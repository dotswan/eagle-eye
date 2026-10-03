<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

/** @internal Consumed by the retrieval API trace tree endpoint. */
final readonly class TraceTree
{
    /** @param list<TraceTree> $children */
    public function __construct(
        public ActivityEntry $entry,
        public array $children = [],
    ) {
    }

    /** @return array{entry: array<string, mixed>, children: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'entry'    => $this->entry->toStorage(),
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    /** @return list<ActivityEntry> */
    public function flatten(): array
    {
        $entries = [$this->entry];

        foreach ($this->children as $child) {
            array_push($entries, ...$child->flatten());
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function toTimeline(): array
    {
        return array_map(
            static fn (ActivityEntry $entry): array => $entry->toStorage(),
            $this->flatten(),
        );
    }

    /**
     * Assemble a nested causal tree from a flat, time-ordered entry list:
     * children are linked by causation_id to their parent's entry id; entries
     * whose parent is absent from the list become roots.
     *
     * @param list<ActivityEntry> $entries
     * @return list<TraceTree>
     */
    public static function assemble(array $entries): array
    {
        $entries = array_slice($entries, 0, 500);
        $nodes = [];
        $childrenOf = [];

        foreach ($entries as $entry) {
            $nodes[$entry->id] = $entry;
            $childrenOf[$entry->trace->causationId ?? ''][] = $entry;
        }

        $built = [];
        $visiting = [];
        $build = static function (ActivityEntry $entry, int $depth = 0) use (&$build, &$childrenOf, &$built, &$visiting): self {
            if ($depth >= 32 || isset($visiting[$entry->id])) {
                return new self($entry);
            }

            $visiting[$entry->id] = true;
            $children = [];

            foreach ($childrenOf[$entry->id] ?? [] as $child) {
                if (! isset($built[$child->id])) {
                    $children[] = $build($child, $depth + 1);
                }
            }

            unset($visiting[$entry->id]);
            $built[$entry->id] = true;

            return new self($entry, $children);
        };

        $roots = [];

        foreach ($entries as $entry) {
            $causation = $entry->trace->causationId;

            if (null === $causation || ! isset($nodes[$causation])) {
                $roots[] = $build($entry);
            }
        }

        foreach ($entries as $entry) {
            if (! isset($built[$entry->id])) {
                $roots[] = $build($entry);
            }
        }

        return $roots;
    }
}
