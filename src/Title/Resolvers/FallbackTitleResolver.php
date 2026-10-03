<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Title\PlaceholderTemplateRenderer;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final readonly class FallbackTitleResolver implements TitleResolverInterface
{
    public function __construct(private TemplateRendererInterface $renderer)
    {
    }

    public function resolve(ActivityDraft $draft): string
    {
        $params = $this->params($draft);
        $channel = $draft->channel->value;
        $verb = Str::afterLast($draft->action, '.');

        $candidates = [
            "titles.{$channel}.{$verb}",
            "titles.{$channel}.default",
            'titles.fallback',
        ];

        foreach ($candidates as $key) {
            if (! Lang::has("eagle-eye::{$key}")) {
                continue;
            }

            $template = (string) __("eagle-eye::{$key}");

            if (! $this->placeholdersCovered($template, $params)) {
                continue;
            }

            return $this->emit($draft, $key, $template, $params);
        }

        $template = (string) __('eagle-eye::titles.fallback');

        return $this->emit($draft, 'titles.fallback', $template, $params);
    }

    /** @param array<string, string> $params */
    private function emit(ActivityDraft $draft, string $key, string $template, array $params): string
    {
        $params = PlaceholderTemplateRenderer::usedParams($template, $params);
        $draft->payload['title_source'] = ['key' => $key, 'params' => $params];

        return $this->renderer->render($template, $params);
    }

    /** @param array<string, string> $params */
    private function placeholdersCovered(string $template, array $params): bool
    {
        preg_match_all('/:([a-z_]+)/', $template, $matches);

        foreach (array_unique($matches[1]) as $placeholder) {
            if (! array_key_exists($placeholder, $params)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function params(ActivityDraft $draft): array
    {
        $actor = $draft->actor;
        $subject = $draft->subject;

        $params = [
            'actor'         => $actor instanceof Actor ? $actor->label ?? $actor->type : 'system',
            'action'        => $draft->action,
            'subject_label' => $subject instanceof Subject ? $subject->label ?? $subject->type : 'record',
        ];

        foreach ($draft->payload as $key => $value) {
            if (is_scalar($value) && ! isset($params[$key])) {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }
}
