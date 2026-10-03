<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Drivers\Loki\LokiStream;
use Dotswan\EagleEye\Drivers\Loki\Writers\PushWriter;
use Dotswan\EagleEye\Transport\SpoolFile;
use RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

final class LokiShipSpoolCommand extends Command
{
    protected $signature = 'activity-log:loki-ship {--chunk=500}';

    protected $description = 'Push Loki-spooled Eagle Eye lines that the push writer could not deliver.';

    public function handle(ConfigRepository $config): int
    {
        $write = $config->get('eagle-eye.drivers.loki.write', []);
        $write = is_array($write) ? $write : [];
        $path = $write['spool'] ?? null;

        if (! is_string($path) || '' === $path) {
            $this->info('No Loki spool found.');

            return self::SUCCESS;
        }

        $retry = is_array($write['retry'] ?? null) ? $write['retry'] : [];
        $auth = is_array($write['auth'] ?? null) ? $write['auth'] : [];
        $backoffs = is_array($retry['backoff_ms'] ?? null) ? array_values(array_map(intval(...), $retry['backoff_ms'])) : [200, 1000, 5000];
        $tenant = is_string($write['tenant'] ?? null) && '' !== $write['tenant'] ? $write['tenant'] : null;

        $pusher = new PushWriter(
            endpoint: (string) ($write['endpoint'] ?? 'http://127.0.0.1:3100'),
            tenant: $tenant,
            auth: $auth,
            gzip: (bool) ($write['gzip'] ?? true),
            backoffMs: $backoffs,
            timeout: (int) ($write['timeout'] ?? 5),
        );

        $chunkSize = max(1, min((int) $this->option('chunk'), 5_000));

        try {
            $shipped = (new SpoolFile($path, 268_435_456))->ship(
                static function (array $rows) use ($pusher): array {
                    $streams = [];

                    foreach ($rows as $row) {
                        if (! isset($row['stream'], $row['values']) || ! is_array($row['stream']) || ! is_array($row['values'])) {
                            throw new RuntimeException('Invalid Loki spool row retained for recovery.');
                        }

                        $streams[] = new LokiStream($row['stream'], array_values($row['values']));
                    }

                    $pusher->write($streams);

                    return [];
                },
                $chunkSize,
            );
            $this->info("Shipped {$shipped} Loki line(s) from the spool.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Loki spool shipping failed; unacknowledged entries remain in the spool: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

}
