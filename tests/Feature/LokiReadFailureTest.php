<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\Loki\LokiWriteException;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Illuminate\Support\Facades\Http;

function readFailureClient(): LokiClient
{
    return new LokiClient('http://loki.test');
}

/** @return array<string, mixed> */
function readFailureQuery(LokiClient $client): array
{
    return $client->queryRange('{app="my-app"}', 100, '1', '2', 'backward');
}

it('reports the status and reason when a read is throttled', function (): void {
    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response('too many outstanding requests', 429),
    ]);

    readFailureQuery(readFailureClient());
})->throws(LokiWriteException::class, 'Loki query_range failed with HTTP 429: too many outstanding requests');

it('reports the status and reason when the tenant is rejected', function (): void {
    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response('no org id', 401),
    ]);

    readFailureQuery(readFailureClient());
})->throws(LokiWriteException::class, 'Loki query_range failed with HTTP 401: no org id');

it('names an unreadable success body instead of a bare syntax error', function (): void {
    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response('<html>502 Bad Gateway</html>', 200),
    ]);

    readFailureQuery(readFailureClient());
})->throws(LokiWriteException::class, 'returned HTTP 200 with a body that is not valid JSON: <html>502 Bad Gateway</html>');

it('surfaces the loki error field when the payload is not a success', function (): void {
    Http::fake([
        '*/loki/api/v1/query_range*' => Http::response([
            'status' => 'error',
            'error'  => 'parse error at line 1, col 6: syntax error: unexpected $end',
        ]),
    ]);

    readFailureQuery(readFailureClient());
})->throws(LokiWriteException::class, 'not a success payload: parse error at line 1');
