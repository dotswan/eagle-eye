<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\TraceTree;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\ActivityChannel;

it('round trips the canonical storage envelope without loss', function (): void {
    $draft = ActivityDraft::make('updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '8821', 'User #8821', '/admin/users/8821'))
        ->withSubject(new Subject('campaign_request', '77213', 'Campaign Request #77213', 'Domain\\CampaignRequest', '/admin/campaigns/77213'))
        ->withPayload(['changes' => ['status' => ['old' => 'pending', 'new' => 'approved']]])
        ->withTrace(TraceContext::create())
        ->withTags(['campaign:4821', 'gdpr']);

    $draft->title = 'User #8821 updated Campaign Request #77213';
    $draft->summary = 'Status: Pending to Approved';
    $draft->request = new RequestSnapshot('POST', 'https://example.test/campaigns/77213/approve', 'campaigns.approve', 200, 412, '145.23.0.0', 'Browser');
    $draft->secrets = [
        'payload.iban' => new SecretRef('laravel:AES-256-CBC', '9f2c1ab7', 'ciphertext', 'blind-index'),
    ];
    $draft->environment = 'testing';
    $draft->hostname = 'test-host';
    $draft->memoryKb = 2048;

    $entry = $draft->freeze();
    $restored = $entry::fromStorage($entry->toStorage());

    expect($restored)->toEqual($entry);
});

it('assembles cyclic traces within finite node and depth limits', function (): void {
    $first = DriverContractTests::entry(301)->toStorage();
    $second = DriverContractTests::entry(302)->toStorage();
    $first['causation_id'] = $second['id'];
    $second['causation_id'] = $first['id'];
    $firstEntry = ActivityEntry::fromStorage($first);
    $secondEntry = ActivityEntry::fromStorage($second);
    $roots = TraceTree::assemble([$firstEntry, $secondEntry]);

    expect($roots)->not->toBeEmpty()
        ->and(array_sum(array_map(static fn (TraceTree $tree): int => count($tree->flatten()), $roots)))->toBeLessThanOrEqual(3);

    $timeline = new TraceTree($firstEntry, [new TraceTree($secondEntry)]);
    expect($timeline->toArray()['children'])->toHaveCount(1)
        ->and($timeline->toTimeline())->toHaveCount(2);
});

it('rejects storage rows without a correlation identifier', function (): void {
    $entry = ActivityDraft::make('created')->withTrace(TraceContext::create())->freeze();
    $stored = $entry->toStorage();
    unset($stored['correlation_id']);

    ActivityEntry::fromStorage($stored);
})->throws(UnexpectedValueException::class, 'Required activity field [correlation_id] is missing.');
