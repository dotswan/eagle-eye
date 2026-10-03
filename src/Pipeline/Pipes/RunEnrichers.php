<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Pipeline\DraftEnricherInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Container\Container;

final class RunEnrichers implements ActivityPipeInterface
{
    /** @var list<DraftEnricherInterface>|null */
    private ?array $enrichers = null;

    public function __construct(private readonly Container $container)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        foreach ($this->enrichers() as $enricher) {
            $enricher->enrich($draft);
        }

        return $next($draft);
    }

    /** @return list<DraftEnricherInterface> */
    private function enrichers(): array
    {
        if (null !== $this->enrichers) {
            return $this->enrichers;
        }

        $this->enrichers = [];

        foreach ($this->container->tagged('eagle-eye.enrichers') as $enricher) {
            if ($enricher instanceof DraftEnricherInterface) {
                $this->enrichers[] = $enricher;
            }
        }

        return $this->enrichers;
    }
}
