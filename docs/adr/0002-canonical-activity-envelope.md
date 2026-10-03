# ADR 0002: Canonical activity envelope

Status: Accepted

All drivers exchange one immutable `ActivityEntry`. Indexed scalar metadata remains top-level. Variable channel data remains in `payload`; encrypted values remain in `secrets`.

Storage implementations must preserve `ActivityEntry::toStorage()` and `ActivityEntry::fromStorage()` round trips.
