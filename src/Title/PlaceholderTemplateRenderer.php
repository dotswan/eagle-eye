<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title;

use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Stringable;

final readonly class PlaceholderTemplateRenderer implements TemplateRendererInterface
{
    public function render(string $template, array $data): string
    {
        $stringified = [];

        foreach ($data as $key => $value) {
            $string = $this->stringify($value);

            if ('' !== $string) {
                $stringified[(string) $key] = $string;
            }
        }

        $template = preg_replace_callback(
            '/:([a-z_]+)/',
            static fn (array $matches): string => isset($stringified[$matches[1]]) ? $matches[0] : '',
            $template,
        ) ?? $template;

        $template = $this->tidy($template);

        $replacements = [];

        foreach ($stringified as $key => $value) {
            $replacements[':'.$key] = $value;
        }

        return mb_trim(strtr($template, $replacements));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function usedParams(string $template, array $data): array
    {
        preg_match_all('/:([a-z_]+)/', $template, $matches);

        $used = array_fill_keys(array_unique($matches[1]), true);

        return array_intersect_key($data, $used);
    }

    private function tidy(string $rendered): string
    {
        $rendered = preg_replace('/\(\s*\)/', '', $rendered) ?? $rendered;
        $rendered = preg_replace('/(\s*,\s*)+/', ', ', $rendered) ?? $rendered;
        $rendered = preg_replace('/,\s*(?=\)|$)/', '', $rendered) ?? $rendered;
        $rendered = preg_replace('/\s*[·,]\s*$/', '', $rendered) ?? $rendered;

        return preg_replace('/\s{2,}/', ' ', $rendered) ?? $rendered;
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }
}
