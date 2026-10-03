<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http;

use Illuminate\Routing\Router;

/**
 * Single registration point for the retrieval API — called by the service
 * provider when `eagle-eye.api.enabled` is set. Kept as one static entry so
 * hosts and tests register the identical group.
 */
final class ApiRoutes
{
    /** @param list<string> $middleware */
    public static function register(Router $router, string $prefix, array $middleware): void
    {
        $router->middleware($middleware)
            ->prefix(mb_trim($prefix, '/'))
            ->group(__DIR__.'/../../routes/api.php');
    }
}
