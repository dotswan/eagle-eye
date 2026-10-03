<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum ActivityLogField: string
{
    case Id = 'id';
    case SchemaVersion = 'schema_version';
    case LoggedAt = 'logged_at';
    case Channel = 'channel';
    case Action = 'action';
    case Severity = 'severity';
    case Title = 'title';
    case Summary = 'summary';
    case ActorType = 'actor_type';
    case ActorId = 'actor_id';
    case ActorLabel = 'actor_label';
    case ActorUrl = 'actor_url';
    case SubjectType = 'subject_type';
    case SubjectId = 'subject_id';
    case SubjectLabel = 'subject_label';
    case SubjectClass = 'subject_class';
    case SubjectUrl = 'subject_url';
    case TraceId = 'trace_id';
    case CorrelationId = 'correlation_id';
    case CausationId = 'causation_id';
    case ParentSpanId = 'parent_span_id';
    case Tags = 'tags';
    case Ip = 'ip';
    case UserAgent = 'user_agent';
    case Method = 'method';
    case Url = 'url';
    case Route = 'route';
    case StatusCode = 'status_code';
    case DurationMs = 'duration_ms';
    case MemoryKb = 'memory_kb';
    case Environment = 'environment';
    case Hostname = 'hostname';
    case Payload = 'payload';
    case Secrets = 'secrets';
    case PayloadBytes = 'payload_bytes';
    case IntegrityHash = 'integrity_hash';
    case CreatedAt = 'created_at';
}
