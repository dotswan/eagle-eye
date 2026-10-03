<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Redaction\IpTruncator;
use Dotswan\EagleEye\Redaction\Redactor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Applies every non-encryption redaction mode (mask, hash, drop), the
 * sensitive-key safety net, and IP truncation in one payload traversal.
 */
final readonly class RedactSensitive implements ActivityPipeInterface
{
    public function __construct(
        private Redactor $redactor,
        private ConfigRepository $config,
    ) {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $secrets = $draft->secrets;
        $draft->payload = $this->redactor->redact(
            $draft->payload,
            $secrets,
            $this->fieldModes($draft),
            [RedactionMode::Mask->value, RedactionMode::Partial->value, RedactionMode::Hash->value, RedactionMode::Drop->value],
        );
        $draft->secrets = $secrets;
        $this->truncateIp($draft);
        $this->sanitizeUrls($draft);

        return $next($draft);
    }

    /** @return array<string, RedactionMode> */
    private function fieldModes(ActivityDraft $draft): array
    {
        return array_replace($this->redactor->fieldModes($draft->subject?->className), $draft->fieldModes);
    }

    private function sanitizeUrls(ActivityDraft $draft): void
    {
        if ($draft->request instanceof RequestSnapshot) {
            $request = $draft->request;
            $draft->request = new RequestSnapshot(
                $request->method,
                $this->sanitizeUrl($request->url),
                $request->route,
                $request->statusCode,
                $request->durationMs,
                $request->ip,
                $request->userAgent,
            );
        }

        if ($draft->actor instanceof Actor) {
            $actor = $draft->actor;
            $draft->actor = new Actor($actor->type, $actor->id, $actor->label, $this->sanitizeUrl($actor->url));
        }

        if ($draft->subject instanceof Subject) {
            $subject = $draft->subject;
            $draft->subject = new Subject($subject->type, $subject->id, $subject->label, $subject->className, $this->sanitizeUrl($subject->url));
        }
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if (null === $url || ! str_contains($url, '?')) {
            return $url;
        }

        [$base, $query] = explode('?', $url, 2);
        $fragment = '';

        if (str_contains($query, '#')) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment = '#'.$fragment;
        }

        $parts = [];

        foreach (array_slice(explode('&', $query), 0, 100) as $part) {
            [$encodedKey, $encodedValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurldecode($encodedKey);
            $value = rawurldecode($encodedValue);
            $secrets = [];
            $redacted = $this->redactor->redact([$key => $value], $secrets);

            if (array_key_exists($key, $redacted)) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $redacted[$key]);
            }
        }

        return $base.([] === $parts ? '' : '?'.implode('&', $parts)).$fragment;
    }

    private function truncateIp(ActivityDraft $draft): void
    {
        $request = $draft->request;
        $ip = $request?->ip;

        if (!$request instanceof RequestSnapshot || null === $ip) {
            return;
        }

        if ('truncate' !== $this->config->get('eagle-eye.privacy.ip.mode', 'truncate')) {
            return;
        }

        $ipv4Bits = $this->clamped('eagle-eye.privacy.ip.ipv4_bits', 24, 32);
        $ipv6Bits = $this->clamped('eagle-eye.privacy.ip.ipv6_bits', 48, 128);

        $draft->request = new RequestSnapshot(
            method: $request->method,
            url: $request->url,
            route: $request->route,
            statusCode: $request->statusCode,
            durationMs: $request->durationMs,
            ip: IpTruncator::truncate($ip, $ipv4Bits, $ipv6Bits),
            userAgent: $request->userAgent,
        );
    }

    private function clamped(string $key, int $default, int $maximum): int
    {
        return max(0, min((int) $this->config->get($key, $default), $maximum));
    }
}
