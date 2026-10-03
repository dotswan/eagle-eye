<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter;

use Closure;
use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Filter\Rules\ActorRule;
use Dotswan\EagleEye\Filter\Rules\ChannelRule;
use Dotswan\EagleEye\Filter\Rules\ClassRule;
use Dotswan\EagleEye\Filter\Rules\ClosureRule;
use Dotswan\EagleEye\Filter\Rules\EnvironmentRule;
use Dotswan\EagleEye\Filter\Rules\MethodRule;
use Dotswan\EagleEye\Filter\Rules\TagRule;
use Dotswan\EagleEye\Filter\Rules\UrlRule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

final readonly class FilterChain
{
    public function __construct(
        private ConfigRepository $config,
        private Container $container,
    ) {
    }

    public function decide(ActivityDraft $draft): FilterDecision
    {
        [$rules, $default] = $this->chainFor($draft);
        $allowed = false;

        foreach ($rules as $rule) {
            $decision = $rule->decide($draft);

            if (FilterDecision::Deny === $decision) {
                return FilterDecision::Deny;
            }

            if (FilterDecision::Allow === $decision) {
                $allowed = true;
            }
        }

        return $allowed ? FilterDecision::Allow : $default;
    }

    /** @return array<string, mixed> */
    public function effectiveFilters(ActivityDraft $draft): array
    {
        $key = $this->overrideKey($draft) ?? '';

        $filters = $this->config->get('eagle-eye.filters', []);
        $filters = is_array($filters) ? $filters : [];

        if ('' !== $key) {
            $override = $this->overrideConfig($key);
            $filters = $this->mergeOverrides($filters, $override);
        }

        return $filters;
    }

    /**
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private function mergeOverrides(array $filters, array $override): array
    {
        foreach ($override as $key => $value) {
            $existing = $filters[$key] ?? null;
            $filters[$key] = is_array($value) && ! array_is_list($value) && is_array($existing)
                ? $this->mergeOverrides($existing, $value)
                : $value;
        }

        return $filters;
    }

    /** @return array{0: list<FilterRuleInterface>, 1: FilterDecision} */
    private function chainFor(ActivityDraft $draft): array
    {
        return $this->buildChain($this->effectiveFilters($draft));
    }

    private function overrideKey(ActivityDraft $draft): ?string
    {
        $overrides = $this->config->get('eagle-eye.overrides', []);

        if (! is_array($overrides) || [] === $overrides) {
            return null;
        }

        $candidates = [];
        $route = $draft->request?->route;

        if (null !== $route && '' !== $route) {
            $candidates[] = $route;
        }

        $url = $draft->request?->url;

        if (null !== $url && '' !== $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $candidates[] = $url;

            if (is_string($path)) {
                $candidates[] = mb_ltrim($path, '/');
            }
        }

        if ([] === $candidates) {
            return null;
        }

        foreach ($overrides as $pattern => $override) {
            if (! is_string($pattern) || ! is_array($override)) {
                continue;
            }

            $matcher = new CompiledFilterMatcher([$pattern]);

            foreach ($candidates as $candidate) {
                if ($matcher->matches($candidate)) {
                    return $pattern;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function overrideConfig(string $key): array
    {
        $overrides = $this->config->get('eagle-eye.overrides', []);
        $override = is_array($overrides) ? ($overrides[$key] ?? []) : [];

        return is_array($override) ? $override : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<FilterRuleInterface>, 1: FilterDecision}
     */
    private function buildChain(array $filters): array
    {
        $rules = [
            new UrlRule($this->strList($filters, 'url.only'), $this->strList($filters, 'url.except')),
            new MethodRule($this->strList($filters, 'method.only'), $this->strList($filters, 'method.except')),
            new ChannelRule($this->strList($filters, 'channels.only'), $this->strList($filters, 'channels.except')),
            new ActorRule($this->strList($filters, 'actors.only'), $this->strList($filters, 'actors.except')),
            new TagRule($this->strList($filters, 'tags.only'), $this->strList($filters, 'tags.except')),
            new EnvironmentRule($this->strList($filters, 'environments.only'), $this->strList($filters, 'environments.except')),
            new ClassRule($this->strList($filters, 'models.only'), $this->strList($filters, 'models.except')),
        ];

        $custom = $filters['rules'] ?? [];

        if (is_array($custom)) {
            foreach ($custom as $rule) {
                if ($rule instanceof Closure) {
                    $rules[] = new ClosureRule($rule);
                } elseif (is_string($rule) && class_exists($rule)) {
                    $instance = $this->container->make($rule);

                    if ($instance instanceof FilterRuleInterface) {
                        $rules[] = $instance;
                    }
                }
            }
        }

        $default = FilterDecision::tryFrom((string) ($filters['default_action'] ?? 'allow'))
            ?? FilterDecision::Allow;

        return [$rules, FilterDecision::Abstain === $default ? FilterDecision::Allow : $default];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private function strList(array $filters, string $dotKey): array
    {
        $value = data_get($filters, $dotKey, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
