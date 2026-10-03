<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use DateTimeImmutable;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\RedactionMode;
use Illuminate\Support\Str;
use JsonException;
use LogicException;

final class ActivityDraft
{
    public string $id;

    public int $schemaVersion = 1;

    public DateTimeImmutable $loggedAt;

    public string $title;

    public ?string $summary = null;

    public ?Actor $actor = null;

    public ?Subject $subject = null;

    public ?TraceContext $trace = null;

    public TagBag $tags;

    public ?RequestSnapshot $request = null;

    /** @var array<string, mixed> */
    public array $payload = [];

    /** @var array<string, SecretRef> */
    public array $secrets = [];

    /** @var list<\Dotswan\EagleEye\Enums\RequestPart> */
    public array $withheldParts = [];

    public ?string $queryFrameKey = null;

    /** @var array<string, RedactionMode> */
    public array $fieldModes = [];

    public int $payloadBytes = 0;

    public string $environment = 'unknown';

    public string $hostname = 'unknown';

    public ?int $memoryKb = null;

    public ?string $integrityHash = null;

    public DateTimeImmutable $createdAt;

    public function __construct(
        public ActivityChannel $channel,
        public string $action,
        public ActivitySeverity $severity = ActivitySeverity::Info,
    ) {
        $this->id = (string) Str::ulid();
        $this->loggedAt = new DateTimeImmutable();
        $this->createdAt = $this->loggedAt;
        $this->tags = new TagBag();
        $this->title = $action;
    }

    public static function make(
        string $action,
        ActivityChannel $channel = ActivityChannel::Custom,
        ActivitySeverity $severity = ActivitySeverity::Info,
    ): self {
        return new self($channel, $action, $severity);
    }

    /** @param array<string, mixed> $payload */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function withActor(?Actor $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function withSubject(?Subject $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function withTrace(TraceContext $trace): self
    {
        $this->trace = $trace;

        return $this;
    }

    /** @param iterable<array-key, string> $tags */
    public function withTags(iterable $tags): self
    {
        $this->tags->merge($tags);

        return $this;
    }

    /** @throws JsonException */
    public function freeze(): ActivityEntry
    {
        if (!$this->trace instanceof TraceContext) {
            throw new LogicException('Activity draft must be stamped with a trace context before freezing.');
        }

        $payloadBytes = mb_strlen(json_encode($this->payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), '8bit');

        return new ActivityEntry(
            id: $this->id,
            schemaVersion: $this->schemaVersion,
            loggedAt: $this->loggedAt,
            channel: $this->channel,
            action: $this->action,
            severity: $this->severity,
            title: $this->title,
            summary: $this->summary,
            actor: $this->actor,
            subject: $this->subject,
            trace: $this->trace,
            tags: $this->tags->all(),
            request: $this->request,
            payload: $this->payload,
            secrets: $this->secrets,
            payloadBytes: $payloadBytes,
            environment: $this->environment,
            hostname: $this->hostname,
            memoryKb: $this->memoryKb,
            integrityHash: $this->integrityHash,
            createdAt: $this->createdAt,
        );
    }
}
