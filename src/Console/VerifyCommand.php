<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Integrity\IntegrityVerifier;
use Illuminate\Console\Command;

final class VerifyCommand extends Command
{
    protected $signature = 'activity-log:verify';

    protected $description = 'Verify stored activity integrity chains.';

    public function handle(IntegrityVerifier $verifier): int
    {
        $result = $verifier->verify();

        if (! $result->valid()) {
            if ($result->pendingCount > 0) {
                $this->warn("Integrity incomplete: {$result->pendingCount} sealed entries await confirmed delivery.");
            }
            $suffix = $result->truncated() ? ' (first 1,000 shown)' : '';
            $this->error("Integrity failure ({$result->invalidCount}){$suffix}: ".implode(', ', $result->invalidEntryIds));

            return self::FAILURE;
        }

        $this->info("Verified {$result->checked} sealed activity entry or entries.");

        return self::SUCCESS;
    }
}
