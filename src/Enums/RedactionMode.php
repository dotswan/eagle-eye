<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum RedactionMode: string
{
    case Keep = 'keep';
    case Mask = 'mask';
    case Partial = 'partial';
    case Hash = 'hash';
    case Encrypt = 'encrypt';
    case Drop = 'drop';
}
