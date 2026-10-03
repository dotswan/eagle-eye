<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

/**
 * A driver implementing the full write/query/maintenance surface. Composite
 * drivers (MultiDriver) require this so every read/write delegation is
 * type-safe without runtime instanceof chains.
 */
interface FullActivityLogDriverInterface extends ActivityLogDriverInterface, ActivityLogMaintenanceDriverInterface, ActivityLogQueryDriverInterface
{
}
