<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Capture\Concerns\ReadsCaptureConfig;
use Dotswan\EagleEye\Capture\Outbound\OutboundHttpRecorder;
use Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use WeakMap;

final class OutboundHttpCapture extends EventCapture
{
    use ReadsCaptureConfig;

    /** @var WeakMap<RequestInterface, float> */
    private WeakMap $started;

    public function __construct(
        Dispatcher $events,
        Container $container,
        private readonly OutboundHttpRecorder $recorder,
    ) {
        parent::__construct($events, $container);

        $this->started = new WeakMap();
    }

    protected function listeners(): array
    {
        $events = $this->captureEvents('outbound_http', ['response', 'failed']);
        $emitSending = in_array('request', $events, true);
        $listeners = [];

        if ($emitSending || in_array('response', $events, true) || in_array('failed', $events, true)) {
            $listeners[RequestSending::class] = function (RequestSending $event) use ($emitSending): void {
                $request = $event->request->toPsrRequest();
                $this->started[$request] = microtime(true);

                if ($emitSending) {
                    $this->recorder->request($request, 'outbound_http.request', ActivitySeverity::Debug);
                }
            };
        }

        if (in_array('response', $events, true)) {
            $listeners[ResponseReceived::class] = $this->onResponse(...);
        }

        if (in_array('failed', $events, true)) {
            $listeners[ConnectionFailed::class] = $this->onFailed(...);
        }

        return $listeners;
    }

    public function register(): void
    {
        parent::register();

        Http::globalRequestMiddleware(static fn ($request): mixed => self::injectTraceHeaders($request));
    }

    public static function injectTraceHeaders(mixed $request): mixed
    {
        if (app(CaptureSuppression::class)->active()) {
            return $request;
        }

        $trace = app(TraceManager::class)->next();
        $carrier = app(TracePropagatorInterface::class)->inject($trace, []);

        return $request->withHeader('traceparent', $carrier['traceparent'])
            ->withHeader('X-Correlation-Id', $carrier['x-correlation-id']);
    }

    private function onResponse(ResponseReceived $event): void
    {
        $request = $event->request->toPsrRequest();
        $this->recorder->response($request, $event->response->toPsrResponse(), $this->forget($request));
    }

    private function onFailed(ConnectionFailed $event): void
    {
        $request = $event->request->toPsrRequest();
        $this->recorder->failed($request, $event->exception, $this->forget($request));
    }

    private function forget(RequestInterface $request): float
    {
        $startedAt = $this->started[$request] ?? microtime(true);
        unset($this->started[$request]);

        return $startedAt;
    }
}
