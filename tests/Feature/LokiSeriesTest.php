<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\Loki\LokiDriver;
use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\LokiQueryTranslator;
use Dotswan\EagleEye\Drivers\Loki\LokiWriteException;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Dotswan\EagleEye\Drivers\Loki\Writers\LokiWriterInterface;
use Illuminate\Support\Facades\Http;

function seriesLokiDriver(): LokiDriver
{
    return new LokiDriver(
        new class () implements LokiWriterInterface {
            public function write(array $streams): void
            {
            }

            public function healthy(): bool
            {
                return true;
            }
        },
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );
}

it('maps a loki metric matrix onto aligned series buckets', function (): void {
    $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $to = new DateTimeImmutable('2026-01-01T00:10:00Z');
    $first = (new DateTimeImmutable('2026-01-01T00:05:00Z'))->getTimestamp();
    $second = (new DateTimeImmutable('2026-01-01T00:10:00Z'))->getTimestamp();

    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    ['metric' => ['severity' => 'error'], 'values' => [[$first, '3'], [$second, '1']]],
                    ['metric' => ['severity' => 'info'], 'values' => [[$first, '2']]],
                ],
            ],
        ]),
    ]);

    $result = seriesLokiDriver()->aggregateSeries(
        (new ActivityQuery())->between($from, $to),
        'severity',
        300,
    );

    expect($result['buckets'])->toHaveCount(2)
        ->and($result['series']['error'])->toBe([3, 1])
        ->and($result['series']['info'])->toBe([2, 0]);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return str_contains((string) $request->url(), '/loki/api/v1/query_range')
            && 'sum by (severity) (count_over_time({app="my-app"}[300s]))' === $data['query']
            && 300 === $data['step'];
    });
});

it('rejects non matrix loki metric responses', function (): void {
    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data'   => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    seriesLokiDriver()->aggregateSeries(
        (new ActivityQuery())->between(
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:10:00Z'),
        ),
        null,
        300,
    );
})->throws(LokiWriteException::class);

it('requires explicit bounds for loki series aggregation', function (): void {
    seriesLokiDriver()->aggregateSeries(new ActivityQuery(), null, 300);
})->throws(InvalidArgumentException::class);
