<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline;

use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;

final readonly class PipelineRunner
{
    public function __construct(
        private Container $container,
        private ConfigRepository $config,
    ) {
    }

    public function run(ActivityDraft $draft): ?ActivityDraft
    {
        return new Pipeline($this->container)
            ->send($draft)
            ->through($this->pipes())
            ->thenReturn();
    }

    /** @return list<ActivityPipeInterface> */
    private function pipes(): array
    {
        $configured = $this->config->get('eagle-eye.pipeline', []);

        if (! is_array($configured)) {
            return [];
        }

        $pipes = [];

        foreach ($configured as $pipe) {
            if (! is_string($pipe) || ! class_exists($pipe)) {
                continue;
            }

            $instance = $this->container->make($pipe);

            if ($instance instanceof ActivityPipeInterface) {
                $pipes[] = $instance;
            }
        }

        return $pipes;
    }
}
