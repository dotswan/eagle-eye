<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\Resolution\ActivityEntryPresenterInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActivityIdentityResolverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;

final readonly class ActivityEntryPresenter implements ActivityEntryPresenterInterface
{
    public function __construct(private ActivityIdentityResolverInterface $identities)
    {
    }

    /**
     * @param list<ActivityEntry> $entries
     * @return list<ActivityEntry>
     */
    public function present(array $entries): array
    {
        $actors = [];
        $subjects = [];

        foreach ($entries as $entry) {
            if ($entry->actor instanceof Actor) {
                $actors[$entry->id] = $entry->actor;
            }

            if ($entry->subject instanceof Subject) {
                $subjects[$entry->id] = $entry->subject;
            }
        }

        $actors = $this->identities->resolveActors($actors);
        $subjects = $this->identities->resolveSubjects($subjects);

        return array_map(
            fn (ActivityEntry $entry): ActivityEntry => $this->withIdentities(
                $entry,
                $actors[$entry->id] ?? $entry->actor,
                $subjects[$entry->id] ?? $entry->subject,
            ),
            $entries,
        );
    }

    public function presentOne(ActivityEntry $entry): ActivityEntry
    {
        return $this->present([$entry])[0];
    }

    private function withIdentities(ActivityEntry $entry, ?Actor $actor, ?Subject $subject): ActivityEntry
    {
        return new ActivityEntry(
            id: $entry->id,
            schemaVersion: $entry->schemaVersion,
            loggedAt: $entry->loggedAt,
            channel: $entry->channel,
            action: $entry->action,
            severity: $entry->severity,
            title: $entry->title,
            summary: $entry->summary,
            actor: $actor,
            subject: $subject,
            trace: $entry->trace,
            tags: $entry->tags,
            request: $entry->request,
            payload: $entry->payload,
            secrets: $entry->secrets,
            payloadBytes: $entry->payloadBytes,
            environment: $entry->environment,
            hostname: $entry->hostname,
            memoryKb: $entry->memoryKb,
            integrityHash: $entry->integrityHash,
            createdAt: $entry->createdAt,
        );
    }
}
