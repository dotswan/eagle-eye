<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface;
use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\RequestPart;
use Dotswan\EagleEye\Filter\FilterChain;
use Dotswan\EagleEye\Filter\Gate;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;
use Dotswan\EagleEye\Redaction\Strategies\PartialMaskStrategy;
use JsonException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HttpCapture
{
    public const string START_ATTRIBUTE = 'eagle_eye.started_at';

    private ?CompiledFilterMatcher $bodyMatcher = null;

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
    ) {
    }

    public function begin(Request $request): void
    {
        $request->attributes->set(self::START_ATTRIBUTE, microtime(true));
        $this->container->make(QuerySummaryCollector::class)->reset();

        try {
            $context = $this->container->make(TracePropagatorInterface::class)->extract([
                'traceparent'      => (string) $request->headers->get('traceparent', ''),
                'x-correlation-id' => (string) $request->headers->get('x-correlation-id', ''),
            ]);
            $this->container->make(TraceManager::class)->adopt($context);
        } catch (Throwable) {
        }
    }

    public function capture(Request $request, object $response): void
    {
        if (! $response instanceof Response) {
            return;
        }

        try {
            $this->record($request, $response);
        } catch (Throwable $exception) {
            try {
                app(CaptureSuppression::class)->run(static fn () => report($exception));
            } catch (Throwable) {
            }
        }
    }

    private function record(Request $request, Response $response): void
    {
        $eagleEye = $this->container->make(EagleEyeInterface::class);
        $environments = $this->config->get('eagle-eye.environments', []);

        if (app(CaptureSuppression::class)->active() || $eagleEye->isPaused()
            || ! $this->config->get('eagle-eye.enabled', true)
            || ! $this->config->get('eagle-eye.capture.http.enabled', true)
            || ! is_array($environments)
            || (! in_array('*', $environments, true) && ! in_array(app()->environment(), $environments, true))) {
            return;
        }

        $started = $request->attributes->get(self::START_ATTRIBUTE, microtime(true));
        $durationMs = max(0, (int) round((microtime(true) - (float) $started) * 1000));
        $status = $response->getStatusCode();
        $severity = match (true) {
            $status >= 500 => ActivitySeverity::Error,
            $status >= 400 => ActivitySeverity::Warning,
            default        => ActivitySeverity::Info,
        };

        $routeName = $request->route()?->getName();
        $bodyRoute = $this->bodyRouteMatched($request);

        $draft = ActivityDraft::make('http.request', ActivityChannel::Http, $severity);
        $draft->request = new RequestSnapshot(
            method: $request->getMethod(),
            url: mb_strcut($request->getSchemeAndHttpHost(), 0, 1024, 'UTF-8').mb_strcut($request->getRequestUri(), 0, 7168, 'UTF-8'),
            route: $routeName ?? $request->path(),
            statusCode: $status,
            durationMs: $durationMs,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
        if (! $this->container->make(Gate::class)->allows($draft)) {
            return;
        }

        $filters = $this->container->make(FilterChain::class)->effectiveFilters($draft);
        $parts = $filters['request_parts'] ?? [];
        $parts = is_array($parts) ? $parts : [];
        $draft->payload = [
            'request'  => $this->requestParts($request, $parts, $bodyRoute),
            'response' => $this->partAllowed(RequestPart::ResponseBody->value, $parts)
                ? $this->responsePayload($response, $status, $bodyRoute)
                : ['status' => $status],
        ];

        $eagleEye->record($draft);
    }

    private function bodyRouteMatched(Request $request): bool
    {
        $matcher = $this->bodyMatcher();

        if ($matcher->isEmpty()) {
            return false;
        }

        $routeName = $request->route()?->getName();

        return (is_string($routeName) && $matcher->matches($routeName)) || $matcher->matches($request->path());
    }

    private function bodyMatcher(): CompiledFilterMatcher
    {
        if (! $this->bodyMatcher instanceof CompiledFilterMatcher) {
            $routes = $this->config->get('eagle-eye.capture.http.body_routes', []);
            $routes = is_array($routes) ? array_values(array_filter($routes, is_string(...))) : [];
            $this->bodyMatcher = new CompiledFilterMatcher($routes);
        }

        return $this->bodyMatcher;
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function requestParts(Request $request, array $projection, bool $bodyRoute): array
    {
        $parts = $this->config->get('eagle-eye.capture.http.parts', []);
        $parts = is_array($parts) ? array_filter($parts, is_string(...)) : [];

        if ($bodyRoute) {
            $parts[] = RequestPart::Body->value;
            $parts[] = RequestPart::Files->value;
        }

        $captured = [];

        foreach (array_unique($parts) as $part) {
            if (! $this->partAllowed($part, $projection)) {
                continue;
            }

            $captured[$part] = match (RequestPart::tryFrom($part)) {
                RequestPart::Query       => $request->query->all(),
                RequestPart::Body        => $this->requestBody($request),
                RequestPart::Files       => $this->files($request),
                RequestPart::Headers     => $request->headers->all(),
                RequestPart::Cookies     => $request->cookies->all(),
                RequestPart::Session     => $request->hasSession() ? $request->session()->all() : [],
                RequestPart::RouteParams => is_object($request->route()) ? (array) $request->route()->parameters() : [],
                default                  => null,
            };

            if (null === $captured[$part] || [] === $captured[$part]) {
                unset($captured[$part]);
            }
        }

        return $captured;
    }

    /** @param array<string, mixed> $projection */
    private function partAllowed(string $part, array $projection): bool
    {
        $only = $projection['only'] ?? [];
        $except = $projection['except'] ?? [];

        return (! is_array($except) || ! in_array($part, $except, true))
            && (! is_array($only) || [] === $only || in_array($part, $only, true));
    }

    /** @return array<string, mixed> */
    private function requestBody(Request $request): array
    {
        $maxBytes = max(1024, min((int) $this->config->get('eagle-eye.capture.http.body_max_bytes', 16_384), 16_384));

        if (! $request->isJson()) {
            $data = $request->request->all();
            $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);

            return is_string($encoded) && mb_strlen($encoded, '8bit') > $maxBytes
                ? ['__omitted' => 'body_too_large']
                : $data;
        }

        if ((int) $request->headers->get('content-length', '0') > $maxBytes) {
            return ['__omitted' => 'body_too_large'];
        }

        $stream = $request->getContent(true);

        if (! is_resource($stream)) {
            return [];
        }

        $content = stream_get_contents($stream, $maxBytes + 1);
        rewind($stream);

        if (! is_string($content) || mb_strlen($content, '8bit') > $maxBytes) {
            return ['__omitted' => 'body_too_large'];
        }

        return $request->json()->all();
    }

    /** @return list<array{field: string, name: string, size: int|null, mime: string|null}> */
    private function files(Request $request): array
    {
        $max = max(0, min((int) $this->config->get('eagle-eye.capture.http.files_max', 20), 100));

        if (0 === $max) {
            return [];
        }

        $strategy = $this->container->make(PartialMaskStrategy::class);
        $captured = [];
        $walk = function (mixed $value, string $field) use (&$walk, &$captured, $max, $strategy): void {
            if (count($captured) >= $max) {
                return;
            }

            if ($value instanceof UploadedFile) {
                $name = $value->getClientOriginalName();
                $masked = $strategy->apply($name, 'http.file');
                $valid = $value->isValid();
                $size = $valid ? $value->getSize() : null;
                $mime = $valid ? $value->getClientMimeType() : null;
                $file = [
                    'field' => $field,
                    'name'  => is_string($masked) ? $masked : '***',
                    'size'  => is_int($size) ? $size : null,
                    'mime'  => is_string($mime) ? $mime : null,
                ];

                if (! $valid) {
                    $file['error'] = $value->getError();
                }

                $captured[] = $file;

                return;
            }

            if (is_array($value)) {
                foreach ($value as $key => $nested) {
                    $walk($nested, $field.'.'.$key);
                }
            }
        };

        foreach ($request->files->all() as $key => $value) {
            $walk($value, (string) $key);
        }

        return $captured;
    }

    /** @return array<string, mixed> */
    private function responsePayload(Response $response, int $status, bool $bodyRoute): array
    {
        $mode = (string) $this->config->get('eagle-eye.capture.http.response.body', 'errors_only');
        $include = $bodyRoute || 'always' === $mode || ('errors_only' === $mode && $status >= 400);

        if (! $include) {
            return ['status' => $status];
        }

        $maxBytes = max(64, min((int) $this->config->get('eagle-eye.capture.http.response.max_bytes', 16_384), 16_384));
        $contentType = mb_strtolower((string) $response->headers->get('Content-Type', ''));

        if (! str_contains($contentType, '/json') && ! str_contains($contentType, '+json')) {
            return ['status' => $status];
        }

        $content = $response->getContent();

        if (! is_string($content) || mb_strlen($content, '8bit') > $maxBytes) {
            return ['status' => $status];
        }

        try {
            $body = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['status' => $status];
        }

        return is_array($body) ? ['status' => $status, 'body' => $body] : ['status' => $status];
    }
}
