<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use JsonException;
use RuntimeException;
use Illuminate\Filesystem\Filesystem;

final class ExportCommand extends EagleEyeCommand
{
    protected $signature = 'activity-log:export {--output=php://stdout} {--chunk=500}';

    protected $description = 'Stream activity entries as newline-delimited JSON with bounded memory.';

    /** @throws JsonException */
    public function handle(ActivityRepositoryInterface $repository, Filesystem $files): int
    {
        $target = $this->stringOption('output', 'php://stdout');

        if (! str_contains($target, '://')) {
            $files->ensureDirectoryExists(dirname($target));
        }

        $stream = fopen($target, 'wb');

        if (false === $stream) {
            throw new RuntimeException("Unable to open export target [{$target}].");
        }

        $query = (new ActivityQuery())->limit(max(1, min((int) $this->option('chunk'), 100)));
        $count = 0;

        try {
            do {
                $result = $repository->search($query);

                foreach ($result->entries as $entry) {
                    $this->write($stream, json_encode($entry->toStorage(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
                    $count++;
                }

                if ($result->hasMore && (! $result->nextCursor instanceof Cursor || $result->nextCursor->encode() === $query->cursor?->encode())) {
                    $this->error("Export incomplete after {$count} entries: the backend supplied no advancing continuation cursor.");

                    return self::FAILURE;
                }

                $query->cursor($result->nextCursor);
            } while ($result->hasMore);
        } finally {
            fclose($stream);
        }

        if ('php://stdout' !== $target) {
            $this->info("Exported {$count} activity entry or entries to [{$target}].");
        }

        return self::SUCCESS;
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $contents): void
    {
        $offset = 0;

        while ($offset < mb_strlen($contents, '8bit')) {
            $written = fwrite($stream, mb_substr($contents, $offset, null, '8bit'));

            if (false === $written || 0 === $written) {
                throw new RuntimeException('Unable to complete the Eagle Eye export write.');
            }

            $offset += $written;
        }
    }
}
