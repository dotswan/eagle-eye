<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Generator;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use JsonException;
use RuntimeException;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;

final readonly class SpoolTransport implements TransportInterface
{
    public function __construct(
        private string $path,
        private int $maxBytes = 10_485_760,
        private ?ErasureBarrierInterface $erasure = null,
    ) {
    }

    /** @param list<ActivityEntry> $entries */
    public function send(array $entries): void
    {
        if ($this->erasure instanceof ErasureBarrierInterface) {
            $entries = $this->erasure->allowed($entries);
        }

        if ([] === $entries) {
            return;
        }

        EnvelopeBudget::batchSize($entries);

        (new SpoolFile($this->path, $this->maxBytes))->append((function () use ($entries): Generator {
            foreach ($entries as $entry) {
                EnvelopeBudget::size($entry);
                yield $entry->toStorage();
            }
        })());
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @throws JsonException
     * @return list<array<string, mixed>>
     */
    public static function rows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if (false === $handle) {
            throw new RuntimeException('Unable to open Eagle Eye spool rows.');
        }

        $rows = [];
        $bytes = 0;

        try {
            while (false !== ($line = fgets($handle, EnvelopeBudget::MAX_BATCH_BYTES + 1))) {
                $bytes += mb_strlen($line, '8bit');

                if ((! str_ends_with($line, "\n") && ! feof($handle)) || $bytes > EnvelopeBudget::MAX_BUFFER_BYTES || count($rows) >= 1000) {
                    throw new RuntimeException('Spool rows exceed in-memory inspection limits; use the streaming ship command.');
                }

                if ('' === mb_trim($line)) {
                    continue;
                }

                $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
