<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Redaction\IpTruncator;
use Dotswan\EagleEye\Redaction\Strategies\EncryptStrategy;
use Dotswan\EagleEye\Redaction\Strategies\HashStrategy;
use Dotswan\EagleEye\Redaction\Strategies\MaskStrategy;
use Mockery\MockInterface;
use Dotswan\EagleEye\Security\HmacBlindIndex;
use Dotswan\EagleEye\Security\LaravelCipher;
use Illuminate\Encryption\Encrypter;

/** @return CipherInterface&MockInterface */
function mockCipher(): CipherInterface
{
    $cipher = Mockery::mock(CipherInterface::class);

    if (!$cipher instanceof CipherInterface) {
        throw new LogicException('Mock cipher contract unavailable.');
    }

    return $cipher;
}

/** @return BlindIndexInterface&MockInterface */
function mockBlindIndex(): BlindIndexInterface
{
    $index = Mockery::mock(BlindIndexInterface::class);

    if (!$index instanceof BlindIndexInterface) {
        throw new LogicException('Mock blind index contract unavailable.');
    }

    return $index;
}

it('masks strings in place and honors kept edges', function (): void {
    $strategy = new MaskStrategy('#', 2, 1);

    expect($strategy->apply('4111111111111111', 'payload.card'))->toBe('41#############1')
        ->and((new MaskStrategy())->apply('secret', 'x'))->toBe('******')
        ->and((new MaskStrategy())->apply(6021, 'x'))->toBe('****')
        ->and((new MaskStrategy())->apply('', 'x'))->toBe('');
});

it('hashes deterministically through the blind index', function (): void {
    $index = new HmacBlindIndex('app-key');
    $strategy = new HashStrategy($index);

    $hashed = $strategy->apply('DE02100100100005123456', 'x');

    expect($hashed)->toBe($index->hash('DE02100100100005123456'));
    expect($hashed)->not->toBe('DE02100100100005123456');
});

it('encrypts into a blind indexed secret reference', function (): void {
    $key = str_repeat('k', 32);
    $cipher = new LaravelCipher(new Encrypter($key, 'AES-256-CBC'), 'base64:'.base64_encode($key), 'AES-256-CBC');
    $index = new HmacBlindIndex('app-key');
    $strategy = new EncryptStrategy($cipher, $index);

    $secret = $strategy->apply('DE02100100100005123456', 'payload.iban');

    expect($secret)->toBeInstanceOf(SecretRef::class);

    if (! $secret instanceof SecretRef) {
        return;
    }

    expect($secret->blindIndex)->toBe($index->hash('DE02100100100005123456'))
        ->and($cipher->decrypt($secret))->toBe('DE02100100100005123456');
});

it('truncates ip addresses to whole bytes and partial bits', function (): void {
    expect(IpTruncator::truncate('145.23.11.9', 24, 48))->toBe('145.23.11.0')
        ->and(IpTruncator::truncate('145.23.11.9', 0, 0))->toBe('0.0.0.0')
        ->and(IpTruncator::truncate('145.23.11.9', 16, 48))->toBe('145.23.0.0')
        ->and(IpTruncator::truncate('2001:db8:85a3:1234:5678:8a2e:0370:7334', 24, 48))->toBe('2001:db8:85a3::')
        ->and(IpTruncator::truncate('not-an-ip', 24, 48))->toBeNull();
});

it('omits oversized sensitive input before encryption or blind indexing', function (): void {
    $cipher = mockCipher();
    $index = mockBlindIndex();
    $cipher->shouldNotReceive('encrypt');
    $index->shouldNotReceive('hash');
    $strategy = new EncryptStrategy($cipher, $index);

    expect($strategy->apply(str_repeat('private', 100000), 'payload.password'))->toBe('[omitted]');
});

it('does not invoke serializers while preparing a secret', function (): void {
    $cipher = mockCipher();
    $index = mockBlindIndex();
    $cipher->shouldNotReceive('encrypt');
    $index->shouldNotReceive('hash');
    $object = new class () implements JsonSerializable {
        public bool $called = false;

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return 'sensitive';
        }
    };

    expect((new EncryptStrategy($cipher, $index))->apply($object, 'payload.password'))->toBe('[omitted]')
        ->and($object->called)->toBeFalse();
});
