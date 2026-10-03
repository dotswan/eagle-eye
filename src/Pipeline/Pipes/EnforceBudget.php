<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\PayloadBudget;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Resolution\PayloadNormalizer;
use LengthException;

final readonly class EnforceBudget implements ActivityPipeInterface
{
    public function __construct(private PayloadBudget $budget)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        if (! $this->identitiesFit($draft)) {
            return null;
        }

        $payload = (new PayloadNormalizer(new PayloadBudget(
            maxBytes: min($this->budget->maxBytes, EnvelopeBudget::MAX_PAYLOAD_BYTES),
            maxDepth: min($this->budget->maxDepth, 16),
            maxArrayItems: min($this->budget->maxArrayItems, 1000),
            maxStringLength: min($this->budget->maxStringLength, EnvelopeBudget::MAX_STRING_BYTES),
            maxNodes: min($this->budget->maxNodes, 2000),
        ), serializeObjects: false))->normalize($draft->payload);
        $draft->payload = is_array($payload) ? $payload : [];
        $this->boundMetadata($draft);
        $this->boundSecrets($draft);

        if ($draft->trace instanceof TraceContext && ! $this->envelopeFits($draft)) {
            $draft->payload = ['__truncated' => true, '__omitted' => 'envelope-budget'];
            $draft->secrets = [];
            $draft->summary = null;
            $draft->actor = $draft->actor instanceof Actor ? new Actor($draft->actor->type, $draft->actor->id) : null;
            $draft->subject = $draft->subject instanceof Subject ? new Subject($draft->subject->type, $draft->subject->id, className: $draft->subject->className) : null;
            $draft->request = $draft->request instanceof RequestSnapshot ? new RequestSnapshot(
                method: $draft->request->method,
                statusCode: $draft->request->statusCode,
                durationMs: $draft->request->durationMs,
            ) : null;

            if (! $this->envelopeFits($draft)) {
                return null;
            }
        }

        return $next($draft);
    }

    private function identitiesFit(ActivityDraft $draft): bool
    {
        foreach ([
            [$draft->id, 64], [$draft->action, 191],
            [$draft->actor?->type, 32], [$draft->actor?->id, 64],
            [$draft->subject?->type, 64], [$draft->subject?->id, 64], [$draft->subject?->className, 255],
            [$draft->trace?->traceId, 32], [$draft->trace?->correlationId, 64],
            [$draft->trace?->causationId, 64], [$draft->trace?->parentSpanId, 16],
        ] as [$value, $limit]) {
            if (null !== $value && (mb_strlen($value, '8bit') > $limit || ! mb_check_encoding($value, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }

    private function boundMetadata(ActivityDraft $draft): void
    {
        $draft->title = $this->boundedString($draft->title, 255) ?? '';
        $draft->summary = $this->boundedString($draft->summary, 255);
        $draft->environment = $this->boundedString($draft->environment, 32) ?? 'unknown';
        $draft->hostname = $this->boundedString($draft->hostname, 255) ?? 'unknown';

        if ($draft->actor instanceof Actor) {
            $draft->actor = new Actor($draft->actor->type, $draft->actor->id, $this->boundedString($draft->actor->label, 255), $this->boundedString($draft->actor->url, 2048));
        }

        if ($draft->subject instanceof Subject) {
            $draft->subject = new Subject($draft->subject->type, $draft->subject->id, $this->boundedString($draft->subject->label, 255), $draft->subject->className, $this->boundedString($draft->subject->url, 2048));
        }

        if ($draft->request instanceof RequestSnapshot) {
            $draft->request = new RequestSnapshot(
                method: $this->boundedString($draft->request->method, 8),
                url: $this->boundedString($draft->request->url, 2048),
                route: $this->boundedString($draft->request->route, 191),
                statusCode: $draft->request->statusCode,
                durationMs: $draft->request->durationMs,
                ip: $this->boundedString($draft->request->ip, 45),
                userAgent: $this->boundedString($draft->request->userAgent, 255),
            );
        }
    }

    private function boundedString(?string $value, int $maximum): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = (new PayloadNormalizer(new PayloadBudget(
            maxBytes: $maximum * 6 + 2,
            maxStringLength: $maximum,
        )))->normalize($value);

        return is_string($normalized) ? $normalized : null;
    }

    private function boundSecrets(ActivityDraft $draft): void
    {
        $allowed = [];
        $bytes = 0;
        $visited = 0;

        foreach ($draft->secrets as $path => $secret) {
            if (++$visited > EnvelopeBudget::MAX_SECRETS) {
                break;
            }

            try {
                $size = EnvelopeBudget::sizeOfArray([$path => $secret->toArray()], maxBytes: 24576);
            } catch (LengthException) {
                continue;
            }

            if ($bytes + $size > 24576) {
                continue;
            }

            $bytes += $size;
            $allowed[$path] = $secret;
        }

        $retained = [];
        $draft->payload = $this->reconcileSecrets($draft->payload, $allowed, $retained, 'payload');
        $draft->secrets = $retained;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, SecretRef> $allowed
     * @param array<string, SecretRef> $retained
     * @return array<array-key, mixed>
     */
    private function reconcileSecrets(array $payload, array $allowed, array &$retained, string $prefix): array
    {
        foreach ($payload as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $path = $prefix.'.'.$key;

            if (array_key_exists('__secret', $value)) {
                $reference = $value['__secret'];

                if (is_string($reference) && $reference === $path && isset($allowed[$reference])) {
                    $retained[$reference] = $allowed[$reference];
                } else {
                    $payload[$key] = '[omitted]';
                }
            } else {
                $payload[$key] = $this->reconcileSecrets($value, $allowed, $retained, $path);
            }
        }

        return $payload;
    }

    /** @phpstan-impure */
    private function envelopeFits(ActivityDraft $draft): bool
    {
        try {
            EnvelopeBudget::size($draft->freeze());

            return true;
        } catch (LengthException) {
            return false;
        }
    }
}
