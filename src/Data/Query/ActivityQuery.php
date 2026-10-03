<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data\Query;

use DateTimeImmutable;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\SortOrder;
use InvalidArgumentException;

final class ActivityQuery
{
    /** @var list<ActivityChannel> */
    public array $channels = [];

    /** @var list<string> */
    public array $actions = [];

    public ?DateTimeImmutable $from = null;

    public ?DateTimeImmutable $to = null;

    public ?string $actorType = null;

    public ?string $actorId = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $subjectClass = null;

    public ?string $traceId = null;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<ActivitySeverity> */
    public array $severities = [];

    public ?string $search = null;

    public ?Criterion $status = null;

    public SortOrder $sortOrder = SortOrder::Descending;

    public ?Cursor $cursor = null;

    public int $limit = 50;

    public function channels(ActivityChannel ...$channels): self
    {
        $this->channels = array_values($channels);

        return $this;
    }

    public function actions(string ...$actions): self
    {
        if (count($actions) > 20) {
            throw new InvalidArgumentException('Activity query accepts at most 20 action filters.');
        }

        $validated = [];

        foreach ($actions as $action) {
            if ('' === $action || mb_strlen($action, '8bit') > 191 || 1 !== preg_match('/^[A-Za-z0-9_.:\-]+$/', $action)) {
                throw new InvalidArgumentException("Invalid activity action filter [{$action}].");
            }

            $validated[] = $action;
        }

        $this->actions = array_values(array_unique($validated));

        return $this;
    }

    public function between(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        if ($from >= $to) {
            throw new InvalidArgumentException('Activity query start time must precede end time.');
        }

        $this->from = $from;
        $this->to = $to;

        return $this;
    }

    public function forActor(string $type, string|int $id): self
    {
        $this->actorType = $type;
        $this->actorId = (string) $id;

        return $this;
    }

    public function forTrace(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    public function forSubject(string $type, string|int $id): self
    {
        $this->subjectType = $type;
        $this->subjectId = (string) $id;

        return $this;
    }

    /** @param list<string> $tags */
    public function withAnyTag(array $tags): self
    {
        $this->tags = array_values(array_unique($tags));

        return $this;
    }

    public function search(string $term): self
    {
        $this->search = mb_trim($term);

        return $this;
    }

    public function whereStatus(string $operator, int $status): self
    {
        if (! in_array($operator, ['=', '!=', '>', '>=', '<', '<='], true)) {
            throw new InvalidArgumentException('Unsupported status operator.');
        }

        $this->status = new Criterion('status_code', $operator, $status);

        return $this;
    }

    public function orderByTime(SortOrder|string $direction = SortOrder::Descending): self
    {
        $this->sortOrder = is_string($direction) ? SortOrder::from($direction) : $direction;

        return $this;
    }

    public function severities(ActivitySeverity ...$severities): self
    {
        $this->severities = array_values($severities);

        return $this;
    }

    public function cursor(?Cursor $cursor): self
    {
        $this->cursor = $cursor;

        if ($cursor instanceof Cursor) {
            $this->sortOrder = $cursor->direction;
        }

        return $this;
    }

    public function forSubjectClass(?string $className): self
    {
        $this->subjectClass = $className;

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Activity query limit must be between 1 and 500.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function last(string $period, ?DateTimeImmutable $now = null): self
    {
        $to = $now ?? new DateTimeImmutable();
        $from = $to->modify('-'.$period);

        $this->from = $from;
        $this->to = $to;

        return $this;
    }
}
