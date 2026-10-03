<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

final readonly class Subject
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $label = null,
        public ?string $className = null,
        public ?string $url = null,
    ) {
    }

    /** @return array{type: string, id: string, label: ?string, class: ?string, url: ?string} */
    public function toArray(): array
    {
        return [
            'type'  => $this->type,
            'id'    => $this->id,
            'label' => $this->label,
            'class' => $this->className,
            'url'   => $this->url,
        ];
    }
}
