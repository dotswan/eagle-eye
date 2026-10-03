<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Data\PayloadBudget;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Pipeline\Pipes\EnforceBudget;
use Dotswan\EagleEye\Data\Subject;

#[ActivitySubject('budget_report')]
#[ActivityTags('finance')]
final class TaggedReport
{
    public string $id = '31';
}

it('merges static automatic scoped and attribute tags with dedupe and cap', function (): void {
    config()->set('eagle-eye.tags.static', ['app:my-app']);
    config()->set('eagle-eye.tags.max', 8);
    $fake = EagleEye::fake();

    $subject = app(SubjectResolverInterface::class)->resolve(new TaggedReport());
    $draft = ActivityDraft::make('report.generated', ActivityChannel::Model)->withSubject($subject);
    $draft->request = new RequestSnapshot('GET', 'https://app.test/reports', 'reports.show', 503, 1800);

    eagle_eye_tag(['scoped:job'], function () use ($draft): void {
        eagleEye()->record($draft);
    });

    $fake->assertLogged(static function ($entry): bool {
        $tags = $entry->tags;

        return count($tags) <= 8
            && count($tags) === count(array_unique($tags))
            && in_array('app:my-app', $tags, true)
            && in_array('channel:model', $tags, true)
            && in_array('finance', $tags, true);
    });
});

it('attaches route status and slow automatic tags', function (): void {
    $fake = EagleEye::fake();

    $draft = ActivityDraft::make('request.handled', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('GET', 'https://app.test/reports', 'reports.show', 503, 1800);

    $entry = eagleEye()->record($draft);

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->tags)
        ->toContain('route:reports.show')
        ->toContain('status:5xx')
        ->toContain('slow');
    $fake->assertCount(1);
});

it('truncates oversized payloads without dropping the entry', function (): void {
    $fake = EagleEye::fake();

    $payload = [];

    for ($i = 0; $i < 100; $i++) {
        $payload['field_'.$i] = str_repeat('x', 900);
    }

    $entry = eagleEye()->record('payload.heavy', $payload);

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->payload['__truncated'] ?? false)->toBeTrue()
        ->and($entry->payloadBytes)->toBeLessThanOrEqual(65_536 + 64);

    $fake->assertCount(1);
});

it('enforces payload bounds without invoking serializers introduced by custom pipes', function (): void {
    $object = new class () implements JsonSerializable {
        public int $calls = 0;

        public function jsonSerialize(): mixed
        {
            $this->calls++;

            return str_repeat('x', 10000);
        }
    };
    $draft = ActivityDraft::make('custom.pipe')->withPayload(['unsafe' => $object]);

    (new EnforceBudget(new PayloadBudget(maxBytes: 256)))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry);

    expect($object->calls)->toBe(0)
        ->and(mb_strlen(json_encode($draft->payload, JSON_THROW_ON_ERROR), '8bit'))->toBeLessThanOrEqual(256);
});

it('measures bounded storage envelopes exactly and rejects unsafe direct entries', function (): void {
    $draft = ActivityDraft::make('bounded')->withTrace(TraceContext::create());
    $entry = $draft->freeze();

    expect(EnvelopeBudget::size($entry))->toBe(mb_strlen(json_encode($entry->toStorage(), JSON_THROW_ON_ERROR), '8bit'));

    $draft->summary = str_repeat('x', 10000);

    expect(fn (): int => EnvelopeBudget::size($draft->freeze()))->toThrow(LengthException::class);
});

it('bounds metadata and complete envelopes before integrity sealing', function (): void {
    $draft = ActivityDraft::make('bounded')->withTrace(TraceContext::create());
    $draft->title = str_repeat('x', 100000);
    $draft->summary = str_repeat('y', 100000);
    $draft->actor = new Actor('user', '7', str_repeat('z', 100000));

    $result = (new EnforceBudget(new PayloadBudget()))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry);

    expect($result)->toBe($draft)
        ->and(mb_strlen($draft->title, '8bit'))->toBeLessThanOrEqual(8192)
        ->and(mb_strlen($draft->summary ?? '', '8bit'))->toBeLessThanOrEqual(8192)
        ->and(EnvelopeBudget::size($draft->freeze()))->toBeLessThanOrEqual(131072);
});

it('removes rejected secret references together with their sealed markers', function (): void {
    $draft = ActivityDraft::make('bounded')->withTrace(TraceContext::create());
    $draft->payload = ['password' => ['__secret' => 'payload.password']];
    $draft->secrets = ['payload.password' => new SecretRef('aes', 'fingerprint', str_repeat('x', 9000))];

    (new EnforceBudget(new PayloadBudget()))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry);

    expect($draft->secrets)->toBe([])
        ->and(json_encode($draft->payload, JSON_THROW_ON_ERROR))->not->toContain('__secret')
        ->and(EnvelopeBudget::size($draft->freeze()))->toBeLessThanOrEqual(131072);
});

it('rejects hidden serialization hooks before estimating raw arrays or entries', function (): void {
    $object = new class () extends DateTimeImmutable implements JsonSerializable {
        public int $calls = 0;

        public function jsonSerialize(): mixed
        {
            $this->calls++;

            return str_repeat('x', 100000);
        }
    };
    $row = ActivityDraft::make('bounded')->withTrace(TraceContext::create())->freeze()->toStorage();
    $row['payload'] = ['unsafe' => $object];
    $entry = ActivityEntry::fromStorage($row);

    expect(fn (): int => EnvelopeBudget::sizeOfArray($row))->toThrow(LengthException::class)
        ->and(fn (): int => EnvelopeBudget::size($entry))->toThrow(LengthException::class)
        ->and($object->calls)->toBe(0);
});

it('validates finite wrapper limits and measures bounded encoded lines', function (): void {
    $row = ['line' => str_repeat('x', 100000)];

    expect(EnvelopeBudget::sizeOfArray($row, 1048576, 131072))->toBe(mb_strlen(json_encode($row, JSON_THROW_ON_ERROR), '8bit'))
        ->and(fn (): int => EnvelopeBudget::sizeOfArray($row))->toThrow(LengthException::class)
        ->and(fn (): int => EnvelopeBudget::sizeOfArray([], 0))->toThrow(LengthException::class)
        ->and(fn (): int => EnvelopeBudget::sizeOfArray([], 2097152))->toThrow(LengthException::class)
        ->and(fn (): int => EnvelopeBudget::sizeOfArray([], maxNodes: 200001))->toThrow(LengthException::class);
});

it('rejects an encoded array that exceeds its exact byte ceiling', function (): void {
    expect(fn (): int => EnvelopeBudget::sizeOfArray(['a' => 'b'], 8, 8))
        ->toThrow(LengthException::class);
});

it('rejects oversized identities before normalization', function (): void {
    $draft = ActivityDraft::make(str_repeat('x', 192));

    expect((new EnforceBudget(new PayloadBudget()))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry))->toBeNull();
});

it('rejects an envelope that remains oversized after safe fallback', function (): void {
    $draft = ActivityDraft::make('bounded')->withTrace(TraceContext::create());
    $draft->integrityHash = str_repeat('x', 132000);
    $draft->actor = new Actor('user', '7', 'Sara', 'https://app.test/users/7');
    $draft->subject = new Subject('order', '9', 'Order', TaggedReport::class, 'https://app.test/orders/9');
    $draft->request = new RequestSnapshot('GET', 'https://app.test/orders/9', 'orders.show', 200, 10);

    expect((new EnforceBudget(new PayloadBudget()))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry))->toBeNull()
        ->and($draft->payload)->toBe(['__truncated' => true, '__omitted' => 'envelope-budget'])
        ->and($draft->actor->label)->toBeNull()
        ->and($draft->subject->label)->toBeNull()
        ->and($draft->request->url)->toBeNull();
});

it('bounds secret visits and cumulative sidecar bytes', function (): void {
    $draft = ActivityDraft::make('bounded')->withTrace(TraceContext::create());

    foreach (range(1, 60) as $index) {
        $path = 'payload.secret_'.$index;
        $draft->payload['secret_'.$index] = ['__secret' => $path];
        $draft->secrets[$path] = new SecretRef('aes', 'fingerprint', str_repeat('x', 3000));
    }

    (new EnforceBudget(new PayloadBudget()))->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry);

    expect(count($draft->secrets))->toBeLessThan(10)
        ->and(json_encode($draft->payload, JSON_THROW_ON_ERROR))->toContain('[omitted]');
});
