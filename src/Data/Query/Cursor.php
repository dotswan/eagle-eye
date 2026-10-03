<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data\Query;

use DateTimeImmutable;
use Dotswan\EagleEye\Enums\SortOrder;
use InvalidArgumentException;
use JsonException;

final readonly class Cursor
{
    public function __construct(
        public DateTimeImmutable $timestamp,
        public string $lastId,
        public SortOrder $direction = SortOrder::Descending,
    ) {
    }

    /** @throws JsonException */
    public function encode(): string
    {
        $json = json_encode([
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'last_id'   => $this->lastId,
            'direction' => $this->direction->value,
        ], JSON_THROW_ON_ERROR);

        return mb_rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @throws JsonException */
    public static function decode(string $cursor): self
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (false === $decoded) {
            throw new InvalidArgumentException('Invalid activity cursor.');
        }

        $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['timestamp'], $data['last_id'], $data['direction'])) {
            throw new InvalidArgumentException('Invalid activity cursor payload.');
        }

        return new self(
            timestamp: new DateTimeImmutable((string) $data['timestamp']),
            lastId: (string) $data['last_id'],
            direction: SortOrder::from((string) $data['direction']),
        );
    }
}
