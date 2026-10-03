<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum FilterDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Abstain = 'abstain';
}
