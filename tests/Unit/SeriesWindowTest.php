<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Series\SeriesWindow;

it('aligns the window outward to the step and lists every bucket', function (): void {
    $query = (new ActivityQuery())->between(
        new DateTimeImmutable('2026-01-01T00:02:00Z'),
        new DateTimeImmutable('2026-01-01T00:58:00Z'),
    );

    $window = SeriesWindow::fromQuery($query, 300);

    expect($window->from->format(DATE_ATOM))->toBe('2026-01-01T00:00:00+00:00')
        ->and($window->to->format(DATE_ATOM))->toBe('2026-01-01T01:00:00+00:00')
        ->and($window->bucketCount())->toBe(12)
        ->and($window->buckets)->toHaveCount(12)
        ->and($window->bucketIndex(new DateTimeImmutable('2026-01-01T00:05:00Z')))->toBe(1)
        ->and($window->bucketIndex(new DateTimeImmutable('2026-01-01T00:59:59Z')))->toBe(11);
});

it('rejects steps outside the whitelist', function (int $step): void {
    $query = (new ActivityQuery())->between(
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    SeriesWindow::fromQuery($query, $step);
})->with([[60], [301], [0], [-300]])->throws(InvalidArgumentException::class);

it('requires an explicit bounded range', function (): void {
    SeriesWindow::fromQuery(new ActivityQuery(), 300);
})->throws(InvalidArgumentException::class);

it('rejects windows beyond the bucket cap', function (): void {
    $query = (new ActivityQuery())->between(
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-03T00:00:00Z'),
    );

    SeriesWindow::fromQuery($query, 300);
})->throws(LengthException::class);

it('asserts the point budget across groups', function (): void {
    $query = (new ActivityQuery())->between(
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify('+120000 seconds'),
    );
    $window = SeriesWindow::fromQuery($query, 300);
    $groups = [];

    for ($index = 0; $index < 13; $index++) {
        $groups['group-'.$index] = [];
    }

    expect($window->bucketCount())->toBe(400);

    $window->assemble($groups);
})->throws(LengthException::class);

it('zero fills unobserved buckets and keeps the supplied group order', function (): void {
    $query = (new ActivityQuery())->between(
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T00:15:00Z'),
    );
    $window = SeriesWindow::fromQuery($query, 300);

    $result = $window->assemble(['http' => [1 => 2]], ['_total']);

    expect($result['series']['_total'])->toBe([0, 0, 0])
        ->and($result['series']['http'])->toBe([0, 2, 0])
        ->and($result['buckets'])->toHaveCount(3);
});
