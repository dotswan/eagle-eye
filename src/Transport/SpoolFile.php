<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Closure;
use RuntimeException;
use Throwable;
use JsonException;
use Dotswan\EagleEye\Data\EnvelopeBudget;

final readonly class SpoolFile
{
    private const int MAX_LINE_BYTES = 1_048_576;

    public function __construct(private string $path, private int $maxBytes = 10_485_760)
    {
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function append(iterable $rows): void
    {
        $this->locked('.lock', function () use ($rows): void {
            $handle = fopen($this->path, 'c+b');

            if (false === $handle) {
                throw new RuntimeException('Unable to open Eagle Eye spool.');
            }

            fseek($handle, 0, SEEK_END);
            $start = ftell($handle);
            $bytes = $this->bytes();

            try {
                foreach ($rows as $row) {
                    EnvelopeBudget::sizeOfArray($row, self::MAX_LINE_BYTES - 1, EnvelopeBudget::MAX_ENTRY_BYTES);
                    $line = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
                    $length = mb_strlen($line, '8bit');

                    if ($length > self::MAX_LINE_BYTES || $bytes + $length > $this->maxBytes) {
                        throw new RuntimeException('Eagle Eye spool byte limit exceeded.');
                    }

                    $this->write($handle, $line);
                    $bytes += $length;
                }

                if (! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Unable to synchronize Eagle Eye spool.');
                }
            } catch (Throwable $exception) {
                if (false !== $start) {
                    ftruncate($handle, max(0, $start));
                    fflush($handle);
                }

                throw $exception;
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * @param Closure(list<array<string, mixed>>): list<array<string, mixed>> $deliver
     * @param null|Closure(array<string, mixed>): mixed $validate
     */
    public function ship(Closure $deliver, int $chunkSize, ?Closure $validate = null): int
    {
        return $this->locked('.ship.lock', function () use ($deliver, $chunkSize, $validate): int {
            $claims = $this->locked('.lock', function (): array {
                $claims = $this->claims();

                if (is_file($this->path)) {
                    $claim = $this->path.'.processing.'.bin2hex(random_bytes(12));

                    if (! rename($this->path, $claim)) {
                        throw new RuntimeException('Unable to claim Eagle Eye spool.');
                    }

                    $claims[] = $claim;
                }

                return $claims;
            });
            $shipped = 0;

            foreach ($claims as $claim) {
                $shipped += $this->shipClaim($claim, $deliver, max(1, min($chunkSize, 100)), $validate);
            }

            return $shipped;
        }, true);
    }

    /**
     * @param Closure(list<array<string, mixed>>): list<array<string, mixed>> $deliver
     * @param null|Closure(array<string, mixed>): mixed $validate
     */
    private function shipClaim(string $claim, Closure $deliver, int $chunkSize, ?Closure $validate): int
    {
        $handle = fopen($claim, 'rb');

        if (false === $handle) {
            throw new RuntimeException('Unable to read claimed Eagle Eye spool.');
        }

        $storedOffset = is_file($claim.'.offset') ? file_get_contents($claim.'.offset', length: 32) : '0';
        $offset = (int) $storedOffset;

        if (! is_string($storedOffset) || ! ctype_digit($storedOffset) || $offset < 0 || $offset > (int) filesize($claim) || 0 !== fseek($handle, $offset)) {
            fclose($handle);

            throw new RuntimeException('Unable to resume Eagle Eye spool.');
        }

        $shipped = 0;
        $quarantined = false;

        try {
            while (! feof($handle)) {
                $rows = [];
                $bytes = 0;

                while (count($rows) < $chunkSize && $bytes < self::MAX_LINE_BYTES) {
                    $lineStart = ftell($handle);
                    $line = fgets($handle, self::MAX_LINE_BYTES + 1);

                    if (false === $line) {
                        break;
                    }

                    if ('' === mb_trim($line)) {
                        continue;
                    }

                    try {
                        $row = str_ends_with($line, "\n") || feof($handle)
                            ? json_decode($line, true, 32, JSON_THROW_ON_ERROR)
                            : null;
                    } catch (JsonException) {
                        $row = null;
                    }

                    $discard = false;

                    if (is_array($row) && $validate instanceof Closure) {
                        try {
                            $discard = false === $validate($row);
                        } catch (Throwable) {
                            $row = null;
                        }
                    }

                    if ($discard) {
                        if ([] !== $rows && false !== $lineStart) {
                            fseek($handle, $lineStart);

                            break;
                        }

                        $position = ftell($handle);

                        if (false === $position) {
                            throw new RuntimeException('Unable to checkpoint discarded Eagle Eye spool row.');
                        }

                        $this->checkpoint($claim, $position);

                        continue;
                    }

                    if (! is_array($row)) {
                        if ([] !== $rows && false !== $lineStart) {
                            fseek($handle, $lineStart);

                            break;
                        }

                        $this->quarantine($handle, $line);
                        $position = ftell($handle);

                        if (false === $position) {
                            throw new RuntimeException('Unable to checkpoint quarantined Eagle Eye spool row.');
                        }

                        $this->checkpoint($claim, $position);
                        $quarantined = true;

                        continue;
                    }

                    if ([] !== $rows && $bytes + mb_strlen($line, '8bit') > self::MAX_LINE_BYTES && false !== $lineStart) {
                        fseek($handle, $lineStart);

                        break;
                    }

                    $rows[] = $row;
                    $bytes += mb_strlen($line, '8bit');
                }

                if ([] === $rows) {
                    continue;
                }

                $failed = app(CaptureSuppression::class)->run(static fn (): array => $deliver($rows));

                if ([] !== $failed) {
                    $this->append($failed);
                }

                $position = ftell($handle);

                if (false === $position) {
                    throw new RuntimeException('Unable to checkpoint Eagle Eye spool.');
                }

                $this->checkpoint($claim, $position);
                $shipped += count($rows) - count($failed);

                if ([] !== $failed) {
                    throw new RuntimeException('Eagle Eye spool contains rejected entries; retained for retry.');
                }
            }
        } finally {
            fclose($handle);
        }

        if (! unlink($claim)) {
            throw new RuntimeException('Unable to remove acknowledged Eagle Eye spool.');
        }

        if (is_file($claim.'.offset')) {
            unlink($claim.'.offset');
        }

        if ($quarantined) {
            throw new RuntimeException('Invalid Eagle Eye spool rows quarantined; valid rows were shipped.');
        }

        return $shipped;
    }

    /** @param resource $source */
    private function quarantine(mixed $source, string $line): void
    {
        $this->locked('.lock', function () use ($source, $line): void {
            $handle = fopen($this->path.'.quarantine', 'ab');

            if (false === $handle) {
                throw new RuntimeException('Unable to quarantine Eagle Eye spool row.');
            }

            $bytes = $this->bytes();

            try {
                do {
                    $bytes += mb_strlen($line, '8bit');

                    if ($bytes > $this->maxBytes) {
                        throw new RuntimeException('Eagle Eye quarantine quota exceeded; source row retained.');
                    }

                    $this->write($handle, $line);

                    if (str_ends_with($line, "\n") || feof($source)) {
                        break;
                    }

                    $line = fgets($source, self::MAX_LINE_BYTES + 1);
                } while (false !== $line);

                if (! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Unable to synchronize Eagle Eye quarantine.');
                }
            } finally {
                fclose($handle);
            }
        });
    }

    private function checkpoint(string $claim, int $offset): void
    {
        $temporary = $claim.'.offset.tmp';
        $handle = fopen($temporary, 'wb');

        if (false === $handle) {
            throw new RuntimeException('Unable to open Eagle Eye spool checkpoint.');
        }

        try {
            $this->write($handle, (string) $offset);

            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Unable to synchronize Eagle Eye spool checkpoint.');
            }
        } finally {
            fclose($handle);
        }

        if (! rename($temporary, $claim.'.offset')) {
            throw new RuntimeException('Unable to commit Eagle Eye spool checkpoint.');
        }
    }

    /** @return list<string> */
    private function claims(): array
    {
        $claims = glob($this->path.'.processing*') ?: [];

        return array_values(array_filter($claims, static fn (string $path): bool => ! str_ends_with($path, '.offset') && ! str_ends_with($path, '.tmp')));
    }

    private function bytes(): int
    {
        $bytes = 0;

        foreach ([$this->path, $this->path.'.quarantine', ...$this->claims()] as $path) {
            clearstatcache(true, $path);
            $bytes += is_file($path) ? (int) filesize($path) : 0;
        }

        return $bytes;
    }

    /** @param resource $handle */
    private function write(mixed $handle, string $bytes): void
    {
        $offset = 0;
        $length = mb_strlen($bytes, '8bit');

        while ($offset < $length) {
            $written = fwrite($handle, mb_substr($bytes, $offset, null, '8bit'));

            if (false === $written || 0 === $written) {
                throw new RuntimeException('Unable to write complete Eagle Eye spool data.');
            }

            $offset += $written;
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function locked(string $suffix, Closure $callback, bool $nonblocking = false): mixed
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Eagle Eye spool directory.');
        }

        $handle = fopen($this->path.$suffix, 'c+b');

        if (false === $handle) {
            throw new RuntimeException('Unable to open Eagle Eye spool lock.');
        }

        try {
            if (! flock($handle, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) {
                throw new RuntimeException('Eagle Eye spool is already being shipped.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
