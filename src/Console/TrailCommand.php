<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Console\Command;

final class TrailCommand extends Command
{
    protected $signature = 'activity-log:trail {--user=} {--subject=} {--limit=50}';

    protected $description = 'Print recent activity for one actor or subject.';

    public function handle(ActivityRepositoryInterface $repository): int
    {
        $query = (new ActivityQuery())->limit(max(1, min((int) $this->option('limit'), 500)));
        $user = $this->option('user');
        $subject = $this->option('subject');

        if (is_string($user) && '' !== $user) {
            [$type, $id] = $this->identifier($user, 'user');
            $query->forActor($type, $id);
        } elseif (is_string($subject) && '' !== $subject) {
            [$type, $id] = $this->identifier($subject, 'subject');
            $query->forSubject($type, $id);
        } else {
            $this->error('Provide --user or --subject. Values may use type:id syntax.');

            return self::INVALID;
        }

        $this->table(['Time', 'Channel', 'Action', 'Title'], array_map(
            static fn (ActivityEntry $entry): array => [
                $entry->loggedAt->format(DATE_ATOM),
                $entry->channel->value,
                $entry->action,
                $entry->title,
            ],
            $repository->search($query)->entries,
        ));

        return self::SUCCESS;
    }

    /** @return array{string, string} */
    private function identifier(string $value, string $defaultType): array
    {
        $parts = explode(':', $value, 2);

        return 2 === count($parts) ? [$parts[0], $parts[1]] : [$defaultType, $value];
    }
}
