<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts;

use Dotswan\EagleEye\Enums\RedactionMode;

interface DeclaresActivityPrivacy
{
    /**
     * Instance-level redaction modes keyed by attribute name.
     *
     * The declaration must be pure: implementations may not run database
     * queries, and should rely on already-loaded attributes only.
     *
     * @return array<string, RedactionMode>
     */
    public function activityPrivacy(): array;
}
