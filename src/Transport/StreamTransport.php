<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use RuntimeException;

final readonly class StreamTransport implements TransportInterface
{
    public function __construct(private string $target = 'php://stdout')
    {
    }

    /** @param list<ActivityEntry> $entries */
    public function send(array $entries): void
    {
        EnvelopeBudget::batchSize($entries);
        $stream = fopen($this->target, 'a');

        if (false === $stream) {
            throw new RuntimeException("Unable to open the Eagle Eye stream target [{$this->target}].");
        }

        try {
            foreach ($entries as $entry) {
                $this->writeFully($stream, json_encode($entry->toStorage(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
            }

            if (! fflush($stream)) {
                throw new RuntimeException("Unable to flush the Eagle Eye stream target [{$this->target}].");
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function writeFully($stream, string $contents): void
    {
        $offset = 0;
        $length = mb_strlen($contents, '8bit');

        while ($offset < $length) {
            $written = @fwrite($stream, mb_substr($contents, $offset, null, '8bit'));

            if (false === $written || 0 === $written) {
                throw new RuntimeException("Unable to write the Eagle Eye stream target [{$this->target}].");
            }

            $offset += $written;
        }
    }
}
