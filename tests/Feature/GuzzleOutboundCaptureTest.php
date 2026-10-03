<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Capture\Outbound\GuzzleCaptureMiddleware;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Facades\EagleEye;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\Response;

/** @param list<ResponseInterface|Throwable> $queue */
function guzzleWithMiddleware(array $queue): Client
{
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(app(GuzzleCaptureMiddleware::class));

    return new Client(['handler' => $stack]);
}

it('records a guzzle response through the capture middleware', function (): void {
    $fake = EagleEye::fake();
    $client = guzzleWithMiddleware([new Response(200, [], 'ok')]);

    $response = $client->get('https://api.example.test/orders?page=2');

    expect($response->getStatusCode())->toBe(200);

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && 'api.example.test' === ($entry->payload['host'] ?? null)
        && '/orders' === ($entry->payload['path'] ?? null)
        && ['page'] === ($entry->payload['query_keys'] ?? null)
        && 200 === ($entry->payload['status'] ?? null)
        && is_int($entry->payload['duration_ms'] ?? null)
        && null === ($entry->payload['response_bytes'] ?? null));
});

it('decodes a json error body without reading a length-less success body', function (): void {
    $fake = EagleEye::fake();
    $client = guzzleWithMiddleware([new Response(500, ['Content-Type' => 'application/json'], '{"error":"boom"}')]);

    $client->get('https://api.example.test/orders', ['http_errors' => false]);

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && ['error' => 'boom'] === ($entry->payload['response_body'] ?? null));
});

it('records a guzzle connection failure with duration and rethrows it', function (): void {
    $fake = EagleEye::fake();
    $client = guzzleWithMiddleware([
        new ConnectException('connection refused', new Request('GET', 'https://api.example.test/orders')),
    ]);

    expect(fn (): ResponseInterface => $client->get('https://api.example.test/orders'))->toThrow(ConnectException::class);

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.failed' === $entry->action
        && 'api.example.test' === ($entry->payload['host'] ?? null)
        && is_int($entry->payload['duration_ms'] ?? null)
        && 'connection refused' === ($entry->payload['error'] ?? null));
});

it('skips excluded hosts through the guzzle middleware', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.outbound_http.except_hosts', ['api.example.test']);
    $client = guzzleWithMiddleware([new Response(200)]);

    $client->get('https://api.example.test/orders');

    $fake->assertCount(0);
});

it('skips suppressed guzzle calls', function (): void {
    $fake = EagleEye::fake();
    $client = guzzleWithMiddleware([new Response(200)]);

    app(CaptureSuppression::class)->run(static fn (): ResponseInterface => $client->get('https://api.example.test/orders'));

    $fake->assertCount(0);
});

it('excludes the configured Loki write endpoint host', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.drivers.loki.write.endpoint', 'http://loki-write.internal:3100');
    $client = guzzleWithMiddleware([new Response(200)]);

    $client->get('http://loki-write.internal:3100/loki/api/v1/push');

    $fake->assertCount(0);
});

it('leaves the caller response body readable after capturing an error body', function (): void {
    $fake = EagleEye::fake();
    $client = guzzleWithMiddleware([new Response(500, ['Content-Type' => 'application/json'], '{"error":"boom"}')]);

    $response = $client->get('https://api.example.test/orders', ['http_errors' => false]);

    expect($response->getBody()->tell())->toBe(0)
        ->and($response->getBody()->getContents())->toBe('{"error":"boom"}');

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && ['error' => 'boom'] === ($entry->payload['response_body'] ?? null));
});

it('skips a non-seekable error body without consuming it', function (): void {
    $fake = EagleEye::fake();
    $body = new NoSeekStream(Utils::streamFor('{"error":"boom"}'));
    $response = new Response(500, ['Content-Type' => 'application/json'], $body);
    $client = guzzleWithMiddleware([$response]);

    $actual = $client->get('https://api.example.test/orders', ['http_errors' => false]);

    expect((string) $actual->getBody())->toBe('{"error":"boom"}');

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && ! array_key_exists('response_body', $entry->payload));
});

it('restores a non-zero stream position after reading an error body', function (): void {
    $fake = EagleEye::fake();
    $body = Utils::streamFor('{"error":"boom"}');
    $body->seek(5);
    $client = guzzleWithMiddleware([new Response(500, ['Content-Type' => 'application/json'], $body)]);

    $response = $client->get('https://api.example.test/orders', ['http_errors' => false]);

    expect($response->getBody()->tell())->toBe(5);

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && ['error' => 'boom'] === ($entry->payload['response_body'] ?? null));
});

it('skips an unknown-size error body larger than the limit and reads at most the limit plus one byte', function (): void {
    $fake = EagleEye::fake();
    $bytesRead = 0;
    $inner = Utils::streamFor('{"error":"'.str_repeat('x', 8192).'"}');
    $body = FnStream::decorate($inner, [
        'getSize' => static fn (): ?int => null,
        'read'    => static function (int $length) use ($inner, &$bytesRead): string {
            $chunk = $inner->read($length);
            $bytesRead += mb_strlen($chunk);

            return $chunk;
        },
    ]);
    $client = guzzleWithMiddleware([new Response(500, ['Content-Type' => 'application/json'], $body)]);

    $client->get('https://api.example.test/orders', ['http_errors' => false]);

    $max = (int) config('eagle-eye.capture.outbound_http.error_body_max_bytes', 4096);

    expect($bytesRead)->toBeLessThanOrEqual($max + 1);
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && ! array_key_exists('response_body', $entry->payload));
});
