<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Pipeline\Pipes\RunEnrichers;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'activity-log:doctor';

    protected $description = 'Diagnose Eagle Eye driver health, capabilities, retention, and Loki cardinality.';

    public function handle(ActivityLogDriverFactoryInterface $drivers, ConfigRepository $config): int
    {
        try {
            $driver = $drivers->driver();
            $healthy = $driver->healthy();
            $capabilities = array_map(
                static fn (Capability $capability): string => $capability->value,
                array_filter(Capability::cases(), $driver->supports(...)),
            );
            $stats = $driver instanceof ActivityLogMaintenanceDriverInterface ? $driver->stats() : null;
            $mode = (string) $config->get('eagle-eye.drivers.loki.write.path', 'stdout');
            $selector = $config->get('eagle-eye.drivers.loki.read.selector');
            $selector = is_string($selector) && '' !== $selector ? $selector : 'package labels';
            $environments = $config->get('eagle-eye.environments', []);
            $environmentCount = is_array($environments) ? count($environments) : 0;
            $cardinality = $environmentCount * count(ActivityChannel::cases()) * count(ActivitySeverity::cases());
            $spoolPath = (string) ($config->get('eagle-eye.transport.spool.path') ?: storage_path('eagle-eye/spool.log'));
            $spoolBytes = is_file($spoolPath) ? (int) filesize($spoolPath) : 0;
            $pipeline = $config->get('eagle-eye.pipeline', []);
            $enrichersRegistered = is_array($pipeline) && in_array(RunEnrichers::class, $pipeline, true);

            $this->table(['Check', 'Value'], [
                ['driver', $driver->name()],
                ['healthy', $healthy ? 'yes' : 'no'],
                ['capabilities', implode(', ', $capabilities)],
                ['entries', null === $stats ? 'unknown' : (string) $stats['entries']],
                ['loki write mode', $mode],
                ['loki read selector', $selector],
                ['maximum bounded stream cardinality', (string) $cardinality],
                ['fast path', 'multi' === $driver->name() ? 'accelerator enabled' : 'primary only'],
                ['spool pending', 0 === $spoolBytes ? 'none' : $spoolBytes.' bytes'],
                ['pipeline enrichers', $enrichersRegistered ? 'registered' : 'missing'],
            ]);

            if ($spoolBytes > 0) {
                $this->warn("{$spoolBytes} bytes pending in spool; run activity-log:ship.");
            }

            if (! $enrichersRegistered) {
                $this->warn('The published eagle-eye.pipeline is missing '.RunEnrichers::class.'; query and Livewire enrichment will not run.');
            }

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
