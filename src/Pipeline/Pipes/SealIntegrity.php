<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final readonly class SealIntegrity implements ActivityPipeInterface
{
    public function __construct(
        private ConfigRepository $config,
        private IntegrityChainInterface $chain,
    ) {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        if ($this->enabledFor($draft)) {
            $draft->integrityHash = $this->chain->seal($draft->freeze());
        }

        return $next($draft);
    }

    private function enabledFor(ActivityDraft $draft): bool
    {
        if (! (bool) $this->config->get('eagle-eye.integrity.enabled', false)) {
            return false;
        }

        $channels = $this->config->get('eagle-eye.integrity.channels', ['auth']);

        return is_array($channels) && in_array($draft->channel->value, $channels, true);
    }
}
