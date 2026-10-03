/**
 * Eagle Eye activity-log API contract (envelope schema_version 1).
 *
 * Mirrors Dotswan\EagleEye\Http\Resources\ActivityEntryResource — the drift
 * test in tests/Feature/Api/TypescriptContractTest.php fails when these
 * shapes diverge. Regenerate the fixture with
 * EAGLE_EYE_UPDATE_SNAPSHOTS=1 vendor/bin/pest tests/Feature/Api/EnvelopeSnapshotTest.php
 */

export interface EagleEyeActor {
    type: string;
    id: string | null;
    label: string | null;
    url: string | null;
}

export interface EagleEyeSubject {
    type: string;
    id: string;
    label: string | null;
    class: string | null;
    url: string | null;
}

export interface EagleEyeTrace {
    trace_id: string;
    correlation_id: string;
    causation_id: string | null;
    parent_span_id: string | null;
}

export interface EagleEyeRequestSnapshot {
    method: string | null;
    url: string | null;
    route: string | null;
    status_code: number | null;
    duration_ms: number | null;
    ip: string | null;
    user_agent: string | null;
}

export interface EagleEyeChange {
    path: string;
    label: string;
    before: unknown;
    after: unknown;
}

export interface EagleEyeActivityEntry {
    id: string;
    schema_version: number;
    logged_at: string;
    channel: string;
    action: string;
    severity: 'debug' | 'info' | 'notice' | 'warning' | 'error' | 'critical' | 'alert' | 'emergency';
    title: string;
    summary: string | null;
    actor: EagleEyeActor | null;
    subject: EagleEyeSubject | null;
    trace: EagleEyeTrace;
    tags: string[];
    request: EagleEyeRequestSnapshot | null;
    changes: EagleEyeChange[];
    payload: Record<string, unknown>;
    sealed: string[];
    payload_bytes: number;
    environment: string;
    hostname: string;
    memory_kb: number | null;
    integrity_hash: string | null;
    created_at: string | null;
}

export interface EagleEyeTraceNode {
    entry: EagleEyeActivityEntry;
    children: EagleEyeTraceNode[];
}

export interface EagleEyeMeta {
    driver: string;
    capabilities: string[];
    count?: { value: number; exact: boolean };
}

export interface EagleEyeLinks {
    next: string | null;
}

export interface EagleEyeListResponse {
    data: EagleEyeActivityEntry[];
    meta: EagleEyeMeta;
    links: EagleEyeLinks;
}

export interface EagleEyeShowResponse {
    data: EagleEyeActivityEntry;
    meta: EagleEyeMeta;
}

export interface EagleEyeTraceResponse {
    data: EagleEyeTraceNode[];
    meta: EagleEyeMeta;
}

export interface EagleEyeStatsResponse {
    data: { group_by: string; interval: string; groups: Record<string, number> };
}

export interface EagleEyeTagsResponse {
    data: string[];
    meta: EagleEyeMeta;
}

export interface EagleEyeRevealResponse {
    data: { path: string; value: string };
    meta: EagleEyeMeta;
}
