<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Outbound;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final readonly class GuzzleCaptureMiddleware
{
    public function __construct(private OutboundHttpRecorder $recorder)
    {
    }

    /**
     * @param  callable(RequestInterface, array<string, mixed>): mixed  $handler
     * @return callable(RequestInterface, array<string, mixed>): mixed
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): mixed {
            if (! $this->recorder->captures($request)) {
                return $handler($request, $options);
            }

            $startedAt = microtime(true);

            return $handler($request, $options)->then(
                function ($response) use ($request, $startedAt) {
                    if ($response instanceof ResponseInterface) {
                        $this->recorder->response($request, $response, $startedAt);
                    }

                    return $response;
                },
                function ($reason) use ($request, $startedAt): mixed {
                    $exception = $reason instanceof Throwable
                        ? $reason
                        : new RuntimeException('Guzzle request rejected with a non-throwable reason.');

                    $this->recorder->failed($request, $exception, $startedAt);

                    throw $exception;
                },
            );
        };
    }
}
