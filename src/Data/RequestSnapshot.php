<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

final readonly class RequestSnapshot
{
    public function __construct(
        public ?string $method = null,
        public ?string $url = null,
        public ?string $route = null,
        public ?int $statusCode = null,
        public ?int $durationMs = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {
    }

    /**
     * @return array{method: ?string, url: ?string, route: ?string, status_code: ?int, duration_ms: ?int, ip: ?string, user_agent: ?string}
     */
    public function toArray(): array
    {
        return [
            'method'      => $this->method,
            'url'         => $this->url,
            'route'       => $this->route,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'ip'          => $this->ip,
            'user_agent'  => $this->userAgent,
        ];
    }
}
