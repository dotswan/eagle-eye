<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

use Closure;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use ReflectionProperty;
use Throwable;

/** @internal */
final class BoundedResponse
{
    public const int MAX_BYTES = 2_097_152;

    private const string GUZZLE_HANDLER_NAMESPACE = 'GuzzleHttp\\Handler\\';

    /**
     * Runs an HTTP send with capture suppression, rethrowing the original
     * Eagle Eye failure when the client wrapped it. Guzzle reports exceptions
     * thrown from the `on_headers` and `progress` callbacks as generic
     * transport errors, which Laravel surfaces as a connection failure.
     *
     * @param  Closure(): Response  $send
     */
    public static function send(Closure $send): Response
    {
        try {
            return app(CaptureSuppression::class)->run($send);
        } catch (Throwable $exception) {
            throw self::originalFailure($exception) ?? $exception;
        }
    }

    public static function deadline(int $seconds): int
    {
        return hrtime(true) + max(1, $seconds) * 1_000_000_000;
    }

    public static function assertDeadlineCapable(PendingRequest $request): void
    {
        $handler = $request->getOptions()['handler'] ?? null;

        if (null === $handler) {
            $property = new ReflectionProperty(PendingRequest::class, 'handler');
            $handler = $property->getValue($request);
        }

        if (null === $handler) {
            if (extension_loaded('curl')) {
                return;
            }

            throw new LokiWriteException('Loki HTTP operations require the cURL extension for whole-operation deadlines.');
        }

        $type = get_debug_type($handler);

        if ($type !== self::GUZZLE_HANDLER_NAMESPACE.'CurlHandler'
            && $type !== self::GUZZLE_HANDLER_NAMESPACE.'CurlMultiHandler') {
            throw new LokiWriteException("Loki HTTP handler [{$type}] cannot enforce whole-operation deadlines.");
        }
    }

    public static function remainingSeconds(int $deadline, string $operation): float
    {
        $remaining = $deadline - hrtime(true);

        if ($remaining <= 0) {
            throw new LokiWriteException("Loki {$operation} exceeded its whole-transfer deadline.");
        }

        return max(0.001, $remaining / 1_000_000_000);
    }

    /** @return array{decode_content: false, on_headers: callable, progress: callable} */
    public static function transferOptions(int $deadline, string $operation, int $maximum = self::MAX_BYTES): array
    {
        return [
            'decode_content' => false,
            'on_headers'     => static function (mixed $response) use ($deadline, $operation, $maximum): void {
                self::remainingSeconds($deadline, $operation);
                $length = $response->getHeaderLine('Content-Length');

                if (ctype_digit($length) && (int) $length > $maximum) {
                    throw new LokiWriteException(self::decodeLimitMessage($operation, $maximum));
                }
            },
            'progress' => static function (int $downloadTotal, int $downloaded) use ($deadline, $operation, $maximum): void {
                self::remainingSeconds($deadline, $operation);

                if ($downloadTotal > $maximum || $downloaded > $maximum) {
                    throw new LokiWriteException(self::decodeLimitMessage($operation, $maximum));
                }
            },
        ];
    }

    public static function read(Response $response, string $operation, int $maximum = self::MAX_BYTES, ?int $deadline = null): string
    {
        $deadline ??= self::deadline(15);
        $length = $response->header('Content-Length');

        if (ctype_digit($length) && (int) $length > $maximum) {
            $response->close();
            throw new LokiWriteException("Loki {$operation} response exceeds the 2 MiB decode limit.");
        }

        $encoding = mb_strtolower((string) $response->header('Content-Encoding'));

        if ('' !== $encoding && 'identity' !== $encoding) {
            $response->close();
            throw new LokiWriteException("Loki {$operation} response uses an unsupported content encoding.");
        }

        $stream = $response->toPsrResponse()->getBody();
        $resource = $stream->detach();
        $body = '';

        try {
            if (! is_resource($resource)) {
                throw new LokiWriteException("Loki {$operation} response stream is unavailable.");
            }

            while (! feof($resource)) {
                $remaining = $maximum + 1 - mb_strlen($body, '8bit');

                if ($remaining <= 0) {
                    throw new LokiWriteException("Loki {$operation} response exceeds the 2 MiB decode limit.");
                }

                self::constrainRead($resource, $deadline, $operation);
                $chunk = @fread($resource, min(8192, $remaining));
                $metadata = stream_get_meta_data($resource);

                if (self::timedOut($metadata) || hrtime(true) >= $deadline) {
                    throw new LokiWriteException("Loki {$operation} exceeded its whole-transfer deadline.");
                }

                if (false === $chunk) {
                    throw new LokiWriteException("Loki {$operation} response failed during transfer.");
                }

                if ('' === $chunk && ! feof($resource)) {
                    throw new LokiWriteException("Loki {$operation} response stalled during transfer.");
                }

                $body .= $chunk;

                if (mb_strlen($body, '8bit') > $maximum) {
                    throw new LokiWriteException("Loki {$operation} response exceeds the 2 MiB decode limit.");
                }
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }

            $response->close();
        }

        return $body;
    }

    /** @param resource $stream */
    private static function constrainRead(mixed $stream, int $deadline, string $operation): void
    {
        $remaining = self::remainingSeconds($deadline, $operation);
        $seconds = (int) floor($remaining);
        $microseconds = max(1, (int) ceil(($remaining - $seconds) * 1_000_000));

        stream_set_timeout($stream, $seconds, $microseconds);
    }

    /** @param array<string, mixed> $metadata */
    private static function timedOut(array $metadata): bool
    {
        return true === ($metadata['timed_out'] ?? false);
    }

    private static function decodeLimitMessage(string $operation, int $maximum): string
    {
        $limit = self::MAX_BYTES === $maximum ? '2 MiB' : sprintf('%d bytes', $maximum);

        return "Loki {$operation} response exceeds the {$limit} decode limit.";
    }

    private static function originalFailure(Throwable $exception): ?Throwable
    {
        $cause = $exception->getPrevious();

        while ($cause instanceof Throwable) {
            if ($cause instanceof LokiWriteException) {
                return $cause;
            }

            $cause = $cause->getPrevious();
        }

        return null;
    }
}
