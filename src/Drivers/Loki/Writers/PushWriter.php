<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki\Writers;

use Illuminate\Http\Client\PendingRequest;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Drivers\Loki\LokiStream;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Drivers\Loki\LokiWriteException;
use Dotswan\EagleEye\Drivers\Loki\BoundedResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Batched `POST /loki/api/v1/push` with exponential backoff. On final
 * failure the batch falls back to the spool (zero-loss) when one is
 * configured; otherwise a LokiWriteException propagates to the transport
 * layer, which owns reporting.
 *
 * @internal
 */
final class PushWriter implements LokiWriterInterface
{
    private bool $acknowledged = false;
    /**
     * @param array{type?: string, username?: ?string, password?: ?string} $auth
     * @param list<int> $backoffMs
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $tenant = null,
        private array $auth = ['type' => 'none', 'username' => null, 'password' => null],
        private readonly bool $gzip = true,
        private readonly array $backoffMs = [200, 1000, 5000],
        private readonly ?SpoolWriter $spool = null,
        private readonly int $timeout = 5,
    ) {
    }

    /** @param list<LokiStream> $streams */
    public function write(array $streams): void
    {
        $this->acknowledged = false;
        if ([] === $streams) {
            return;
        }

        try {
            $this->push($streams);
            $this->acknowledged = true;

            return;
        } catch (Throwable $throwable) {
            if (! $this->spool instanceof SpoolWriter) {
                throw new LokiWriteException(sprintf('Eagle Eye could not push %d stream(s) to Loki and no spool is configured: %s', count($streams), $throwable->getMessage()), $throwable->getCode(), previous: $throwable);
            }

            $this->spool->write($streams);
        }
    }

    public function acknowledged(): bool
    {
        return $this->acknowledged;
    }

    public function healthy(): bool
    {
        try {
            $deadline = BoundedResponse::deadline(2);
            $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'readiness', 4096)->timeout(BoundedResponse::remainingSeconds($deadline, 'readiness'))->get(mb_rtrim($this->endpoint, '/').'/ready'));

            return $response->ok() && str_contains(BoundedResponse::read($response, 'readiness', 4096, $deadline), 'ready');
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<LokiStream> $streams */
    private function push(array $streams): void
    {
        $deadline = BoundedResponse::deadline($this->timeout);
        $structured = [
            'streams' => array_map(
                static fn (LokiStream $stream): array => [
                    'stream' => $stream->labels,
                    'values' => $stream->values,
                ],
                $streams,
            ),
        ];
        EnvelopeBudget::sizeOfArray(
            $structured,
            EnvelopeBudget::MAX_BATCH_BYTES,
            EnvelopeBudget::MAX_ENTRY_BYTES,
            EnvelopeBudget::MAX_BATCH_NODES,
        );
        $payload = json_encode($structured, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $attempt = 0;
        $backoffs = array_map(
            static fn (int $milliseconds): int => max(0, min($milliseconds, 10_000)),
            array_slice($this->backoffMs, 0, 5),
        );

        while (true) {
            $request = $this->request($deadline, 'push', 4096)
                ->timeout(BoundedResponse::remainingSeconds($deadline, 'push'))
                ->connectTimeout(max(1, min($this->timeout, 3)))
                ->withHeader('Content-Type', 'application/json');

            if ($this->gzip) {
                $request = $request->withHeader('Content-Encoding', 'gzip')->withBody(gzencode($payload) ?: $payload, 'application/json');
            } else {
                $request = $request->withBody($payload, 'application/json');
            }

            try {
                $response = app(CaptureSuppression::class)->run(fn () => $request->post(mb_rtrim($this->endpoint, '/').'/loki/api/v1/push'));
                BoundedResponse::remainingSeconds($deadline, 'push');
            } catch (Throwable $throwable) {
                if ($attempt >= count($backoffs)) {
                    throw new LokiWriteException(sprintf(
                        'Loki push failed after %d attempts: %s',
                        $attempt + 1,
                        $throwable->getMessage(),
                    ), $throwable->getCode(), previous: $throwable);
                }

                $this->backoff($backoffs[$attempt], $deadline);
                $attempt++;

                continue;
            }

            if ($response->successful() || 204 === $response->status()) {
                $response->close();
                return;
            }

            if ($attempt >= count($backoffs)) {
                throw new LokiWriteException(sprintf(
                    'Loki push failed with status %d after %d attempts: %s',
                    $response->status(),
                    $attempt + 1,
                    mb_strcut(BoundedResponse::read($response, 'push error', 4096, $deadline), 0, 4096, 'UTF-8'),
                ));
            }

            $response->close();
            $this->backoff($backoffs[$attempt], $deadline);
            $attempt++;
        }
    }

    private function backoff(int $milliseconds, int $deadline): void
    {
        $remainingMicroseconds = (int) floor(BoundedResponse::remainingSeconds($deadline, 'push') * 1_000_000);

        if ($milliseconds * 1000 >= $remainingMicroseconds) {
            throw new LokiWriteException('Loki push exceeded its whole-transfer deadline during retry backoff.');
        }

        usleep($milliseconds * 1000);
    }

    private function request(int $deadline, string $operation, int $maximum): PendingRequest
    {
        $request = Http::asJson()
            ->withHeader('Accept-Encoding', 'identity')
            ->withOptions(BoundedResponse::transferOptions($deadline, $operation, $maximum));

        BoundedResponse::assertDeadlineCapable($request);

        if (null !== $this->tenant && '' !== $this->tenant) {
            $request = $request->withHeader('X-Scope-OrgID', $this->tenant);
        }

        return match ($this->auth['type'] ?? 'none') {
            'basic'  => $request->withBasicAuth((string) ($this->auth['username'] ?? ''), (string) ($this->auth['password'] ?? '')),
            'bearer' => $request->withToken((string) ($this->auth['password'] ?? '')),
            default  => $request,
        };
    }
}
