<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\RequestPart;
use Dotswan\EagleEye\Filter\FilterChain;

final readonly class ProjectRequestParts implements ActivityPipeInterface
{
    public function __construct(private FilterChain $filters)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $config = $this->filters->effectiveFilters($draft);
        $parts = $config['request_parts'] ?? [];
        $parts = is_array($parts) ? $parts : [];

        $withheld = $this->withheldParts($this->strList($parts['only'] ?? []), $this->strList($parts['except'] ?? []));
        $draft->withheldParts = $withheld;

        $request = $draft->request;

        if ([] !== $withheld) {
            if ($request instanceof RequestSnapshot) {
                $draft->request = new RequestSnapshot(
                    method: $request->method,
                    url: $request->url,
                    route: $request->route,
                    statusCode: $request->statusCode,
                    durationMs: $request->durationMs,
                    ip: in_array(RequestPart::Ip, $withheld, true) ? null : $request->ip,
                    userAgent: in_array(RequestPart::UserAgent, $withheld, true) ? null : $request->userAgent,
                );
            }

            $this->stripPayloadParts($draft, $withheld);
        }

        return $next($draft);
    }

    /** @param list<RequestPart> $withheld */
    private function stripPayloadParts(ActivityDraft $draft, array $withheld): void
    {
        $requestPayload = $draft->payload['request'] ?? null;

        if (is_array($requestPayload)) {
            foreach ($withheld as $part) {
                unset($requestPayload[$part->value]);
            }

            $draft->payload['request'] = $requestPayload;
        }

        $responsePayload = $draft->payload['response'] ?? null;

        if (is_array($responsePayload) && in_array(RequestPart::ResponseBody, $withheld, true)) {
            unset($responsePayload['body']);
            $draft->payload['response'] = $responsePayload;
        }
    }

    /**
     * @param list<string> $only
     * @param list<string> $except
     * @return list<RequestPart>
     */
    private function withheldParts(array $only, array $except): array
    {
        $withheld = [];

        foreach (RequestPart::cases() as $part) {
            if (in_array($part->value, $except, true)) {
                $withheld[] = $part;
            } elseif ([] !== $only && ! in_array($part->value, $only, true)) {
                $withheld[] = $part;
            }
        }

        return $withheld;
    }

    /** @return list<string> */
    private function strList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }
}
