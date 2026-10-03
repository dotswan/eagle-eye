<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\TraceContext;

it('creates W3C compatible trace identifiers', function (): void {
    $context = TraceContext::create('cause', 'parent');

    expect($context->traceId)->toMatch('/^[a-f0-9]{32}$/')
        ->and($context->correlationId)->toHaveLength(26)
        ->and($context->traceId())->toBe($context->traceId)
        ->and($context->correlationId())->toBe($context->correlationId)
        ->and($context->causationId())->toBe('cause')
        ->and($context->parentSpanId())->toBe('parent')
        ->and($context->toArray())->toBe([
            'trace_id'       => $context->traceId,
            'correlation_id' => $context->correlationId,
            'causation_id'   => 'cause',
            'parent_span_id' => 'parent',
        ]);
});

it('rejects an all-zero trace identifier', function (): void {
    new TraceContext(str_repeat('0', 32), '01JBQ4Z8W9K3M7N2P5R8T1V6X0');
})->throws(InvalidArgumentException::class);

it('rejects an empty correlation identifier', function (): void {
    new TraceContext(str_repeat('a', 32), '');
})->throws(InvalidArgumentException::class, 'Correlation ID cannot be empty');
