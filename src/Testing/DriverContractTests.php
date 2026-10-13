<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Testing;

use DateTimeZone;
use Closure;
use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogQueryDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\Series\SeriesWindow;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use InvalidArgumentException;
use LogicException;
use LengthException;
use PHPUnit\Framework\Assert;

final class DriverContractTests
{
    /**
     * @param Closure(): ActivityLogDriverInterface $factory
     */
    public static function define(string $name, Closure $factory): void
    {
        test("{$name} driver round trips entries losslessly", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $entry = DriverContractTests::entry(1);
            $driver->write($entry);

            Assert::assertEquals($entry, $driver->find($entry->id));
        });

        test("{$name} driver writes idempotently", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $entry = DriverContractTests::entry(1);
            $driver->write($entry);
            $driver->write($entry);

            Assert::assertSame(1, $driver->stats()['entries']);
        });

        test("{$name} driver accepts batches without partial failures", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);

            Assert::assertSame([], $driver->writeBatch([DriverContractTests::entry(1), DriverContractTests::entry(2)]));
            Assert::assertSame(2, $driver->count(new ActivityQuery()));
        });

        test("{$name} driver rejects oversized batches before persistence", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $accepted = DriverContractTests::entry(1);
            $storage = DriverContractTests::entry(2)->toStorage();
            $storage['summary'] = str_repeat('x', EnvelopeBudget::MAX_STRING_BYTES + 1);
            $rejected = ActivityEntry::fromStorage($storage);
            $failure = null;

            try {
                $driver->writeBatch([$accepted, $rejected]);
            } catch (LengthException $exception) {
                $failure = $exception;
            }

            Assert::assertInstanceOf(LengthException::class, $failure);
            Assert::assertNull($driver->find($accepted->id));
            Assert::assertNull($driver->find($rejected->id));
        });

        test("{$name} driver answers actor subject tag and search queries", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([
                DriverContractTests::entry(1, actorId: '8821', subjectType: 'campaign', subjectId: '42', tags: ['gdpr'], title: 'Payment declined'),
                DriverContractTests::entry(2, actorId: '9911', subjectType: 'invoice', subjectId: '84', tags: ['billing']),
            ]);

            Assert::assertCount(1, $driver->search((new ActivityQuery())->forActor('user', '8821'))->entries);
            Assert::assertCount(1, $driver->search((new ActivityQuery())->forSubject('campaign', '42'))->entries);
            Assert::assertCount(1, $driver->search((new ActivityQuery())->withAnyTag(['gdpr']))->entries);
            Assert::assertCount(1, $driver->search((new ActivityQuery())->search('payment declined'))->entries);
        });

        test("{$name} driver paginates without gaps or duplicates", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([DriverContractTests::entry(1), DriverContractTests::entry(2), DriverContractTests::entry(3)]);
            $first = $driver->search((new ActivityQuery())->orderByTime(SortOrder::Ascending)->limit(2));

            Assert::assertTrue($first->hasMore);
            Assert::assertNotNull($first->nextCursor);
            Assert::assertCount(2, $first->entries);

            $second = $driver->search((new ActivityQuery())->cursor($first->nextCursor)->limit(2));
            $ids = array_map(static fn (ActivityEntry $entry): string => $entry->id, [...$first->entries, ...$second->entries]);

            Assert::assertCount(3, array_unique($ids));
            Assert::assertFalse($second->hasMore);
        });

        test("{$name} driver returns time ordered trace searches", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $traceId = str_repeat('a', 32);
            $driver->writeBatch([DriverContractTests::entry(2, traceId: $traceId), DriverContractTests::entry(1, traceId: $traceId)]);
            $result = $driver->search((new ActivityQuery())->forTrace($traceId)->orderByTime(SortOrder::Ascending)->limit(15));

            Assert::assertSame(
                [DriverContractTests::id(1), DriverContractTests::id(2)],
                array_map(static fn (ActivityEntry $entry): string => $entry->id, $result->entries),
            );
        });

        test("{$name} driver returns exact counts and aggregations", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([
                DriverContractTests::entry(1, channel: ActivityChannel::Model),
                DriverContractTests::entry(2, channel: ActivityChannel::Model),
                DriverContractTests::entry(3, channel: ActivityChannel::Http),
            ]);

            Assert::assertSame(3, $driver->count(new ActivityQuery()));
            Assert::assertSame(['http' => 1, 'model' => 2], $driver->aggregate(new ActivityQuery(), 'channel'));
        });

        test("{$name} driver filters by severity", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([
                DriverContractTests::entry(1, title: 'Bravo', severity: ActivitySeverity::Warning),
                DriverContractTests::entry(2, title: 'Alpha', severity: ActivitySeverity::Error),
                DriverContractTests::entry(3, title: 'Charlie', severity: ActivitySeverity::Error),
            ]);

            Assert::assertCount(2, $driver->search((new ActivityQuery())->severities(ActivitySeverity::Error))->entries);
            Assert::assertCount(3, $driver->search((new ActivityQuery())->severities(ActivitySeverity::Error, ActivitySeverity::Warning))->entries);
        });

        test("{$name} driver filters by action with exact matches", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([
                DriverContractTests::entry(1, action: 'model.updated'),
                DriverContractTests::entry(2, action: 'model.updated_at'),
                DriverContractTests::entry(3, action: 'user.login'),
            ]);

            Assert::assertCount(1, $driver->search((new ActivityQuery())->actions('model.updated'))->entries);
            Assert::assertCount(2, $driver->search((new ActivityQuery())->actions('model.updated', 'user.login'))->entries);
            Assert::assertCount(0, $driver->search((new ActivityQuery())->actions('model'))->entries);
        });

        test("{$name} driver aggregates by trace id", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $traceA = str_repeat('a', 32);
            $traceB = str_repeat('b', 32);
            $driver->writeBatch([
                DriverContractTests::entry(1, traceId: $traceA),
                DriverContractTests::entry(2, traceId: $traceA),
                DriverContractTests::entry(3, traceId: $traceB),
            ]);

            $groups = $driver->aggregate(new ActivityQuery(), 'trace_id');

            Assert::assertSame(2, $groups[$traceA]);
            Assert::assertSame(1, $groups[$traceB]);
        });

        test("{$name} driver rejects unknown aggregation group keys", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->write(DriverContractTests::entry(1));

            expect(fn (): array => $driver->aggregate(new ActivityQuery(), 'nonsense'))->toThrow(InvalidArgumentException::class);
        });

        test("{$name} driver supports scoped maintenance", function () use ($factory): void {
            $driver = DriverContractTests::driver($factory);
            $driver->writeBatch([
                DriverContractTests::entry(1, subjectType: 'campaign', subjectId: '42'),
                DriverContractTests::entry(2, subjectType: 'invoice', subjectId: '84'),
            ]);
            $driver->install();
            $driver->install();

            if (! $driver->supports(Capability::SynchronousDeletion)) {
                // Append backends (Loki) own retention remotely; prune() is a
                // documented no-op so the operation can never fake success.
                Assert::assertSame(0, $driver->prune(new DateTimeImmutable()));

                return;
            }

            Assert::assertSame(1, $driver->forget('campaign', '42')->removed);
            Assert::assertSame(1, $driver->prune(new DateTimeImmutable('+1 day')));
            Assert::assertSame(0, $driver->stats()['entries']);
        });
    }

    /** @param Closure(): ActivityLogDriverInterface $factory */
    public static function defineNull(string $name, Closure $factory): void
    {
        test("{$name} driver safely discards writes", function () use ($factory): void {
            $driver = $factory();
            $entry = DriverContractTests::entry(1);
            $driver->write($entry);

            Assert::assertSame([], $driver->writeBatch([$entry]));
            Assert::assertTrue($driver->healthy());
            Assert::assertFalse($driver->supports(Capability::FullTextSearch));

            if ($driver instanceof ActivityLogQueryDriverInterface) {
                Assert::assertNull($driver->find($entry->id));
                Assert::assertSame([], $driver->search(new ActivityQuery())->entries);
            }
        });
    }

    /**
     * Registers the optional series-aggregation contract for a driver.
     *
     * @param  Closure(): ActivityLogDriverInterface  $factory
     */
    public static function defineSeries(string $name, Closure $factory): void
    {
        test("{$name} driver aggregates zero-filled aligned series", function () use ($factory): void {
            $driver = $factory();

            if (! $driver instanceof ActivityLogSeriesDriverInterface) {
                throw new LogicException('Series contract driver must implement ActivityLogSeriesDriverInterface.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $model = ActivityChannel::Model;
            $http = ActivityChannel::Http;
            $entries = [
                DriverContractTests::at(DriverContractTests::entry(1, channel: $model), $now->modify('-10 minutes')),
                DriverContractTests::at(DriverContractTests::entry(2, channel: $model), $now->modify('-10 minutes')->modify('+30 seconds')),
                DriverContractTests::at(DriverContractTests::entry(3, channel: $http), $now->modify('-5 minutes')),
            ];
            $driver->writeBatch($entries);

            $from = $now->modify('-30 minutes');
            $to = $now->modify('+1 minute');
            $window = SeriesWindow::fromQuery((new ActivityQuery())->between($from, $to), 300);

            $grouped = $driver->aggregateSeries((new ActivityQuery())->between($from, $to), 'channel', 300);

            Assert::assertCount($window->bucketCount(), $grouped['buckets']);
            Assert::assertSame(2, array_sum($grouped['series'][$model->value] ?? []));
            Assert::assertSame(1, array_sum($grouped['series'][$http->value] ?? []));
            Assert::assertContains(0, $grouped['series'][$model->value] ?? []);

            $total = $driver->aggregateSeries((new ActivityQuery())->between($from, $to), null, 300);

            Assert::assertSame(3, array_sum($total['series']['_total'] ?? []));
        });
    }

    /**
     * @param Closure(): ActivityLogDriverInterface $factory
     */
    public static function driver(Closure $factory): ActivityLogDriverInterface&ActivityLogQueryDriverInterface&ActivityLogMaintenanceDriverInterface
    {
        $driver = $factory();

        if (! $driver instanceof ActivityLogQueryDriverInterface || ! $driver instanceof ActivityLogMaintenanceDriverInterface) {
            throw new LogicException('Contract driver must implement query and maintenance interfaces.');
        }

        return $driver;
    }

    /** @param list<string> $tags */
    public static function entry(
        int $sequence,
        string $actorId = '8821',
        string $subjectType = 'campaign',
        string $subjectId = '42',
        array $tags = [],
        string $title = 'Activity recorded',
        string $traceId = '4bf92f3577b34da6a3ce929d0e0e4736',
        ActivityChannel $channel = ActivityChannel::Custom,
        ActivitySeverity $severity = ActivitySeverity::Info,
        string $action = 'recorded',
    ): ActivityEntry {
        $draft = ActivityDraft::make($action, $channel, $severity)
            ->withActor(new Actor('user', $actorId, 'User '.$actorId, '/users/'.$actorId))
            ->withSubject(new Subject($subjectType, $subjectId, 'Subject '.$subjectId, self::class, '/subjects/'.$subjectId))
            ->withTags($tags)
            ->withPayload(['message' => $title]);
        $draft->id = self::id($sequence);
        $draft->title = $title;
        // Recent, distinct, monotonically increasing moments: some drivers
        // (Loki) reject samples that are too old, and a pinned historical
        // date silently rots the contract suite as the calendar moves on.
        $draft->loggedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-1 hour')
            ->modify(sprintf('+%d seconds', $sequence));
        $draft->createdAt = $draft->loggedAt;
        $draft->trace = new TraceContext($traceId, self::id(100 + $sequence));
        $draft->request = new RequestSnapshot('GET', '/subjects/'.$subjectId, 'subjects.show', 200);

        return $draft->freeze();
    }

    public static function at(ActivityEntry $entry, DateTimeImmutable $at): ActivityEntry
    {
        $storage = $entry->toStorage();
        $storage['logged_at'] = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $storage['created_at'] = $storage['logged_at'];

        return ActivityEntry::fromStorage($storage);
    }

    public static function id(int $sequence): string
    {
        return sprintf('01J%023d', $sequence);
    }
}
