<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Contracts\Privacy\SubjectEraserInterface;
use Dotswan\EagleEye\Privacy\ErasureRegistry;
use Throwable;

final class ForgetCommand extends EagleEyeCommand
{
    protected $signature = 'activity-log:forget {type} {id}
        {--resolve-attempt= : Resolve an uncertain delivery only after independently confirming remote settlement}
        {--settlement-evidence= : Operator evidence reference proving the remote request can no longer ingest}';

    protected $description = 'Erase activity for one data subject and record a pseudonymous outcome tombstone.';

    public function handle(SubjectEraserInterface $eraser, ErasureRegistry $registry): int
    {
        try {
            $attempt = $this->option('resolve-attempt');

            if (is_string($attempt) && '' !== $attempt) {
                $evidence = $this->option('settlement-evidence');
                $registry->resolveAttempt($attempt, is_string($evidence) ? $evidence : '', $this->stringArgument('type'), $this->stringArgument('id'));
            }

            $result = $eraser->erase($this->stringArgument('type'), $this->stringArgument('id'));

            if ('completed' === $result->state) {
                $this->info("Erased {$result->removed} activity entry or entries; committed receipt recorded.");
            } else {
                $this->warn('Erasure request accepted and pending backend completion; committed receipt recorded.');
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
