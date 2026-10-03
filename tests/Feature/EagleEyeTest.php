<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Facades\EagleEye;

it('records an activity end to end through the null driver', function (): void {
    $entry = app(EagleEyeInterface::class)->record(
        'campaign.approved',
        ['campaign_id' => 42],
        ActivityChannel::Custom,
    );

    expect($entry)->toBeInstanceOf(ActivityEntry::class);

    if (! $entry instanceof ActivityEntry) {
        throw new RuntimeException('Expected an activity entry.');
    }

    expect($entry->action)->toBe('campaign.approved')
        ->and($entry->payload['campaign_id'])->toBe(42)
        ->and($entry->payload['title_source']['key'] ?? null)->not->toBeNull()
        ->and($entry->environment)->toBe('testing');
});

it('provides a fake with assertions and query support', function (): void {
    $repository = app(ActivityRepositoryInterface::class);
    $fake = EagleEye::fake();

    EagleEye::record('campaign.created', ['campaign_id' => 10]);
    EagleEye::record('campaign.updated', ['campaign_id' => 10]);

    $fake->assertCount(2);
    $fake->assertLogged(fn (ActivityEntry $entry): bool => 'campaign.updated' === $entry->action);

    expect(EagleEye::query()->search('campaign.created')->get()->entries)->toHaveCount(1)
        ->and($repository->search((new ActivityQuery())->search('campaign.updated'))->entries)->toHaveCount(1);
});

it('keeps fluent trails immutable and exposes cursor pagination', function (): void {
    EagleEye::fake();
    EagleEye::record('first');
    EagleEye::record('second');
    EagleEye::record('third');

    $base = EagleEye::query();
    $actorTrail = $base->forActor('user', 42);
    $subjectTrail = $base->forSubject('campaign', 91);
    $firstPage = $base->limit(2)->get();
    $secondPage = $base->cursor($firstPage->nextCursor)->limit(2)->get();
    $now = new DateTimeImmutable();
    $configured = $base
        ->channels(ActivityChannel::Custom)
        ->between($now->modify('-2 days'), $now)
        ->forTrace(str_repeat('a', 32))
        ->forSubjectClass(ActivityEntry::class)
        ->withAnyTag(['audit'])
        ->search('created')
        ->whereStatus('>=', 200)
        ->orderByTime(SortOrder::Ascending)
        ->last('1 day', $now)
        ->limit(3);

    expect($base->criteria->actorId)->toBeNull()
        ->and($actorTrail->criteria->actorId)->toBe('42')
        ->and($actorTrail->criteria->subjectId)->toBeNull()
        ->and($subjectTrail->criteria->subjectId)->toBe('91')
        ->and($firstPage->entries)->toHaveCount(2)
        ->and($firstPage->hasMore)->toBeTrue()
        ->and($firstPage->nextCursor)->not->toBeNull()
        ->and($secondPage->entries)->toHaveCount(1)
        ->and($secondPage->hasMore)->toBeFalse()
        ->and($configured->criteria->channels)->toBe([ActivityChannel::Custom])
        ->and($configured->criteria->traceId)->toBe(str_repeat('a', 32))
        ->and($configured->criteria->subjectClass)->toBe(ActivityEntry::class)
        ->and($configured->criteria->tags)->toBe(['audit'])
        ->and($configured->criteria->search)->toBe('created')
        ->and($configured->criteria->status?->operator)->toBe('>=')
        ->and($configured->criteria->sortOrder)->toBe(SortOrder::Ascending)
        ->and($configured->criteria->limit)->toBe(3)
        ->and($configured->count())->toBe(0)
        ->and($configured->aggregate('channel'))->toBe([]);
});

it('bounds fake storage capacity', function (): void {
    $fake = EagleEye::fake(2);
    EagleEye::record('first');
    EagleEye::record('second');
    EagleEye::record('third');

    $fake->assertCount(2);
    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'first' === $entry->action);
});

it('supports scoped tags and silent blocks', function (): void {
    $fake = EagleEye::fake();

    eagle_eye_tag(['campaign:42'], function (): void {
        eagleEye()->record('campaign.updated');
    });

    eagle_eye_silent(function (): void {
        eagleEye()->record('campaign.hidden');
    });

    $fake->assertCount(1);
    $fake->assertLogged(fn (ActivityEntry $entry): bool => in_array('campaign:42', $entry->tags, true));
    $fake->assertNotLogged(fn (ActivityEntry $entry): bool => 'campaign.hidden' === $entry->action);
});
