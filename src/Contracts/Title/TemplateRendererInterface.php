<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Title;

interface TemplateRendererInterface
{
    /** @param array<string, scalar|null> $data */
    public function render(string $template, array $data): string;
}
