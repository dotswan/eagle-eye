<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Contracts\Capture\CaptureAdapterInterface;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Resolution\ActivityPrivacyDeclaration;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Container\Container as ApplicationContainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

final readonly class EloquentCapture implements CaptureAdapterInterface
{
    private const string SELF_PREFIX = 'Dotswan\\EagleEye\\';

    private const array SEVERITIES = [
        'created'      => 'info',
        'updated'      => 'info',
        'deleted'      => 'notice',
        'restored'     => 'notice',
        'forceDeleted' => 'warning',
    ];

    public function __construct(
        private Dispatcher $events,
    ) {
    }

    public function register(): void
    {
        $this->events->listen('eloquent.*', function (string $eventName, array $payload): void {
            $container = ApplicationContainer::getInstance();

            if ($container->make(CaptureSuppression::class)->active()) {
                return;
            }

            try {
                $this->handle($container, $eventName, $payload);
            } catch (Throwable $exception) {
                try {
                    $container->make(CaptureSuppression::class)->run(static fn () => report($exception));
                } catch (Throwable) {
                }
            }
        });
    }

    /** @param array<array-key, mixed> $payload */
    private function handle(Container $container, string $eventName, array $payload): void
    {
        [$name, $class] = array_pad(explode(': ', $eventName, 2), 2, null);
        $event = null === $name || ! str_starts_with($name, 'eloquent.')
            ? null
            : mb_substr($name, mb_strlen('eloquent.'));

        if (null === $event || ! isset(self::SEVERITIES[$event])) {
            return;
        }

        $allowed = $container->make(ConfigRepository::class)->get('eagle-eye.capture.model.events', []);
        $allowed = is_array($allowed) ? array_filter($allowed, is_string(...)) : [];

        if (! in_array($event, $allowed, true)) {
            return;
        }

        if (null === $class || str_starts_with($class, self::SELF_PREFIX)) {
            return;
        }

        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        if ('updated' === $event && [] === $model->getRawOriginal()) {
            return;
        }

        $rule = $this->stateRule($container, $event, $model);
        $severity = null !== $rule
            ? (ActivitySeverity::tryFrom($rule['severity']) ?? ActivitySeverity::Warning)
            : ActivitySeverity::from(self::SEVERITIES[$event]);
        $action = 'model.'.$event;
        $payload = $this->payload($event, $model);

        if (null !== $rule) {
            $payload['state'] = $rule['state'];
        }

        $draft = ActivityDraft::make($action, ActivityChannel::Model, $severity)
            ->withSubject($container->make(SubjectResolverInterface::class)->resolve($model))
            ->withPayload($payload);

        $draft->fieldModes = $container->make(ActivityPrivacyDeclaration::class)->for($model);

        $record = static function () use ($container, $draft): void {
            $container->make(EagleEyeInterface::class)->record($draft);
        };
        $connection = $model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($record);

            return;
        }

        $record();
    }

    /**
     * @return array{severity: string, state: array{field: string, old: mixed, new: mixed}}|null
     */
    private function stateRule(Container $container, string $event, Model $model): ?array
    {
        if ('updated' !== $event) {
            return null;
        }

        $states = $container->make(ConfigRepository::class)->get('eagle-eye.capture.model.states', []);

        if (! is_array($states)) {
            return null;
        }

        $fields = is_array($states['fields'] ?? null) ? $states['fields'] : [];
        $failureValues = is_array($states['failure_values'] ?? null) ? $states['failure_values'] : [];
        $severity = is_string($states['severity'] ?? null) ? $states['severity'] : 'warning';

        foreach ($model->getDirty() as $attribute => $value) {
            if (! in_array((string) $attribute, $fields, true)) {
                continue;
            }

            $normalised = mb_strtolower(is_scalar($value) ? (string) $value : (string) json_encode($value));

            if (! in_array($normalised, $failureValues, true)) {
                continue;
            }

            return [
                'severity' => $severity,
                'state'    => [
                    'field' => (string) $attribute,
                    'old'   => $model->getRawOriginal((string) $attribute),
                    'new'   => $value,
                ],
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(string $event, Model $model): array
    {
        if ('created' === $event) {
            $changes = [];

            foreach ($model->getAttributes() as $attribute => $value) {
                $changes[$attribute] = ['old' => null, 'new' => $value];
            }

            return ['changes' => $changes];
        }

        if ('updated' === $event) {
            $changes = [];

            foreach ($model->getDirty() as $attribute => $value) {
                $changes[$attribute] = ['old' => $model->getRawOriginal($attribute), 'new' => $value];
            }

            return ['changes' => $changes];
        }

        return ['key' => $model->getKeyName().' '.Str::headline($event)];
    }
}
