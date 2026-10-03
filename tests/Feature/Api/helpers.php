<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** @return array{0: int, 1: array<string, mixed>} */
function apiRequest(string $method, string $uri, ?object $viewer = null): array
{
    $kernel = app(Kernel::class);
    $request = Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'application/json']);

    if (null !== $viewer) {
        // The AuthServiceProvider rebind handler replaces the request's user
        // resolver; set the resolver at its source instead.
        app('auth')->resolveUsersUsing(static fn ($guard = null): object => $viewer);
    }

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $decoded = json_decode((string) $response->getContent(), true);

    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
}

function apiEntry(int $sequence, ?string $causationId = null, ?string $traceId = null): ActivityEntry
{
    $at = new DateTimeImmutable('2026-09-08T12:00:'.sprintf('%02d', $sequence).'.123456', new DateTimeZone('UTC'));

    return new ActivityEntry(
        id: sprintf('01J%021d', $sequence),
        schemaVersion: 1,
        loggedAt: $at,
        channel: ActivityChannel::Http,
        action: 'http.request',
        severity: ActivitySeverity::Info,
        title: 'GET /orders answered with 200',
        summary: null,
        actor: new Actor('user', '8821', 'Sara Bakker', '/users/8821'),
        subject: new Subject('order', '77213', 'Order 77213', 'App\\Models\\Order', '/orders/77213'),
        trace: new TraceContext($traceId ?? str_repeat('c', 32), sprintf('01J%021d', 9000), $causationId),
        tags: ['shop', 'gdpr'],
        request: new RequestSnapshot('GET', '/orders', 'orders.index', 200, 42, '10.0.0.1', 'phpunit'),
        payload: ['changes' => ['status' => ['old' => 'draft', 'new' => 'approved']], 'note' => 'snapshot'],
        secrets: [],
        payloadBytes: 128,
        environment: 'testing',
        hostname: 'testbench',
        memoryKb: 1024,
        createdAt: $at,
    );
}
