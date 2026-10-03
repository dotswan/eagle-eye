<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Drivers\NullDriver;
use Dotswan\EagleEye\Enums\Capability;

it('accepts writes and exposes empty query results', function (): void {
    $driver = new NullDriver();
    $entry = ActivityDraft::make('test')->withTrace(TraceContext::create())->freeze();

    $driver->write($entry);

    expect($driver->writeBatch([$entry]))->toBe([])
        ->and($driver->name())->toBe('null')
        ->and($driver->find($entry->id))->toBeNull()
        ->and($driver->search(new ActivityQuery())->entries)->toBe([])
        ->and($driver->count(new ActivityQuery()))->toBe(0)
        ->and($driver->aggregate(new ActivityQuery(), 'channel'))->toBe([])
        ->and($driver->aggregateTop(new ActivityQuery(), 'channel', 5))->toBe([])
        ->and($driver->tags('a', 5))->toBe([])
        ->and($driver->prune(new DateTimeImmutable()))->toBe(0)
        ->and($driver->forget('user', '42')->state)->toBe('failed')
        ->and($driver->stats())->toBe(['entries' => 0, 'bytes' => 0, 'oldest' => null])
        ->and($driver->supports(Capability::Aggregation))->toBeTrue()
        ->and($driver->supports(Capability::ExactCount))->toBeTrue()
        ->and($driver->supports(Capability::UnboundedTimeRange))->toBeTrue()
        ->and($driver->supports(Capability::SynchronousDeletion))->toBeTrue()
        ->and($driver->supports(Capability::FullTextSearch))->toBeFalse()
        ->and($driver->supports(Capability::LiveTail))->toBeFalse()
        ->and($driver->supports(Capability::JsonFieldQuery))->toBeFalse()
        ->and($driver->supports(Capability::RankedAggregation))->toBeFalse()
        ->and($driver->healthy())->toBeTrue();

    $driver->install();
});
