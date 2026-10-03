<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum ActivitySeverity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';
}
