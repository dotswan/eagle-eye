<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use InvalidArgumentException;

final class TagBag
{
    /** @var list<string> */
    private array $tags = [];

    /** @param iterable<array-key, string> $tags */
    public function __construct(iterable $tags = [], private readonly int $limit = 24)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Tag limit must be positive.');
        }

        $this->merge($tags);
    }

    public function add(string ...$tags): self
    {
        return $this->merge($tags);
    }

    /** @param iterable<array-key, string> $tags */
    public function merge(iterable $tags): self
    {
        $limit = min($this->limit, 50);

        if (count($this->tags) >= $limit) {
            return $this;
        }

        $visited = 0;

        foreach ($tags as $tag) {
            if (++$visited > 2000) {
                break;
            }

            if (mb_strlen($tag, '8bit') > 256 || ! mb_check_encoding($tag, 'UTF-8')) {
                continue;
            }

            $normalized = mb_trim($tag);

            if ('' === $normalized || in_array($normalized, $this->tags, true)) {
                continue;
            }

            $this->tags[] = $normalized;

            if (count($this->tags) >= $limit) {
                break;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->tags;
    }
}
