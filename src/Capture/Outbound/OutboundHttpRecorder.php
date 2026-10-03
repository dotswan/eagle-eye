<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Outbound;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final readonly class OutboundHttpRecorder
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function response(RequestInterface $request, ResponseInterface $response, float $startedAt): void
    {
        $this->write($request, function () use ($request, $response, $startedAt): ActivityDraft {
            $status = $response->getStatusCode();
            $payload = $this->requestPayload($request);
            $payload['status'] = $status;
            $payload['duration_ms'] = $this->duration($startedAt);
            $payload['response_bytes'] = $this->responseBytes($response);

            if ($status >= 400) {
                $body = $this->errorBody($response);

                if (null !== $body) {
                    $payload['response_body'] = $body;
                }
            }

            return ActivityDraft::make(
                'outbound_http.response',
                ActivityChannel::OutboundHttp,
                $status >= 400 ? ActivitySeverity::Warning : ActivitySeverity::Info,
            )->withPayload($payload);
        });
    }

    public function failed(RequestInterface $request, Throwable $exception, float $startedAt): void
    {
        $this->write($request, function () use ($request, $exception, $startedAt): ActivityDraft {
            $payload = $this->requestPayload($request);
            $payload['duration_ms'] = $this->duration($startedAt);
            $payload['error'] = $exception->getMessage();

            return ActivityDraft::make('outbound_http.failed', ActivityChannel::OutboundHttp, ActivitySeverity::Error)
                ->withPayload($payload);
        });
    }

    public function request(RequestInterface $request, string $action, ActivitySeverity $severity): void
    {
        $this->write($request, fn (): ActivityDraft => ActivityDraft::make($action, ActivityChannel::OutboundHttp, $severity)
            ->withPayload($this->requestPayload($request)));
    }

    /** @return array<string, mixed> */
    public function requestPayload(RequestInterface $request): array
    {
        $uri = $request->getUri();
        $query = [];
        parse_str($uri->getQuery(), $query);

        return [
            'method'     => $request->getMethod(),
            'host'       => $uri->getHost(),
            'path'       => $uri->getPath(),
            'query_keys' => array_keys($query),
        ];
    }

    public function excluded(RequestInterface $request): bool
    {
        $patterns = $this->config->get('eagle-eye.capture.outbound_http.except_hosts', []);
        $patterns = is_array($patterns) ? $patterns : [];
        $patterns = [...$patterns, ...$this->driverHosts()];
        $host = $request->getUri()->getHost();

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && '' !== $pattern && fnmatch($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    public function captures(RequestInterface $request): bool
    {
        return ! (app(CaptureSuppression::class)->active() || ! $this->enabled() || $this->excluded($request))



        ;
    }

    /** @param callable(): ActivityDraft $draft */
    private function write(RequestInterface $request, callable $draft): void
    {
        if (! $this->captures($request)) {
            return;
        }

        app(EagleEyeInterface::class)->record($draft());
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('eagle-eye.enabled', true)
            && (bool) $this->config->get('eagle-eye.capture.outbound_http.enabled', true);
    }

    private function duration(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function responseBytes(ResponseInterface $response): ?int
    {
        $length = $response->getHeaderLine('Content-Length');

        return is_numeric($length) ? (int) $length : null;
    }

    /** @return array<string, mixed>|null */
    private function errorBody(ResponseInterface $response): ?array
    {
        $max = max(0, (int) $this->config->get('eagle-eye.capture.outbound_http.error_body_max_bytes', 4096));

        if (0 === $max || ! str_contains(mb_strtolower($response->getHeaderLine('Content-Type')), 'json')) {
            return null;
        }

        $stream = $response->getBody();

        if (! $stream->isSeekable()) {
            return null;
        }

        $size = $stream->getSize();

        if (is_int($size) && $size > $max) {
            return null;
        }

        $position = null;

        try {
            $position = $stream->tell();
            $stream->rewind();
            $contents = $stream->read($max + 1);
        } catch (RuntimeException) {
            return null;
        } finally {
            if (is_int($position)) {
                try {
                    $stream->seek($position);
                } catch (RuntimeException) {
                }
            }
        }

        if ('' === $contents || mb_strlen($contents, '8bit') > $max) {
            return null;
        }

        $decoded = json_decode($contents, true, 32);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function driverHosts(): array
    {
        $urls = [
            $this->config->get('eagle-eye.drivers.loki.write.endpoint'),
            $this->config->get('eagle-eye.drivers.loki.read.endpoint'),
        ];

        $hosts = [];

        foreach ($urls as $url) {
            if (! is_string($url) || '' === $url) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && '' !== $host) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
