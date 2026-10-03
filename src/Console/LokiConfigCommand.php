<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class LokiConfigCommand extends Command
{
    protected $signature = 'activity-log:loki-config {--retention-days= : Override the configured default retention window}';

    protected $description = 'Emit the Alloy collector stage and Loki limits fragment for the Eagle Eye Loki driver.';

    public function handle(ConfigRepository $config): int
    {
        $read = $config->get('eagle-eye.drivers.loki.read', []);
        $read = is_array($read) ? $read : [];
        $structured = (bool) ($read['structured_metadata'] ?? true);
        $override = $this->option('retention-days');
        $retentionDays = max(1, (int) (is_numeric($override) ? $override : $config->get('eagle-eye.retention.default_days', 30)));
        $tagRules = $this->retentionRules($config, 'tags');

        if ([] !== $tagRules) {
            $this->error('Loki stream retention cannot enforce tag rules because tags are structured metadata.');

            return self::FAILURE;
        }

        $extracted = array_unique(['app', 'env', 'channel', 'severity', 'logged_at', ...LokiLineFormatter::METADATA_KEYS]);
        $expressions = implode(', ', array_map(static fn (string $key): string => "{$key} = \"{$key}\"", $extracted));
        $metadata = implode(', ', array_map(static fn (string $key): string => "{$key} = \"\"", LokiLineFormatter::METADATA_KEYS));

        $this->line('# Alloy stage for the stdout write path');
        $this->line('loki.process "activity_log" {');
        $this->line('  forward_to = [loki.write.default.receiver]');
        $this->line('');
        $this->line('  stage.json {');
        $this->line("    expressions = { {$expressions} }");
        $this->line('  }');
        $this->line('  // ONLY these become labels — never actor_id / subject_id / trace_id');
        $this->line('  stage.labels { values = { app = "", env = "", channel = "", severity = "" } }');
        $this->line('');

        if ($structured) {
            $this->line('  stage.structured_metadata {');
            $this->line("    values = { {$metadata} }");
            $this->line('  }');
            $this->line('');
        }

        $this->line('  stage.timestamp { source = "logged_at", format = "RFC3339Nano" }');
        $this->line('}');
        $this->newLine();
        $this->line('# Loki limits fragment (limits_config)');
        $this->line(sprintf('retention_period: %dh', $retentionDays * 24));
        $this->line('retention_stream:');

        $priority = 1;
        foreach ($this->retentionRules($config, 'channels') as $value => $days) {
            $this->retentionStream('channel', $value, $days, $priority++);
        }

        foreach ($this->retentionRules($config, 'severities') as $value => $days) {
            $this->retentionStream('severity', $value, $days, $priority++);
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function retentionRules(ConfigRepository $config, string $group): array
    {
        $configured = $config->get("eagle-eye.retention.{$group}", []);

        if (! is_array($configured)) {
            return [];
        }

        $rules = [];
        foreach ($configured as $value => $days) {
            if (is_string($value) && '' !== $value && is_numeric($days)) {
                $rules[$value] = max(1, (int) $days);
            }
        }

        return $rules;
    }

    private function retentionStream(string $label, string $value, int $days, int $priority): void
    {
        $this->line(sprintf('  - selector: \'{%s="%s"}\'', $label, addcslashes($value, '"\\')));
        $this->line("    priority: {$priority}");
        $this->line(sprintf('    period: %dh', $days * 24));
    }
}
