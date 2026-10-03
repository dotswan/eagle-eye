<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\WriteFailure;
use Dotswan\EagleEye\Enums\Capability;

interface ActivityLogDriverInterface
{
    /** Entry must be rejected before persistence when it exceeds the published envelope limits. */
    public function write(ActivityEntry $entry): void;

    /**
     * @param list<ActivityEntry> $entries
     * @return list<WriteFailure>
     *
     * The entire batch must be rejected before persistence when any entry or
     * the batch exceeds the published delivery limits.
     */
    public function writeBatch(array $entries): array;

    public function name(): string;

    public function healthy(): bool;

    public function supports(Capability $capability): bool;
}
