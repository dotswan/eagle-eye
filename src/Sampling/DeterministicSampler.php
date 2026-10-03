<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Sampling;

use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Contracts\Sampling\SamplerInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class DeterministicSampler implements SamplerInterface
{
    /** @var array<string, CompiledFilterMatcher> */
    private array $routeMatchers = [];

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function passes(ActivityDraft $draft): bool
    {
        $rate = $this->rateFor($draft);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        $trace = $draft->trace;
        $seed = $trace instanceof TraceContext ? $trace->traceId : $draft->id;
        $bucket = hexdec(mb_substr($seed, 0, 8)) / 0xFFFFFFFF;

        return $bucket < $rate;
    }

    private function rateFor(ActivityDraft $draft): float
    {
        $route = $draft->request?->route;

        if (null !== $route) {
            $routes = $this->config->get('eagle-eye.sampling.routes', []);

            if (is_array($routes)) {
                foreach ($routes as $pattern => $rate) {
                    if (is_string($pattern) && $this->routeMatcher($pattern)->matches($route)) {
                        return $this->clamped($rate);
                    }
                }
            }
        }

        $channels = $this->config->get('eagle-eye.sampling.channels', []);

        if (is_array($channels) && array_key_exists($draft->channel->value, $channels)) {
            return $this->clamped($channels[$draft->channel->value]);
        }

        return $this->clamped($this->config->get('eagle-eye.sampling.rate', 1.0));
    }

    private function routeMatcher(string $pattern): CompiledFilterMatcher
    {
        return $this->routeMatchers[$pattern] ??= new CompiledFilterMatcher([$pattern]);
    }

    private function clamped(mixed $rate): float
    {
        return max(0.0, min(is_numeric($rate) ? (float) $rate : 1.0, 1.0));
    }
}
