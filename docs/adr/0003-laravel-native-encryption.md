# ADR 0003: Laravel-native encryption

Status: Accepted

Default encryption uses Laravel's `Encrypter` and application key. Blind indexes use HMAC-SHA256 with that key. No external key service is required.

Consumers may replace `CipherInterface` and `BlindIndexInterface` bindings without changing package code.
