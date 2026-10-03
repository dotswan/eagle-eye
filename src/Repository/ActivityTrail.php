<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Repository;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\SortOrder;

final readonly class ActivityTrail
{
    public function __construct(
        private ActivityRepositoryInterface $repository,
        public ActivityQuery $criteria = new ActivityQuery(),
    ) {
    }

    public function channels(ActivityChannel ...$channels): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->channels(...$channels));
    }

    public function between(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->between($from, $to));
    }

    public function forActor(string $type, string|int $id): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->forActor($type, $id));
    }

    public function forSubject(string $type, string|int $id): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->forSubject($type, $id));
    }

    public function forTrace(string $traceId): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->forTrace($traceId));
    }

    public function forSubjectClass(?string $className): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->forSubjectClass($className));
    }

    /** @param list<string> $tags */
    public function withAnyTag(array $tags): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->withAnyTag($tags));
    }

    public function search(string $term): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->search($term));
    }

    public function whereStatus(string $operator, int $status): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->whereStatus($operator, $status));
    }

    public function orderByTime(SortOrder|string $direction = SortOrder::Descending): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->orderByTime($direction));
    }

    public function last(string $period, ?DateTimeImmutable $now = null): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->last($period, $now));
    }

    public function limit(int $limit): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->limit($limit));
    }

    public function cursor(?Cursor $cursor): self
    {
        return $this->withCriteria(static fn (ActivityQuery $criteria): ActivityQuery => $criteria->cursor($cursor));
    }

    public function get(): QueryResult
    {
        return $this->repository->search($this->criteria);
    }

    public function count(): int
    {
        return $this->repository->count($this->criteria);
    }

    /** @return array<string, int> */
    public function aggregate(string $groupBy, string $interval = 'day'): array
    {
        return $this->repository->aggregate($this->criteria, $groupBy, $interval);
    }

    /** @param callable(ActivityQuery): mixed $callback */
    private function withCriteria(callable $callback): self
    {
        $criteria = clone $this->criteria;
        $callback($criteria);

        return new self($this->repository, $criteria);
    }
}
