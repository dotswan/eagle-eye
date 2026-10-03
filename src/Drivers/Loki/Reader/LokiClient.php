<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki\Reader;

use DateTimeImmutable;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Drivers\Loki\LokiWriteException;
use Dotswan\EagleEye\Drivers\Loki\BoundedResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP wrapper over the Loki query API (v1). Returns decoded arrays;
 * interpretation lives in LokiDriver.
 *
 * @internal
 */
final readonly class LokiClient
{
    /**
     * @param array{type?: string, username?: ?string, password?: ?string} $auth
     */
    public function __construct(
        private string $endpoint,
        private ?string $tenant = null,
        private array $auth = ['type' => 'none', 'username' => null, 'password' => null],
        private int $timeout = 15,
    ) {
    }

    /**
     * @return array<string, mixed> decoded `data` block of a streams query
     */
    public function queryRange(
        string $logql,
        int $limit,
        string $startNs,
        string $endNs,
        string $direction,
        ?int $deadline = null,
    ): array {
        $deadline ??= $this->deadline();
        $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'query_range')->timeout(BoundedResponse::remainingSeconds($deadline, 'query_range'))->get($this->url('/loki/api/v1/query_range'), [
            'query'     => $logql,
            'limit'     => $limit,
            'start'     => $startNs,
            'end'       => $endNs,
            'direction' => $direction,
        ]));

        return $this->data($this->decoded($response, 'query_range', $deadline), 'query_range');
    }

    public function deadline(): int
    {
        return BoundedResponse::deadline($this->timeout);
    }

    /**
     * Instant metric query (e.g. count_over_time). Returns the `data.result`
     * vector/matrix entries.
     *
     * @return list<array<string, mixed>>
     */
    public function instant(string $expression, string $atNs): array
    {
        $deadline = BoundedResponse::deadline($this->timeout);
        $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'instant query')->timeout(BoundedResponse::remainingSeconds($deadline, 'instant query'))->get($this->url('/loki/api/v1/query'), [
            'query' => $expression,
            'time'  => $atNs,
        ]));

        $data = $this->data($this->decoded($response, 'instant query', $deadline), 'instant query');
        $result = $data['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * Metric range query (`query_range` with a step). Returns the matrix
     * entries of `data.result`.
     *
     * @return list<array<string, mixed>>
     */
    public function metricRange(string $expression, string $startNs, string $endNs, int $stepSeconds, ?int $deadline = null): array
    {
        $deadline ??= $this->deadline();
        $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'metric query')->timeout(BoundedResponse::remainingSeconds($deadline, 'metric query'))->get($this->url('/loki/api/v1/query_range'), [
            'query' => $expression,
            'start' => $startNs,
            'end'   => $endNs,
            'step'  => $stepSeconds,
        ]));
        $data = $this->data($this->decoded($response, 'metric query', $deadline), 'metric query');

        if ('matrix' !== ($data['resultType'] ?? null)) {
            throw new LokiWriteException('Loki metric query did not return a matrix result.');
        }

        $result = $data['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    public function ready(): bool
    {
        try {
            $deadline = BoundedResponse::deadline(3);
            $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'readiness', 4096)->timeout(BoundedResponse::remainingSeconds($deadline, 'readiness'))->get($this->url('/ready')));

            return $response->ok() && str_contains(BoundedResponse::read($response, 'readiness', 4096, $deadline), 'ready');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Best-effort asynchronous deletion request (compactor delete API).
     * Returns the localised request id when the endpoint answers.
     */
    public function requestDelete(string $logql, string $start, string $end): ?string
    {
        try {
            $deadline = BoundedResponse::deadline($this->timeout);
            $requests = $this->deleteRequests($deadline);
            $existing = $this->findDeleteRequest($requests, $logql, $start, $end, activeOnly: true);
            if (null !== $existing) {
                return $existing;
            }

            $knownRequestIds = array_values(array_filter(array_map(
                static fn (array $request): mixed => $request['request_id'] ?? null,
                $requests,
            ), is_string(...)));

            $parameters = http_build_query([
                'query' => $logql,
                'start' => $start,
                'end'   => $end,
            ], encoding_type: PHP_QUERY_RFC3986);
            $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'delete', 4096)->timeout(BoundedResponse::remainingSeconds($deadline, 'delete'))->post($this->url('/loki/api/v1/delete').'?'.$parameters));

            BoundedResponse::remainingSeconds($deadline, 'delete');
            $accepted = 204 === $response->status();
            $response->close();

            return $accepted
                ? $this->findDeleteRequest($this->deleteRequests($deadline), $logql, $start, $end, excludedRequestIds: $knownRequestIds)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function deleteRequests(int $deadline): array
    {
        $response = app(CaptureSuppression::class)->run(fn () => $this->request($deadline, 'delete receipts')
            ->timeout(BoundedResponse::remainingSeconds($deadline, 'delete receipts'))
            ->get($this->url('/loki/api/v1/delete')));
        if (! $response->successful()) {
            $response->close();
            throw new LokiWriteException('Loki delete receipts are unavailable.');
        }
        $requests = $this->decoded($response, 'delete receipts', $deadline);

        return array_values(array_filter($requests, is_array(...)));
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @param list<string>              $excludedRequestIds
     */
    private function findDeleteRequest(
        array $requests,
        string $logql,
        string $start,
        string $end,
        bool $activeOnly = false,
        array $excludedRequestIds = [],
    ): ?string {
        $from = (new DateTimeImmutable($start))->getTimestamp();
        $to = (new DateTimeImmutable($end))->getTimestamp();

        foreach ($requests as $request) {
            if (($request['query'] ?? null) === $logql
                && is_numeric($request['start_time'] ?? null)
                && is_numeric($request['end_time'] ?? null)
                && (float) $request['start_time'] <= $from
                && (float) $request['end_time'] >= $to
                && is_string($request['request_id'] ?? null)
                && 1 === preg_match('/^[a-f0-9]{8,64}$/D', $request['request_id'])
                && is_string($request['status'] ?? null)
                && '' !== $request['status']
                && (! $activeOnly || 'received' === $request['status'])
                && ! in_array($request['request_id'], $excludedRequestIds, true)) {
                return $request['request_id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(mixed $json, string $operation): array
    {
        if (! is_array($json) || 'success' !== ($json['status'] ?? null) || ! isset($json['data']) || ! is_array($json['data'])) {
            throw new LokiWriteException("Loki {$operation} response is not a success payload.");
        }

        return $json['data'];
    }

    /** @return array<string, mixed> */
    private function decoded(Response $response, string $operation, int $deadline): array
    {
        $body = BoundedResponse::read($response, $operation, deadline: $deadline);

        $decoded = json_decode($body, true, 32, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new LokiWriteException("Loki {$operation} response is not a JSON object.");
        }

        return $decoded;
    }

    private function url(string $path): string
    {
        return mb_rtrim($this->endpoint, '/').$path;
    }

    private function request(int $deadline, string $operation, int $maximum = BoundedResponse::MAX_BYTES): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeader('Accept-Encoding', 'identity')
            ->withOptions(BoundedResponse::transferOptions($deadline, $operation, $maximum))
            ->connectTimeout(max(1, min($this->timeout, 3)))
            ->timeout($this->timeout);

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
