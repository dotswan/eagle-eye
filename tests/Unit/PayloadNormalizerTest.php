<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\PayloadBudget;
use Dotswan\EagleEye\Resolution\PayloadNormalizer;

it('normalizes unsafe payload values without throwing', function (): void {
    $resource = fopen('php://memory', 'r');
    $normalizer = new PayloadNormalizer(new PayloadBudget(maxStringLength: 8));

    try {
        $payload = $normalizer->normalize([
            'closure'  => static fn (): string => 'secret',
            'resource' => $resource,
            'invalid'  => "valid\xB1text",
            'long'     => '1234567890',
        ]);
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    expect($payload)->toBeArray()
        ->and($payload['closure'])->toBe('[closure]')
        ->and($payload['resource'])->toBe('[resource:stream]')
        ->and($payload['invalid'])->toBeString()
        ->and($payload['long'])->toBe('12345...');
});

it('enforces the string limit in bytes for non-ascii content', function (): void {
    $normalizer = new PayloadNormalizer(new PayloadBudget(maxStringLength: 100));

    $payload = $normalizer->normalize(['name' => str_repeat('ü', 100)]);

    expect($payload)->toBeArray()
        ->and(mb_strlen($payload['name'], '8bit'))->toBeLessThanOrEqual(100);
});

it('enforces depth item node and byte limits', function (): void {
    $normalizer = new PayloadNormalizer(new PayloadBudget(
        maxBytes: 64,
        maxDepth: 2,
        maxArrayItems: 2,
        maxStringLength: 32,
        maxNodes: 5,
    ));

    $payload = $normalizer->normalize([
        'nested' => ['value' => ['too_deep' => true]],
        'items'  => [1, 2, 3],
        'extra'  => str_repeat('x', 100),
    ]);

    expect($payload)->toBeArray()
        ->and(mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR), '8bit'))->toBeLessThanOrEqual(64);
});

it('normalizes stringable and json serializable values but never advances generators', function (): void {
    $calls = 0;
    $stringable = new class ($calls) implements Stringable {
        public function __construct(public int &$calls)
        {
        }

        public function __toString(): string
        {
            $this->calls++;

            return '9f8c0e6a-1b2d-4f3a-9c4e-5d6f7a8b9c0d';
        }
    };
    $serializable = new class () implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['id' => 'abc', 'active' => true];
        }
    };
    $generator = (function (): Generator {
        yield 'must not execute';
    })();

    $payload = (new PayloadNormalizer())->normalize([
        'stringable'   => $stringable,
        'serializable' => $serializable,
        'generator'    => $generator,
    ]);

    expect($calls)->toBe(1)
        ->and($payload['stringable'])->toBe('9f8c0e6a-1b2d-4f3a-9c4e-5d6f7a8b9c0d')
        ->and($payload['serializable'])->toBe(['id' => 'abc', 'active' => true])
        ->and($payload['generator'])->toBe('[object]');
});

it('normalizes json serializable values against the byte budget', function (): void {
    $serializable = new class () implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['value' => str_repeat('x', 1000)];
        }
    };

    $payload = (new PayloadNormalizer(new PayloadBudget(maxStringLength: 8)))->normalize(['object' => $serializable]);

    expect($payload['object'])->toBe(['value' => 'xxxxx...']);
});

it('truncates oversized stringable values', function (): void {
    $stringable = new class () implements Stringable {
        public function __toString(): string
        {
            return str_repeat('x', 1000);
        }
    };

    $payload = (new PayloadNormalizer(new PayloadBudget(maxStringLength: 8)))->normalize(['object' => $stringable]);

    expect($payload['object'])->toBe('xxxxx...');
});

it('falls back to object marker when tostring throws', function (): void {
    $stringable = new class () implements Stringable {
        public function __toString(): string
        {
            throw new RuntimeException('cannot render');
        }
    };

    $payload = (new PayloadNormalizer())->normalize(['object' => $stringable]);

    expect($payload['object'])->toBe('[object]');
});

it('bounds oversized keys without renaming sensitive fields', function (): void {
    $payload = (new PayloadNormalizer(new PayloadBudget(maxBytes: 256, maxStringLength: 32)))
        ->normalize([str_repeat('password', 1000) => 'secret', 'safe' => 'retained']);

    expect(mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR), '8bit'))->toBeLessThanOrEqual(256)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('enforces the total byte budget on scalar strings', function (): void {
    $payload = (new PayloadNormalizer(new PayloadBudget(maxBytes: 16, maxStringLength: 4096)))
        ->normalize(str_repeat('x', 1000));

    expect(mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR), '8bit'))->toBeLessThanOrEqual(16);
});

it('stops retaining sibling values when the node budget is exhausted', function (): void {
    $payload = (new PayloadNormalizer(new PayloadBudget(maxArrayItems: 1000, maxNodes: 3)))
        ->normalize(array_fill(0, 1000, 'value'));

    expect(count($payload))->toBeLessThanOrEqual(3);
});

it('clips invalid utf8 before allocating normalization copies', function (): void {
    $input = str_repeat("\xB1", 10 * 1024 * 1024);
    $normalizer = new PayloadNormalizer();
    $baseline = memory_get_usage();
    memory_reset_peak_usage();

    $payload = $normalizer->normalize(['value' => $input]);
    $incrementalPeak = memory_get_peak_usage() - $baseline;

    expect($incrementalPeak)->toBeLessThan(4 * 1024 * 1024)
        ->and(mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR), '8bit'))->toBeLessThanOrEqual(65536)
        ->and(mb_strlen($payload['value'], '8bit'))->toBeLessThanOrEqual(4096);
});

it('bounds cyclic payloads without consuming the complete graph', function (): void {
    $input = ['safe' => 'value'];
    $input['cycle'] = &$input;

    $payload = (new PayloadNormalizer(new PayloadBudget(maxDepth: 4, maxNodes: 20)))->normalize($input);

    expect(json_encode($payload, JSON_THROW_ON_ERROR))->toContain('[max-depth]')
        ->and($payload['__truncated'])->toBeTrue();
});

it('retains bounded memory across one hundred thousand payloads', function (): void {
    $normalizer = new PayloadNormalizer();
    $input = ['name' => str_repeat('x', 100), 'nested' => ['safe' => true]];
    $normalizer->normalize($input);
    $baseline = memory_get_usage();
    memory_reset_peak_usage();

    for ($i = 0; $i < 100000; $i++) {
        $normalizer->normalize($input);
    }

    expect(memory_get_usage() - $baseline)->toBeLessThan(1024 * 1024)
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(4 * 1024 * 1024);
})->group('performance');

it('rejects nonpositive payload budgets', function (): void {
    new PayloadBudget(maxNodes: 0);
})->throws(InvalidArgumentException::class, 'Payload budget values must be positive');
