<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Integrity;

use Dotswan\EagleEye\Contracts\Integrity\IntegrityHasherInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use JsonException;

final class CanonicalEntryHasher implements IntegrityHasherInterface
{
    /** @throws JsonException */
    public function hash(ActivityEntry $entry, ?string $previousHash = null): string
    {
        $storage = $entry->toStorage();
        $storage['integrity_hash'] = null;

        return hash('sha256', ($previousHash ?? '').json_encode(
            $this->canonicalize($storage),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
