# ADR 0007: Persistent integrity and retention

Status: Accepted

The initial schema stores one ordered integrity ledger in bounded segments. Writers lock a persistent state row, link each segment to its predecessor, and authenticate segment state and entry lifecycle with HMACs derived from the dedicated Eagle Eye security key. Verification treats missing required hashes, ledger entries, segments, invalid anchors, changed metadata, and changed content as failures while retaining a bounded diagnostic sample. This detects database changes only while the dedicated key and ledger are trusted; externally immutable anchors remain an operator responsibility for a stronger administrator threat model.

Retention applies the most specific base rule in severity, channel, default order. Tag rules may shorten but never extend that base. SQL-like drivers execute scoped deletion. Loki emits representable retention configuration and rejects tag policies it cannot enforce. Subject erasure commits a keyed-HMAC replay barrier before backend deletion and records authenticated requested/final receipts; Loki acceptance remains pending until its compactor completes both deletion requests.
