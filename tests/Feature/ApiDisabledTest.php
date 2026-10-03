<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

it('does not register api routes unless explicitly enabled', function (): void {
    $kernel = app(Kernel::class);
    $request = Request::create('/api/v1/activity-logs', 'GET');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    expect($response->getStatusCode())->toBe(404);
});
