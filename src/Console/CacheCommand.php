<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class CacheCommand extends Command
{
    protected $signature = 'activity-log:cache';

    protected $description = 'Warm configured Eagle Eye subject metadata.';

    public function handle(ConfigRepository $config, AttributeMetadataReader $metadata): int
    {
        $subjects = $config->get('eagle-eye.subjects.per_class', []);
        $subjects = is_array($subjects) ? array_keys($subjects) : [];
        $warmed = 0;

        foreach ($subjects as $subject) {
            if (is_string($subject) && class_exists($subject)) {
                $metadata->read($subject);
                $warmed++;
            }
        }

        $this->info("Warmed {$warmed} subject metadata definition or definitions.");

        return self::SUCCESS;
    }
}
