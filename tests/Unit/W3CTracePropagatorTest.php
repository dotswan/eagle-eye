<?php

declare(strict_types=1);

use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Correlation\W3CTracePropagator;
use Dotswan\EagleEye\Data\TraceContext;

it('round trips a W3C traceparent header', function (): void {
    $propagator = new W3CTracePropagator();
    $context = new TraceContext('4bf92f3577b34da6a3ce929d0e0e4736', '01JBQ4Z8W9K3M7N2P5R8T1V6X0', null, '00f067aa0ba902b7');

    $carrier = $propagator->inject($context, []);
    $extracted = $propagator->extract($carrier);

    expect($carrier['traceparent'])->toMatch('/^00-4bf92f3577b34da6a3ce929d0e0e4736-[a-f0-9]{16}-01$/')
        ->and($carrier['x-correlation-id'])->toBe('01JBQ4Z8W9K3M7N2P5R8T1V6X0')
        ->and($extracted->traceId)->toBe($context->traceId)
        ->and($extracted->correlationId)->toBe($context->correlationId)
        ->and($extracted->parentSpanId)->toBe('00f067aa0ba902b7');
});

it('mints a fresh context for missing or invalid traceparent headers', function (array $carrier): void {
    $extracted = (new W3CTracePropagator())->extract($carrier);

    expect($extracted->traceId)->toMatch('/^[a-f0-9]{32}$/')
        ->and($extracted->correlationId)->not->toBe('');
})->with([
    'empty'                 => [[]],
    'malformed version'     => [['traceparent' => '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']],
    'malformed trace id'    => [['traceparent' => '00-xyz-00f067aa0ba902b7-01']],
    'preserves correlation' => [['X-Correlation-Id' => 'req-123']],
]);

it('adopts an upstream context and links causal entries', function (): void {
    $manager = new TraceManager();
    $upstream = new TraceContext('0af7651916cd43dd8448eb211c80319c', 'req-9', null, 'b7ad6b7169203331');

    $manager->adopt($upstream);

    $first = $manager->next();
    $manager->recorded('01JENTRY000000000000000001');
    $second = $manager->next();

    expect($first->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($first->causationId)->toBeNull()
        ->and($second->traceId)->toBe($first->traceId)
        ->and($second->correlationId)->toBe($first->correlationId)
        ->and($second->causationId)->toBe('01JENTRY000000000000000001');
});

it('replaces unsafe inbound correlation ids with bounded tokens', function (mixed $value): void {
    $context = (new W3CTracePropagator())->extract(['X-Correlation-Id' => $value]);

    expect($context->correlationId)->not->toBe($value)
        ->and($context->correlationId)->toMatch('/\A[A-Za-z0-9._:-]{1,128}\z/');
})->with([
    'empty'            => [''],
    'spaces'           => ['request id'],
    'oversized'        => [str_repeat('a', 129)],
    'line break'       => ["req-123\n"],
    'header injection' => ["req-123\r\nInjected: value"],
    'null byte'        => ["req\0id"],
    'unicode'          => ['شناسه'],
    'array'            => [['req-123']],
    'integer'          => [123],
]);

it('preserves valid bounded inbound correlation ids', function (string $value): void {
    $context = (new W3CTracePropagator())->extract(['x-CoRrElAtIoN-Id' => $value]);

    expect($context->correlationId)->toBe($value);
})->with(['req-123', 'Request_123.test:one', str_repeat('a', 128)]);

it('rejects invalid inbound traceparent ids without throwing', function (mixed $value): void {
    $context = (new W3CTracePropagator())->extract([
        'Traceparent'      => $value,
        'X-Correlation-Id' => 'req-123',
    ]);

    expect($context->traceId)->toMatch('/\A[a-f0-9]{32}\z/');
    expect($context->traceId)->not->toBe(str_repeat('0', 32));
    expect($context->parentSpanId)->toBeNull()
        ->and($context->correlationId)->toBe('req-123');
})->with([
    'zero trace' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'zero span'  => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'line break' => ["00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\n"],
    'oversized'  => [str_repeat('a', 4096)],
    'array'      => [['invalid']],
]);

it('clears stale causation when adopting a new context', function (): void {
    $manager = new TraceManager();
    $manager->recorded('previous-scope-entry');
    $upstream = new TraceContext('0af7651916cd43dd8448eb211c80319c', 'req-9', 'upstream-entry', 'b7ad6b7169203331');

    $manager->adopt($upstream);

    expect($manager->next())->toEqual($upstream);
});

it('bounds case insensitive carrier header searches', function (): void {
    $carrier = [];

    for ($index = 0; $index < 127; $index++) {
        $carrier['unrelated-'.$index] = 'ignored';
    }

    $propagator = new W3CTracePropagator();
    $withinLimit = $propagator->extract([...$carrier, 'X-Correlation-Id' => 'req-123']);
    $overLimit = $propagator->extract([...$carrier, 'another-unrelated' => 'ignored', 'X-Correlation-Id' => 'req-123']);

    expect($withinLimit->correlationId)->toBe('req-123')
        ->and($overLimit->correlationId)->not->toBe('req-123');
});
