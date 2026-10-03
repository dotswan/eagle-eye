<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Transport\Jobs\PersistActivityBatchJob;
use Illuminate\Contracts\Queue\Queue;

final readonly class QueueTransport implements TransportInterface
{
    public function __construct(
        private Queue $queue,
        private ?string $queueName = null,
    ) {
    }

    /** @param list<ActivityEntry> $entries */
    public function send(array $entries): void
    {
        EnvelopeBudget::batchSize($entries);

        $rows = array_map(
            static fn (ActivityEntry $entry): array => $entry->toStorage(),
            $entries,
        );

        $job = new PersistActivityBatchJob($rows);

        if (null !== $this->queueName) {
            $this->queue->push($job->onQueue($this->queueName));
        } else {
            $this->queue->push($job);
        }
    }
}
