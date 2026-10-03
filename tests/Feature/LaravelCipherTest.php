<?php

declare(strict_types=1);

use Dotswan\EagleEye\Security\LaravelCipher;
use Illuminate\Encryption\Encrypter;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;

it('encrypts and decrypts secrets using the Laravel application key', function (): void {
    $cipher = app(CipherInterface::class);
    $secret = $cipher->encrypt('NL91ABNA0417164300');

    expect(str_contains($secret->ciphertext, 'NL91ABNA0417164300'))->toBeFalse()
        ->and($secret->algorithm)->toBe('laravel:AES-256-CBC')
        ->and($cipher->decrypt($secret))->toBe('NL91ABNA0417164300');
});

it('decrypts secrets across application key rotation', function (): void {
    $oldKey = random_bytes(32);
    $newKey = random_bytes(32);

    $before = new LaravelCipher(
        new Encrypter($oldKey, 'AES-256-CBC'),
        'base64:'.base64_encode($oldKey),
        'AES-256-CBC',
    );
    $secret = $before->encrypt('confidential-value');

    $rotated = new Encrypter($newKey, 'AES-256-CBC');
    $rotated->previousKeys([$oldKey]);

    $after = new LaravelCipher(
        $rotated,
        'base64:'.base64_encode($newKey),
        'AES-256-CBC',
    );

    $fresh = $after->encrypt('fresh');

    expect($after->decrypt($secret))->toBe('confidential-value')
        ->and($fresh->keyFingerprint)->not->toBe($secret->keyFingerprint);
});

it('creates deterministic blind indexes', function (): void {
    $index = app(BlindIndexInterface::class);

    $hash = $index->hash('subject');

    expect($hash)->toHaveLength(64)
        ->and($hash)->toBe($index->hash('subject'))
        ->and($hash === $index->hash('different'))->toBeFalse();
});
