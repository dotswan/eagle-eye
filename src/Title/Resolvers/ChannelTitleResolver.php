<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Title\PlaceholderTemplateRenderer;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

abstract class ChannelTitleResolver implements TitleResolverInterface
{
    public function __construct(protected readonly TemplateRendererInterface $renderer)
    {
    }

    abstract protected function channel(): ActivityChannel;

    public function resolve(ActivityDraft $draft): ?string
    {
        if ($this->channel() !== $draft->channel) {
            return null;
        }

        return $this->render($draft);
    }

    abstract protected function render(ActivityDraft $draft): ?string;

    /** @param array<string, mixed> $params */
    protected function renderTemplate(ActivityDraft $draft, string $base, array $params): string
    {
        $verb = Str::afterLast($draft->action, '.');
        $key = Lang::has("eagle-eye::titles.{$base}.{$verb}") ? "{$base}.{$verb}" : "{$base}.default";

        return $this->renderKey($draft, $key, $params);
    }

    /** @param array<string, mixed> $params */
    protected function renderKey(ActivityDraft $draft, string $key, array $params): string
    {
        $template = (string) __("eagle-eye::titles.{$key}");
        $params = PlaceholderTemplateRenderer::usedParams($template, $params);

        $draft->payload['title_source'] = [
            'key'    => "titles.{$key}",
            'params' => $params,
        ];

        return $this->renderer->render($template, $params);
    }

    protected function actorLabel(ActivityDraft $draft): string
    {
        $actor = $draft->actor;

        if (!$actor instanceof Actor) {
            return 'system';
        }

        return $actor->label ?? $actor->type;
    }

    protected function subjectLabel(ActivityDraft $draft): string
    {
        $subject = $draft->subject;

        if (!$subject instanceof Subject) {
            return 'record';
        }

        return $subject->label ?? $subject->type;
    }

    protected function jobShort(ActivityDraft $draft): string
    {
        $job = $draft->payload['job'] ?? null;
        $short = class_basename(is_string($job) && '' !== $job ? $job : 'Job');

        return Str::endsWith($short, 'Job') ? Str::substr($short, 0, -3) : $short;
    }

    protected function notificationShort(ActivityDraft $draft): string
    {
        $notification = $draft->payload['notification'] ?? $draft->action;

        return class_basename((string) (is_string($notification) && '' !== $notification ? $notification : $draft->action));
    }

    protected function durationLabel(ActivityDraft $draft): string
    {
        $milliseconds = $draft->payload['duration_ms'] ?? null;

        if (! is_numeric($milliseconds)) {
            return 'unknown duration';
        }

        $milliseconds = max(0, (int) $milliseconds);

        return match (true) {
            $milliseconds < 1000  => $milliseconds.'ms',
            $milliseconds < 60000 => mb_rtrim(mb_rtrim(number_format($milliseconds / 1000, 1, '.', ''), '0'), '.').'s',
            default               => intdiv($milliseconds, 60000).'m '.intdiv($milliseconds % 60000, 1000).'s',
        };
    }

    protected function changeSummary(ActivityDraft $draft): string
    {
        $changes = $draft->payload['changes'] ?? [];

        if (! is_array($changes)) {
            return '';
        }

        $parts = [];
        $ignored = config('eagle-eye.capture.model.ignored_change_fields', ['created_at', 'updated_at', 'deleted_at']);
        $ignored = is_array($ignored) ? $ignored : [];

        foreach ($changes as $field => $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)) {
                continue;
            }

            if (in_array((string) $field, $ignored, true)) {
                continue;
            }

            $parts[] = Str::headline((string) $field).': '.$this->scalar($change['old']).' → '.$this->scalar($change['new']);

            if (3 === count($parts)) {
                break;
            }
        }

        return implode(', ', $parts);
    }

    protected function changedFields(ActivityDraft $draft): string
    {
        $changes = $draft->payload['changes'] ?? [];

        if (! is_array($changes)) {
            return '';
        }

        $ignored = config('eagle-eye.capture.model.ignored_change_fields', ['created_at', 'updated_at', 'deleted_at']);
        $ignored = is_array($ignored) ? $ignored : [];
        $fields = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)) {
                continue;
            }

            if (in_array((string) $field, $ignored, true)) {
                continue;
            }

            $fields[] = Str::headline((string) $field);
        }

        if ([] === $fields) {
            return '';
        }

        $suffix = '';

        if (count($fields) > 3) {
            $suffix = ' +'.(count($fields) - 3).' more';
            $fields = array_slice($fields, 0, 3);
        }

        return implode(', ', $fields).$suffix;
    }

    protected function scalar(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $string = (string) $value;

            return mb_strlen($string) > 80 ? mb_substr($string, 0, 79).'…' : $string;
        }

        return array_is_list($value) ? '[…]' : '{…}';
    }
}
