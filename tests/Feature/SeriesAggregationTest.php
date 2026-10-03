<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Dotswan\EagleEye\Testing\DriverContractTests;

beforeEach(function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');
});

it('buckets entries exactly on boundaries and zero fills gaps', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $to = new DateTimeImmutable('2026-01-01T00:15:00Z');
    $driver->writeBatch([
        DriverContractTests::at(DriverContractTests::entry(1), $from),
        DriverContractTests::at(DriverContractTests::entry(2), $from->modify('+5 minutes')),
        DriverContractTests::at(DriverContractTests::entry(3), $from->modify('+14 minutes +59 seconds')),
        DriverContractTests::at(DriverContractTests::entry(4), $to),
        DriverContractTests::at(DriverContractTests::entry(5), $from->modify('-1 second')),
    ]);

    $result = $driver->aggregateSeries((new ActivityQuery())->between($from, $to), null, 300);

    expect($result['series']['_total'])->toBe([1, 1, 1])
        ->and($result['buckets'])->toHaveCount(3);
});

it('groups a series by actor and trace with canonical keys', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $to = new DateTimeImmutable('2026-01-01T00:15:00Z');
    $trace = str_repeat('a', 32);
    $driver->writeBatch([
        DriverContractTests::at(DriverContractTests::entry(1, actorId: 'a', traceId: $trace), $from),
        DriverContractTests::at(DriverContractTests::entry(2, actorId: 'a', traceId: $trace), $from->modify('+1 minute')),
        DriverContractTests::at(DriverContractTests::entry(3, actorId: 'b'), $from->modify('+6 minutes')),
    ]);

    $actors = $driver->aggregateSeries((new ActivityQuery())->between($from, $to), 'actor', 300);
    $traces = $driver->aggregateSeries((new ActivityQuery())->between($from, $to), 'trace_id', 300);

    $actorA = json_encode(['user', 'a'], JSON_THROW_ON_ERROR);

    expect(array_sum($actors['series'][$actorA]))->toBe(2)
        ->and(array_sum($actors['series'][json_encode(['user', 'b'], JSON_THROW_ON_ERROR)]))->toBe(1)
        ->and(array_sum($traces['series'][$trace]))->toBe(2);
});

it('requires explicit bounds and rejects unsupported series group keys', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    $driver->aggregateSeries(new ActivityQuery(), null, 300);
})->throws(InvalidArgumentException::class);

it('rejects series grouped by the free time bucket key', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $driver->aggregateSeries(
        (new ActivityQuery())->between(
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:15:00Z'),
        ),
        'logged_at',
        300,
    );
})->throws(InvalidArgumentException::class);
