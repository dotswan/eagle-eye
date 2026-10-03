<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Redaction\Redactor;

/**
 * Encrypts values whose configured redaction mode is `encrypt`, replacing them
 * with an `__secret` marker and storing a blind-indexed reference in `secrets`.
 */
final readonly class EncryptSensitive implements ActivityPipeInterface
{
    public function __construct(private Redactor $redactor)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $secrets = $draft->secrets;
        $draft->payload = $this->redactor->redact(
            $draft->payload,
            $secrets,
            array_replace($this->redactor->fieldModes($draft->subject?->className), $draft->fieldModes),
            [RedactionMode::Encrypt->value],
        );
        $draft->secrets = $secrets;

        return $next($draft);
    }
}
