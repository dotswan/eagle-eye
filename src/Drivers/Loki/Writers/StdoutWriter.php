<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki\Writers;

use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\LokiStream;
use RuntimeException;

/**
 * Writes `stream::values[i][1]` lines to a stream (default php://stdout);
 * the collector (Alloy/Promtail) owns shipping. Lines already carry the
 * routing fields at their top level.
 *
 * @internal
 */
final readonly class StdoutWriter implements LokiWriterInterface
{
    public function __construct(private string $target = 'php://stdout')
    {
    }

    /** @param list<LokiStream> $streams */
    public function write(array $streams): void
    {
        $handle = fopen($this->target, 'a');

        if (false === $handle) {
            throw new RuntimeException("Eagle Eye cannot open the Loki stdout writer target [{$this->target}].");
        }

        try {
            foreach ($streams as $stream) {
                foreach ($stream->values as [, $line]) {
                    $this->writeFully($handle, $this->routingLine($stream->labels, $line)."\n");
                }
            }

            if (! fflush($handle)) {
                throw new RuntimeException("Eagle Eye cannot flush the Loki stdout writer target [{$this->target}].");
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeFully($handle, string $contents): void
    {
        $offset = 0;
        $length = mb_strlen($contents, '8bit');

        while ($offset < $length) {
            $written = @fwrite($handle, mb_substr($contents, $offset, null, '8bit'));

            if (false === $written || 0 === $written) {
                throw new RuntimeException("Eagle Eye cannot write the Loki stdout writer target [{$this->target}].");
            }

            $offset += $written;
        }
    }

    public function healthy(): bool
    {
        $handle = @fopen($this->target, 'a');

        if (false === $handle) {
            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * The collector reads one marked JSON line containing the canonical body
     * plus stream-label candidates missing from that body.
     *
     * @param array<string, string> $labels
     */
    private function routingLine(array $labels, string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid Loki stdout JSON body.');
        }

        return json_encode(
            [LokiLineFormatter::STDOUT_MARKER => true] + $decoded + $labels,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
