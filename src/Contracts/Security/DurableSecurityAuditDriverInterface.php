<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Security;

interface DurableSecurityAuditDriverInterface
{
    public function hasUncommittedTransaction(): bool;
}
