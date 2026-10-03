<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\TagBag;

it('normalizes deduplicates and limits tags', function (): void {
    $tags = new TagBag([' gdpr ', 'gdpr', '', 'campaign:42', 'ignored'], 2);

    expect($tags->all())->toBe(['gdpr', 'campaign:42']);
});

it('stops reading tag iterables once the retained limit is reached', function (): void {
    $visited = 0;
    $source = (function () use (&$visited): Generator {
        for ($index = 0; $index < 10000; $index++) {
            $visited++;
            yield 'tag:'.$index;
        }
    })();
    $tags = new TagBag($source, 2);

    expect($tags->all())->toBe(['tag:0', 'tag:1'])
        ->and($visited)->toBe(2);
});

it('omits oversized tags and caps retained tags independently of configuration', function (): void {
    $tags = new TagBag([str_repeat('s', 10 * 1024 * 1024), ...array_map(static fn (int $index): string => 'tag:'.$index, range(1, 100))], 100);

    expect($tags->all())->toHaveCount(50)
        ->and($tags->all()[0])->toBe('tag:1');
});

it('rejects nonpositive tag limits', function (): void {
    expect(fn (): TagBag => new TagBag([], 0))->toThrow(InvalidArgumentException::class);
});

it('stops merging once full and bounds hostile iterables', function (): void {
    $full = new TagBag(['kept'], 1);
    $full->add('ignored');

    $visited = 0;
    $hostile = (function () use (&$visited): Generator {
        while (true) {
            $visited++;
            yield '';
        }
    })();
    $bounded = new TagBag($hostile);

    expect($full->all())->toBe(['kept'])
        ->and($bounded->all())->toBe([])
        ->and($visited)->toBe(2001);
});

it('rejects invalid UTF-8 tags', function (): void {
    expect((new TagBag(["\xB1\x31"]))->all())->toBe([]);
});
