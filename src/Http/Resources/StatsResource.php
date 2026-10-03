<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{groups: array<string, int>, group_by: string, interval: string} $resource
 */
final class StatsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'group_by' => $this->resource['group_by'],
            'interval' => $this->resource['interval'],
            'groups'   => $this->resource['groups'],
        ];
    }
}
