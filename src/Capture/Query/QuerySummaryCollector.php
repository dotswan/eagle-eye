<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Query;

/**
 * @phpstan-type QuerySummary array{
 *     count: int,
 *     total_ms: float,
 *     nested?: array{count: int, total_ms: float},
 *     slow: list<array{sql: string, ms: float}>,
 *     duplicates: list<array{sql: string, count: int, total_ms: float}>,
 *     truncated: bool
 * }
 */
final class QuerySummaryCollector
{
    public const string ROOT = 'root';

    private const int MAX_FRAMES = 8;

    private const int MAX_FINGERPRINTS = 50;

    private const int MAX_MEMO = 50;

    private const int MAX_MEMO_SQL_BYTES = 4096;

    private const int MAX_SLOW = 5;

    private const int MAX_DUPLICATES = 5;

    /** @var list<Frame> */
    private array $frames;

    public function __construct(
        private readonly SqlFingerprint $fingerprint = new SqlFingerprint(),
        private readonly int $slowThresholdMs = 500,
    ) {
        $this->frames = [new Frame(self::ROOT)];
    }

    public function open(string $key): void
    {
        if (count($this->frames) >= self::MAX_FRAMES) {
            $this->top()->truncated = true;

            return;
        }

        $this->frames[] = new Frame($key);
    }

    /** @return QuerySummary|null */
    public function close(string $key): ?array
    {
        $index = $this->indexOf($key);

        if (null === $index || $index < 1) {
            return null;
        }

        $closed = $this->frames[$index];
        $parent = $this->frames[$index - 1];
        $parent->nestedCount += $closed->count;
        $parent->nestedMs += $closed->totalMs;

        for ($depth = count($this->frames) - 1; $depth > $index; $depth--) {
            $parent->nestedCount += $this->frames[$depth]->count;
            $parent->nestedMs += $this->frames[$depth]->totalMs;
        }

        $this->frames = array_slice($this->frames, 0, $index);

        return $this->summarise($closed);
    }

    /** @return QuerySummary|null */
    public function peek(string $key): ?array
    {
        $index = $this->indexOf($key);

        return null === $index ? null : $this->summarise($this->frames[$index]);
    }

    public function record(string $sql, float $milliseconds): void
    {
        $frame = $this->top();
        $frame->count++;
        $frame->totalMs += $milliseconds;

        $fingerprint = $this->fingerprintFor($frame, $sql);

        if (null !== $fingerprint) {
            $this->accumulate($frame, $fingerprint, $milliseconds);
        }

        if ($milliseconds >= $this->slowThresholdMs) {
            $this->addSlow($frame, $fingerprint ?? $this->fingerprint->normalise($sql), $milliseconds);
        }
    }

    public function reset(): void
    {
        $this->frames = [new Frame(self::ROOT)];
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    public function activeKey(): string
    {
        return $this->top()->key;
    }

    /** @return QuerySummary|null */
    private function summarise(Frame $frame): ?array
    {
        if (0 === $frame->count) {
            return null;
        }

        $summary = [
            'count'    => $frame->count,
            'total_ms' => round($frame->totalMs, 2),
        ];

        if ($frame->nestedCount > 0) {
            $summary['nested'] = [
                'count'    => $frame->nestedCount,
                'total_ms' => round($frame->nestedMs, 2),
            ];
        }

        $summary['slow'] = array_map(
            static fn (array $entry): array => ['sql' => $entry['sql'], 'ms' => round($entry['ms'], 2)],
            $frame->slow,
        );

        $duplicates = array_values(array_filter(
            $frame->fingerprints,
            static fn (array $entry): bool => $entry['count'] >= 3,
        ));
        usort($duplicates, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $duplicates = array_slice($duplicates, 0, self::MAX_DUPLICATES);

        $summary['duplicates'] = array_map(
            static fn (array $entry): array => [
                'sql'      => $entry['sql'],
                'count'    => $entry['count'],
                'total_ms' => round($entry['total_ms'], 2),
            ],
            $duplicates,
        );
        $summary['truncated'] = $frame->truncated;

        return $summary;
    }

    private function fingerprintFor(Frame $frame, string $sql): ?string
    {
        if (mb_strlen($sql, '8bit') > self::MAX_MEMO_SQL_BYTES) {
            return $this->fingerprint->normalise($sql);
        }

        $key = hash('xxh3', $sql);

        if (isset($frame->memo[$key])) {
            return $frame->memo[$key];
        }

        if (count($frame->memo) >= self::MAX_MEMO) {
            $frame->truncated = true;

            return null;
        }

        $fingerprint = $this->fingerprint->normalise($sql);
        $frame->memo[$key] = $fingerprint;

        return $fingerprint;
    }

    private function accumulate(Frame $frame, string $fingerprint, float $milliseconds): void
    {
        if (! isset($frame->fingerprints[$fingerprint])) {
            if (count($frame->fingerprints) >= self::MAX_FINGERPRINTS) {
                $frame->truncated = true;

                return;
            }

            $frame->fingerprints[$fingerprint] = [
                'sql'      => $fingerprint,
                'count'    => 0,
                'total_ms' => 0.0,
                'max_ms'   => 0.0,
            ];
        }

        $frame->fingerprints[$fingerprint]['count']++;
        $frame->fingerprints[$fingerprint]['total_ms'] += $milliseconds;
        $frame->fingerprints[$fingerprint]['max_ms'] = max($frame->fingerprints[$fingerprint]['max_ms'], $milliseconds);
    }

    private function addSlow(Frame $frame, string $sql, float $milliseconds): void
    {
        if (count($frame->slow) < self::MAX_SLOW) {
            $frame->slow[] = ['sql' => $sql, 'ms' => $milliseconds];
            usort($frame->slow, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

            return;
        }

        $slowestIndex = 0;

        foreach ($frame->slow as $index => $entry) {
            if ($entry['ms'] < $frame->slow[$slowestIndex]['ms']) {
                $slowestIndex = $index;
            }
        }

        if ($milliseconds > $frame->slow[$slowestIndex]['ms']) {
            $frame->slow[$slowestIndex] = ['sql' => $sql, 'ms' => $milliseconds];
        }
    }

    private function indexOf(string $key): ?int
    {
        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            if ($this->frames[$index]->key === $key) {
                return $index;
            }
        }

        return null;
    }

    private function top(): Frame
    {
        return $this->frames[count($this->frames) - 1];
    }
}
