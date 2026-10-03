<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Retention;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use LogicException;

final readonly class RetentionPruner
{
    public function __construct(
        private ActivityLogDriverFactoryInterface $drivers,
        private ConfigRepository $config,
    ) {
    }

    public function prune(?DateTimeImmutable $now = null): int
    {
        $plan = $this->plan();
        $driver = $this->drivers->driver();

        if (! $driver instanceof ActivityLogMaintenanceDriverInterface) {
            throw new LogicException("Driver [{$driver->name()}] does not support retention.");
        }

        $now ??= new DateTimeImmutable();
        $total = 0;

        foreach ($plan as [$days, $scope]) {
            $total += $driver->prune($now->modify("-{$days} days"), $scope);
        }

        return $total;
    }

    /** @return list<array{0: int, 1: ActivityQuery}> */
    private function plan(): array
    {
        $default = $this->days($this->config->get('eagle-eye.retention.default_days', 30), 'retention.default_days');
        $channels = $this->rules('channels', array_column(ActivityChannel::cases(), 'value'));
        $severities = $this->rules('severities', array_column(ActivitySeverity::cases(), 'value'));
        $tags = $this->tagRules();
        $minimumBaseDays = 36_500;
        $plan = [];

        foreach (ActivityChannel::cases() as $channel) {
            foreach (ActivitySeverity::cases() as $severity) {
                $days = $severities[$severity->value] ?? $channels[$channel->value] ?? $default;
                $minimumBaseDays = min($minimumBaseDays, $days);
                $plan[] = [$days, (new ActivityQuery())->channels($channel)->severities($severity)];
            }
        }

        foreach ($tags as $tag => $days) {
            if ($days > $minimumBaseDays) {
                throw new InvalidArgumentException('Tag retention may only shorten the base retention window.');
            }

            $plan[] = [$days, (new ActivityQuery())->withAnyTag([$tag])];
        }

        return $plan;
    }

    /** @return array<string, int> */
    private function tagRules(): array
    {
        $configured = $this->config->get('eagle-eye.retention.tags', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('eagle-eye.retention.tags must be an array.');
        }

        $rules = [];

        foreach ($configured as $tag => $days) {
            if (! is_string($tag) || '' === $tag || mb_strlen($tag, '8bit') > 256 || preg_match('/[\x00-\x1F\x7F]/', $tag)) {
                throw new InvalidArgumentException('eagle-eye.retention.tags contains an invalid tag key.');
            }

            $rules[$tag] = $this->days($days, "retention.tags.{$tag}");
        }

        return $rules;
    }

    /**
     * @param list<string> $known
     * @return array<string, int>
     */
    private function rules(string $name, array $known): array
    {
        $configured = $this->config->get("eagle-eye.retention.{$name}", []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException("eagle-eye.retention.{$name} must be an array.");
        }

        $rules = [];

        foreach ($configured as $key => $days) {
            if (! is_string($key) || ! in_array($key, $known, true)) {
                throw new InvalidArgumentException("eagle-eye.retention.{$name} contains an unknown key.");
            }

            $rules[$key] = $this->days($days, "retention.{$name}.{$key}");
        }

        return $rules;
    }

    private function days(mixed $days, string $key): int
    {
        if (! is_int($days) || $days < 1 || $days > 36_500) {
            throw new InvalidArgumentException("eagle-eye.{$key} must be an integer from 1 to 36500.");
        }

        return $days;
    }
}
