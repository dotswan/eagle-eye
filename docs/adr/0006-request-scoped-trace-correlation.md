# ADR 0006: Request-scoped trace correlation

Status: Accepted

Drafts no longer mint their own trace. A scoped `TraceManager` holds one `TraceContext` per HTTP request, console command, or queued job. The recorder stamps drafts from it, and the id of the previous entry becomes the next entry's `causation_id`, producing a causal chain instead of isolated records.

Inbound HTTP requests adopt the W3C `traceparent` header when present; queue payloads carry the trace via `Queue::createPayloadUsing()` and a `JobProcessing` listener restores it in the worker. A global job middleware class was rejected: Laravel has no global job-middleware registration, so a listener is the only zero-developer-action mechanism, and shipping both would duplicate one responsibility.

`TraceManager` binds as a container `scoped` instance, so Octane and long-running workers reset it between units of work without coupling the package to Octane.
