<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class Gate
{
    private const array SEVERITY_RANK = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    /** @var array<string, CompiledFilterMatcher> */
    private array $matchers = [];

    /** @var array<string, mixed> */
    private array $filters = [];

    private readonly FilterChain $filterChain;

    public function __construct(ConfigRepository $config, ?FilterChain $filterChain = null)
    {
        $this->filterChain = $filterChain ?? new FilterChain($config, new Container());
    }

    public function allows(ActivityDraft $draft): bool
    {
        $this->filters = $this->filterChain->effectiveFilters($draft);
        $this->matchers = [];

        if (! $this->listPasses('channels', [$draft->channel->value])) {
            return false;
        }

        if (! $this->severityPasses($draft->severity)) {
            return false;
        }

        $actor = $draft->actor;

        if ($actor instanceof Actor && ! $this->actorPasses($actor->type, $actor->id)) {
            return false;
        }

        $method = $draft->request?->method;

        if (null !== $method && '' !== $method && ! $this->listPasses('method', [mb_strtoupper($method)])) {
            return false;
        }

        return $this->urlPasses($draft->request?->url);
    }

    /** @param list<string> $candidates */
    private function listPasses(string $key, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->matcher("{$key}.except")->matches($candidate)) {
                return false;
            }
        }

        $only = $this->matcher("{$key}.only");

        if ($only->isEmpty()) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($only->matches($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function actorPasses(string $type, ?string $id): bool
    {
        $candidates = [$type, $type.':'.($id ?? '')];

        if (null !== $id) {
            $candidates[] = $id;
        }

        foreach ($candidates as $candidate) {
            if ($this->matcher('actors.except')->matches($candidate)) {
                return false;
            }
        }

        return true;
    }

    private function severityPasses(ActivitySeverity $severity): bool
    {
        $minimum = (string) data_get($this->filters, 'severity.min', 'debug');

        return self::SEVERITY_RANK[$severity->value] >= (self::SEVERITY_RANK[$minimum] ?? 0);
    }

    private function urlPasses(?string $url): bool
    {
        if (null === $url || '' === $url) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $candidates = [$url];

        if (is_string($path) && '' !== $path) {
            $candidates[] = $path;
            $candidates[] = mb_ltrim($path, '/');
        }

        return $this->listPasses('url', $candidates);
    }

    private function matcher(string $key): CompiledFilterMatcher
    {
        return $this->matchers[$key] ??= new CompiledFilterMatcher($this->strList($key));
    }

    /** @return list<string> */
    private function strList(string $key): array
    {
        $values = data_get($this->filters, $key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }
}
