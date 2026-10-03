<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Middleware;

use Closure;
use Dotswan\EagleEye\Capture\HttpCapture;
use Dotswan\EagleEye\Correlation\TraceManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class CaptureHttpActivityMiddleware
{
    public function __construct(
        private Container $container,
        private ConfigRepository $config,
    ) {
    }

    /** @param Closure(Request): mixed $next */
    public function handle(Request $request, Closure $next): mixed
    {
        $this->capture()->begin($request);

        $response = $next($request);

        if ($response instanceof Response) {
            try {
                $response->headers->set('X-Correlation-Id', $this->container->make(TraceManager::class)->next()->correlationId);
            } catch (Throwable) {
            }
        }

        return $response;
    }

    public function terminate(Request $request, mixed $response): void
    {
        if (! is_object($response) || ! method_exists($response, 'getStatusCode')) {
            return;
        }

        $this->capture()->capture($request, $response);
    }

    private function capture(): HttpCapture
    {
        $via = $this->config->get('eagle-eye.capture.http.via', HttpCapture::class);

        $capture = is_string($via) && class_exists($via)
            ? $this->container->make($via)
            : $this->container->make(HttpCapture::class);

        return $capture instanceof HttpCapture ? $capture : $this->container->make(HttpCapture::class);
    }
}
