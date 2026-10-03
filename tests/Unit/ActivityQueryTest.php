<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\SortOrder;

it('builds backend neutral activity criteria', function (): void {
    $query = (new ActivityQuery())
        ->channels(ActivityChannel::Http, ActivityChannel::Model)
        ->forActor('user', 8821)
        ->forSubject('campaign_request', 77213)
        ->withAnyTag(['gdpr', 'gdpr', 'campaign:4821'])
        ->whereStatus('>=', 500)
        ->search('payment declined')
        ->orderByTime('asc')
        ->limit(100);

    expect($query->channels)->toBe([ActivityChannel::Http, ActivityChannel::Model])
        ->and($query->actorId)->toBe('8821')
        ->and($query->subjectId)->toBe('77213')
        ->and($query->tags)->toBe(['gdpr', 'campaign:4821'])
        ->and($query->sortOrder)->toBe(SortOrder::Ascending)
        ->and($query->limit)->toBe(100);
});

it('round trips opaque cursors', function (): void {
    $cursor = new Cursor(
        new DateTimeImmutable('2026-09-01T10:14:02.140000Z'),
        '01JBQ4Z8W9K3M7N2P5R8T1V6X0',
    );

    expect(Cursor::decode($cursor->encode()))->toEqual($cursor);
});

it('uses cursor direction for stable pagination', function (): void {
    $cursor = new Cursor(
        new DateTimeImmutable('2026-09-01T10:14:02.140000Z'),
        '01JBQ4Z8W9K3M7N2P5R8T1V6X0',
        SortOrder::Ascending,
    );
    $query = (new ActivityQuery())->cursor($cursor);

    expect($query->cursor)->toBe($cursor)
        ->and($query->sortOrder)->toBe(SortOrder::Ascending);
});

it('exposes every activity channel value', function (): void {
    expect(ActivityChannel::values())->toBe(array_column(ActivityChannel::cases(), 'value'));
});

it('rejects malformed opaque cursors', function (string $cursor): void {
    expect(fn (): Cursor => Cursor::decode($cursor))->toThrow(InvalidArgumentException::class);
})->with([
    'invalid base64'  => ['%%%'],
    'invalid payload' => [base64_encode('{}')],
]);

it('accepts and deduplicates valid action filters', function (): void {
    $query = (new ActivityQuery())->actions('model.updated', 'user.login', 'model.updated');

    expect($query->actions)->toBe(['model.updated', 'user.login']);
});

it('rejects invalid action filters', function (string $action): void {
    (new ActivityQuery())->actions($action);
})->with([
    'empty'    => [''],
    'space'    => ['model updated'],
    'wildcard' => ['model.*'],
    'newline'  => ["model\nupdated"],
    'too long' => [str_repeat('a', 192)],
])->throws(InvalidArgumentException::class);

it('rejects more than twenty action filters', function (): void {
    $actions = array_map(static fn (int $index): string => 'action.'.$index, range(1, 21));

    (new ActivityQuery())->actions(...$actions);
})->throws(InvalidArgumentException::class);
