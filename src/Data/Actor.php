<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

final readonly class Actor
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $label = null,
        public ?string $url = null,
    ) {
    }

    /** @return array{type: string, id: ?string, label: ?string, url: ?string} */
    public function toArray(): array
    {
        return [
            'type'  => $this->type,
            'id'    => $this->id,
            'label' => $this->label,
            'url'   => $this->url,
        ];
    }
}
