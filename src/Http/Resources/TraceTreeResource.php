<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Resources;

use Dotswan\EagleEye\Data\TraceTree;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property TraceTree $resource */
final class TraceTreeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'entry'    => (new ActivityEntryResource($this->resource->entry))->toArray($request),
            'children' => array_map(
                static fn (TraceTree $child): array => (new TraceTreeResource($child))->toArray($request),
                $this->resource->children,
            ),
        ];
    }
}
