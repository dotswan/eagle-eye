<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\Writers\StdoutWriter;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Dotswan\EagleEye\Data\ActivityEntry;

it('splits entries into the three tiers with a slug subject type', function (): void {
    $formatter = new LokiLineFormatter(['app' => 'my-app']);
    $entry = DriverContractTests::entry(1, tags: ['gdpr', 'campaign:1']);

    $labels = $formatter->labels($entry);
    $metadata = $formatter->metadata($entry);

    expect($labels)->toHaveKeys(['app', 'env', 'channel', 'severity'])
        ->and($labels['app'])->toBe('my-app')
        ->and($metadata['subject_type'])->toBe('campaign')
        ->and($metadata['tags'])->toBe('gdpr,campaign:1')
        ->and($metadata['actor_id'])->toBe('8821')
        ->and($metadata['id'])->toBe($entry->id)
        ->and(array_keys($metadata))->each->toBeIn(LokiLineFormatter::METADATA_KEYS);

    $body = json_decode($formatter->body($entry), true);
    expect($body['subject_class'] ?? null)->toBe(DriverContractTests::class);
});

it('groups entries of the same label set into one stream', function (): void {
    $formatter = new LokiLineFormatter(['app' => 'my-app']);
    $streams = $formatter->streams([
        DriverContractTests::entry(1),
        DriverContractTests::entry(2),
    ]);

    expect($streams)->toHaveCount(1)
        ->and($streams[0]->values)->toHaveCount(2)
        ->and($streams[0]->labels['channel'])->toBe('custom')
        ->and($streams[0]->values[0][0])->toMatch('/^\d{19}$/');
});

it('writes lossless marked stdout lines with routing fields at the top level', function (): void {
    $target = tempnam(sys_get_temp_dir(), 'loki-stdout-');
    $formatter = new LokiLineFormatter(['app' => 'my-app']);
    $writer = new StdoutWriter($target);
    $entry = DriverContractTests::entry(1, tags: ['gdpr', 'campaign:1', 'a,b']);

    $writer->write($formatter->streams([$entry]));

    $contents = (string) file_get_contents($target);
    $lines = file($target, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    expect($lines)->toHaveCount(1);

    $line = json_decode($lines[0], true);
    expect($line)
        ->toMatchArray([
            'eagle_eye' => true,
            'app'       => 'my-app',
            'env'       => $line['env'],
            'channel'   => 'custom',
            'severity'  => 'info',
            'tags'      => ['gdpr', 'campaign:1', 'a,b'],
        ])
        ->toHaveKey('logged_at')
        ->toHaveKey('title', 'Activity recorded')
        ->toHaveKey('trace_id');

    expect($contents)
        ->toStartWith('{"eagle_eye":true,')
        ->toEndWith("\n")
        ->and($line['status_code'] ?? null)->toBeInt()->toBe(200)
        ->and($line['logged_at'] ?? null)->toBe($entry->toStorage()['logged_at'])
        ->and(ActivityEntry::fromStorage($line)->toStorage())->toBe($entry->toStorage());
    expect(mb_substr_count($contents, "\n"))->toBe(1);

    unlink($target);
});

it('round trips Loki timestamps at microsecond precision', function (string $timestamp): void {
    $formatter = new LokiLineFormatter([]);
    $at = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));

    expect($formatter->fromNanoseconds($formatter->nanoseconds($at))->format('U.u'))
        ->toBe($at->format('U.u'))
        ->and($formatter->fromNanoseconds($formatter->nanoseconds($at))->getTimezone()->getName())
        ->toBe('UTC');
})->with([
    'leading microsecond zero' => '2026-09-15 07:20:26.000001',
    'before epoch'             => '1969-12-31 23:59:59.500001',
]);
