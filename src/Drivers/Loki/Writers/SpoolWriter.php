<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki\Writers;

use Dotswan\EagleEye\Drivers\Loki\LokiStream;
use Dotswan\EagleEye\Transport\SpoolFile;
use Generator;

/**
 * Appends pre-formatted streams as NDJSON to disk, ready for direct
 * re-push by `activity-log:loki-ship`. One JSON object per line holds the
 * labels and a single value triple so lines stay independently recoverable.
 *
 * @internal
 */
final readonly class SpoolWriter implements LokiWriterInterface
{
    public function __construct(private string $path)
    {
    }

    /** @param list<LokiStream> $streams */
    public function write(array $streams): void
    {
        (new SpoolFile($this->path, 268_435_456))->append((function () use ($streams): Generator {
            foreach ($streams as $stream) {
                foreach ($stream->values as $value) {
                    yield [
                        'stream' => $stream->labels,
                        'values' => [$value],
                    ];
                }
            }
        })());
    }

    public function healthy(): bool
    {
        $directory = dirname($this->path);

        return (is_dir($directory) || @mkdir($directory, 0755, true)) && is_writable($directory);
    }

    public function path(): string
    {
        return $this->path;
    }
}
