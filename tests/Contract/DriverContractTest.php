<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\MemoryDriver;
use Dotswan\EagleEye\Drivers\NullDriver;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Testing\DriverContractTests;

DriverContractTests::define('memory', static fn (): MemoryDriver => new MemoryDriver());
DriverContractTests::defineSeries('memory', static fn (): MemoryDriver => new MemoryDriver());
DriverContractTests::defineNull('null', static fn (): NullDriver => new NullDriver());

it('exercises bounded memory-driver filtering aggregation maintenance and statistics', function (): void {
    $driver = new MemoryDriver(3);
    $first = DriverContractTests::entry(1, actorId: 'alpha', subjectType: 'campaign', subjectId: '42', tags: ['billing'], channel: ActivityChannel::Http, severity: ActivitySeverity::Warning);
    $secondStorage = DriverContractTests::entry(2, actorId: 'beta', subjectType: 'invoice', subjectId: '84', tags: ['audit'], channel: ActivityChannel::Queue, severity: ActivitySeverity::Error)->toStorage();
    $secondStorage['subject_class'] = 'Domain\\Invoice';
    $second = ActivityEntry::fromStorage($secondStorage);
    $anonymousStorage = DriverContractTests::entry(3)->toStorage();
    foreach (['actor_type', 'actor_id', 'actor_label', 'actor_url', 'subject_type', 'subject_id', 'subject_label', 'subject_class', 'subject_url', 'route', 'status_code'] as $key) {
        $anonymousStorage[$key] = null;
    }
    $anonymous = ActivityEntry::fromStorage($anonymousStorage);
    $driver->writeBatch([$first, $second, $anonymous]);

    expect($driver->find('missing'))->toBeNull()
        ->and($driver->search((new ActivityQuery())->channels(ActivityChannel::Model))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->severities(ActivitySeverity::Critical))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->between($first->loggedAt->modify('+1 day'), $first->loggedAt->modify('+2 days')))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->forActor('user', 'missing'))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->forSubject('campaign', 'missing'))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->forSubjectClass('Domain\\Invoice')->forSubject('invoice', '84'))->entries)->toHaveCount(1)
        ->and($driver->search((new ActivityQuery())->forTrace(str_repeat('f', 32)))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->withAnyTag(['missing']))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->search('absent phrase'))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->whereStatus('=', 200))->entries)->toHaveCount(2)
        ->and($driver->search((new ActivityQuery())->whereStatus('!=', 200))->entries)->toBe([])
        ->and($driver->search((new ActivityQuery())->whereStatus('>', 199))->entries)->toHaveCount(2)
        ->and($driver->search((new ActivityQuery())->whereStatus('>=', 200))->entries)->toHaveCount(2)
        ->and($driver->search((new ActivityQuery())->whereStatus('<', 201))->entries)->toHaveCount(2)
        ->and($driver->search((new ActivityQuery())->whereStatus('<=', 200))->entries)->toHaveCount(2);

    foreach (['channel', 'action', 'severity', 'actor_type', 'actor', 'subject_type', 'route', 'status_code'] as $group) {
        expect($driver->aggregate(new ActivityQuery(), $group))->not->toBeEmpty();
    }
    foreach (['hour', 'month', 'day'] as $interval) {
        expect($driver->aggregate(new ActivityQuery(), 'logged_at', $interval))->not->toBeEmpty();
    }

    expect($driver->aggregateTop(new ActivityQuery(), 'actor', 2))->toHaveCount(2)
        ->and($driver->tags('bill', 1))->toBe(['billing'])
        ->and($driver->stats()['entries'])->toBe(3)
        ->and($driver->stats()['oldest'])->toEqual($first->loggedAt)
        ->and($driver->supports(Capability::JsonFieldQuery))->toBeFalse();

    expect(fn (): array => $driver->aggregate(new ActivityQuery(), 'unknown'))->toThrow(InvalidArgumentException::class);

    $maintenance = new MemoryDriver();
    $maintenance->writeBatch([$first, $second]);
    expect($maintenance->prune($second->loggedAt, (new ActivityQuery())->forActor('user', 'alpha')))->toBe(1)
        ->and($maintenance->forget('Domain\\Invoice', '84')->removed)->toBe(1)
        ->and($maintenance->entries())->toBe([]);
});

it('rejects invalid memory-driver capacities', function (int $capacity): void {
    new MemoryDriver($capacity);
})->with([[0], [100_001]])->throws(InvalidArgumentException::class);
