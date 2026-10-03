# Security Policy

## Supported versions

Security fixes are released for the latest minor version of the current major release (`1.x`).

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Email **tech@dotswan.com** with:

- a description of the issue and its impact,
- the affected Eagle Eye version, PHP version, Laravel version, and driver (`database`, `loki`, `multi`, ...),
- steps or a minimal proof of concept to reproduce it.

We acknowledge reports within a few working days, keep you informed while we investigate, and credit you in the release notes unless you prefer to stay anonymous. Please give us reasonable time to release a fix before disclosing the issue publicly.

## Scope notes

Eagle Eye stores audit data that can contain personal information. Reports about redaction bypasses, secret reveal without authorization or audit, erasure barriers that can be skipped, integrity-ledger forgery, or the retrieval API leaking entries past `LogAccessPolicyInterface` are all in scope.
