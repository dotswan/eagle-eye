<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tagging;

use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Contracts\Tagging\TagProviderInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

final readonly class AutomaticTagProvider implements TagProviderInterface
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function tags(ActivityDraft $draft): array
    {
        if (! (bool) $this->config->get('eagle-eye.tags.automatic', true)) {
            return [];
        }

        $tags = ['channel:'.$draft->channel->value];
        $request = $draft->request;

        if ($request instanceof RequestSnapshot) {
            if (null !== $request->route && '' !== $request->route) {
                $tags[] = 'route:'.$request->route;
            }

            if (null !== $request->statusCode && $request->statusCode >= 500) {
                $tags[] = 'status:5xx';
            } elseif (null !== $request->statusCode && $request->statusCode >= 400) {
                $tags[] = 'status:4xx';
            }

            $threshold = max(1, min((int) $this->config->get('eagle-eye.tags.slow_threshold_ms', 1000), 600_000));

            if (null !== $request->durationMs && $request->durationMs >= $threshold) {
                $tags[] = 'slow';
            }
        }

        $module = $this->module($draft->subject?->className);

        if (null !== $module) {
            $tags[] = 'module:'.$module;
        }

        return $tags;
    }

    private function module(?string $class): ?string
    {
        if (null === $class || ! str_contains($class, '\\')) {
            return null;
        }

        $segments = explode('\\', $class);

        if (count($segments) < 2) {
            return null;
        }

        return Str::kebab($segments[1]);
    }
}
