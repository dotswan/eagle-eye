<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivityLogField;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use JsonSerializable;
use UnexpectedValueException;

final readonly class ActivityEntry implements JsonSerializable
{
    /**
     * @param list<string> $tags
     * @param array<string, mixed> $payload
     * @param array<string, SecretRef> $secrets
     */
    public function __construct(
        public string $id,
        public int $schemaVersion,
        public DateTimeImmutable $loggedAt,
        public ActivityChannel $channel,
        public string $action,
        public ActivitySeverity $severity,
        public string $title,
        public ?string $summary,
        public ?Actor $actor,
        public ?Subject $subject,
        public TraceContext $trace,
        public array $tags,
        public ?RequestSnapshot $request,
        public array $payload,
        public array $secrets,
        public int $payloadBytes,
        public string $environment,
        public string $hostname,
        public ?int $memoryKb = null,
        public ?string $integrityHash = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toStorage(): array
    {
        $secrets = array_map(
            static fn (SecretRef $secret): array => $secret->toArray(),
            $this->secrets,
        );

        return [
            ActivityLogField::Id->value            => $this->id,
            ActivityLogField::SchemaVersion->value => $this->schemaVersion,
            ActivityLogField::LoggedAt->value      => $this->formatDate($this->loggedAt),
            ActivityLogField::Channel->value       => $this->channel->value,
            ActivityLogField::Action->value        => $this->action,
            ActivityLogField::Severity->value      => $this->severity->value,
            ActivityLogField::Title->value         => $this->title,
            ActivityLogField::Summary->value       => $this->summary,
            ActivityLogField::ActorType->value     => $this->actor?->type,
            ActivityLogField::ActorId->value       => $this->actor?->id,
            ActivityLogField::ActorLabel->value    => $this->actor?->label,
            ActivityLogField::ActorUrl->value      => $this->actor?->url,
            ActivityLogField::SubjectType->value   => $this->subject?->type,
            ActivityLogField::SubjectId->value     => $this->subject?->id,
            ActivityLogField::SubjectLabel->value  => $this->subject?->label,
            ActivityLogField::SubjectClass->value  => $this->subject?->className,
            ActivityLogField::SubjectUrl->value    => $this->subject?->url,
            ActivityLogField::TraceId->value       => $this->trace->traceId,
            ActivityLogField::CorrelationId->value => $this->trace->correlationId,
            ActivityLogField::CausationId->value   => $this->trace->causationId,
            ActivityLogField::ParentSpanId->value  => $this->trace->parentSpanId,
            ActivityLogField::Tags->value          => $this->tags,
            ActivityLogField::Ip->value            => $this->request?->ip,
            ActivityLogField::UserAgent->value     => $this->request?->userAgent,
            ActivityLogField::Method->value        => $this->request?->method,
            ActivityLogField::Url->value           => $this->request?->url,
            ActivityLogField::Route->value         => $this->request?->route,
            ActivityLogField::StatusCode->value    => $this->request?->statusCode,
            ActivityLogField::DurationMs->value    => $this->request?->durationMs,
            ActivityLogField::MemoryKb->value      => $this->memoryKb,
            ActivityLogField::Environment->value   => $this->environment,
            ActivityLogField::Hostname->value      => $this->hostname,
            ActivityLogField::Payload->value       => $this->payload,
            ActivityLogField::Secrets->value       => $secrets,
            ActivityLogField::PayloadBytes->value  => $this->payloadBytes,
            ActivityLogField::IntegrityHash->value => $this->integrityHash,
            ActivityLogField::CreatedAt->value     => $this->formatDate($this->createdAt ?? $this->loggedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromStorage(array $row): self
    {
        $actorType = self::nullableString($row, ActivityLogField::ActorType);
        $subjectType = self::nullableString($row, ActivityLogField::SubjectType);
        $method = self::nullableString($row, ActivityLogField::Method);
        $url = self::nullableString($row, ActivityLogField::Url);
        $route = self::nullableString($row, ActivityLogField::Route);
        $statusCode = self::nullableInt($row, ActivityLogField::StatusCode);
        $durationMs = self::nullableInt($row, ActivityLogField::DurationMs);
        $ip = self::nullableString($row, ActivityLogField::Ip);
        $userAgent = self::nullableString($row, ActivityLogField::UserAgent);
        $secretRows = self::arrayValue($row[ActivityLogField::Secrets->value] ?? []);
        $secrets = [];

        foreach ($secretRows as $path => $secret) {
            if (is_string($path) && is_array($secret)) {
                $secrets[$path] = SecretRef::fromArray(self::secretShape($secret));
            }
        }

        return new self(
            id: (string) $row[ActivityLogField::Id->value],
            schemaVersion: (int) $row[ActivityLogField::SchemaVersion->value],
            loggedAt: self::dateValue($row[ActivityLogField::LoggedAt->value]),
            channel: ActivityChannel::from((string) $row[ActivityLogField::Channel->value]),
            action: (string) $row[ActivityLogField::Action->value],
            severity: ActivitySeverity::from((string) $row[ActivityLogField::Severity->value]),
            title: (string) $row[ActivityLogField::Title->value],
            summary: self::nullableString($row, ActivityLogField::Summary),
            actor: null === $actorType ? null : new Actor(
                type: $actorType,
                id: self::nullableString($row, ActivityLogField::ActorId),
                label: self::nullableString($row, ActivityLogField::ActorLabel),
                url: self::nullableString($row, ActivityLogField::ActorUrl),
            ),
            subject: null === $subjectType ? null : new Subject(
                type: $subjectType,
                id: (string) ($row[ActivityLogField::SubjectId->value] ?? ''),
                label: self::nullableString($row, ActivityLogField::SubjectLabel),
                className: self::nullableString($row, ActivityLogField::SubjectClass),
                url: self::nullableString($row, ActivityLogField::SubjectUrl),
            ),
            trace: new TraceContext(
                traceId: self::requiredString($row, ActivityLogField::TraceId),
                correlationId: self::requiredString($row, ActivityLogField::CorrelationId),
                causationId: self::nullableString($row, ActivityLogField::CausationId),
                parentSpanId: self::nullableString($row, ActivityLogField::ParentSpanId),
            ),
            tags: self::stringList($row[ActivityLogField::Tags->value] ?? []),
            request: null === $method && null === $url && null === $route && null === $statusCode && null === $durationMs && null === $ip && null === $userAgent
                ? null
                : new RequestSnapshot($method, $url, $route, $statusCode, $durationMs, $ip, $userAgent),
            payload: self::arrayValue($row[ActivityLogField::Payload->value] ?? []),
            secrets: $secrets,
            payloadBytes: (int) ($row[ActivityLogField::PayloadBytes->value] ?? 0),
            environment: (string) ($row[ActivityLogField::Environment->value] ?? 'unknown'),
            hostname: (string) ($row[ActivityLogField::Hostname->value] ?? 'unknown'),
            memoryKb: self::nullableInt($row, ActivityLogField::MemoryKb),
            integrityHash: self::nullableString($row, ActivityLogField::IntegrityHash),
            createdAt: self::dateValue($row[ActivityLogField::CreatedAt->value] ?? $row[ActivityLogField::LoggedAt->value]),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toStorage();
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, ActivityLogField $field): ?string
    {
        $value = $row[$field->value] ?? null;

        return null === $value ? null : (string) $value;
    }

    /** @param array<string, mixed> $row */
    private static function requiredString(array $row, ActivityLogField $field): string
    {
        $value = self::nullableString($row, $field);

        if (null === $value || '' === $value) {
            throw new UnexpectedValueException("Required activity field [{$field->value}] is missing.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, ActivityLogField $field): ?int
    {
        $value = $row[$field->value] ?? null;

        return null === $value ? null : (int) $value;
    }

    private static function dateValue(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }

    /** @return array<array-key, mixed> */
    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter(
            self::arrayValue($value),
            is_string(...),
        ));
    }

    /**
     * @param array<array-key, mixed> $secret
     * @return array{alg: string, key_fingerprint: string, ciphertext: string, bi: ?string}
     */
    private static function secretShape(array $secret): array
    {
        return [
            'alg'             => (string) ($secret['alg'] ?? ''),
            'key_fingerprint' => (string) ($secret['key_fingerprint'] ?? ''),
            'ciphertext'      => (string) ($secret['ciphertext'] ?? ''),
            'bi'              => isset($secret['bi']) ? (string) $secret['bi'] : null,
        ];
    }
}
