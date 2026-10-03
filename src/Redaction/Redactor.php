<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction;

use Dotswan\EagleEye\Contracts\Redaction\RedactionModeResolverInterface;
use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Dotswan\EagleEye\Redaction\Strategies\DropStrategy;
use Dotswan\EagleEye\Redaction\Strategies\PartialMaskStrategy;
use Illuminate\Support\Str;

final readonly class Redactor implements RedactionModeResolverInterface
{
    /**
     * @param array<string, RedactionMode> $paths
     * @param list<string> $sensitiveKeyPatterns
     * @param list<string> $partialKeyPatterns
     * @param array<string, RedactionStrategyInterface> $strategies
     */
    public function __construct(
        private RedactionMode $defaultMode,
        private array $paths,
        private array $sensitiveKeyPatterns,
        private array $strategies,
        private AttributeMetadataReader $metadata,
        private array $partialKeyPatterns = [],
        private bool $detectEmailValues = false,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, SecretRef> $secrets
     * @param array<string, RedactionMode> $fieldModes
     * @param list<string>|null $allowedModes
     * @return array<string, mixed>
     */
    public function redact(array $payload, array &$secrets, array $fieldModes = [], ?array $allowedModes = null): array
    {
        return $this->walk($payload, '', $secrets, $fieldModes, $allowedModes);
    }

    /** @return array<string, RedactionMode> */
    public function fieldModes(?string $subjectClass): array
    {
        if (null === $subjectClass || ! class_exists($subjectClass)) {
            return [];
        }

        $modes = [];

        foreach ($this->metadata->read($subjectClass)->fields as $property => $field) {
            if (RedactionMode::Keep !== $field->redact) {
                $modes[$property] = $field->redact;
            }
        }

        return $modes;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, SecretRef> $secrets
     * @param array<string, RedactionMode> $fieldModes
     * @param list<string>|null $allowedModes
     * @param bool $detectEmails false inside a subtree explicitly declared Keep
     * @return array<string, mixed>
     */
    private function walk(array $data, string $prefix, array &$secrets, array $fieldModes, ?array $allowedModes, bool $detectEmails = true): array
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $path = '' === $prefix ? $key : $prefix.'.'.$key;
            $mode = $this->modeFor($path, $key, $fieldModes);
            $ruled = $mode instanceof RedactionMode;
            $kept = RedactionMode::Keep === $mode;

            if ($ruled && null !== $allowedModes && ! in_array($mode->value, $allowedModes, true)) {
                $mode = null;
            }

            if (! $ruled && $detectEmails && $this->detectEmailValues && is_string($value)
                && (null === $allowedModes || in_array(RedactionMode::Partial->value, $allowedModes, true))
                && true === $this->emailStrategy()?->isEmail($value)) {
                $mode = RedactionMode::Partial;
            }

            if ($mode instanceof RedactionMode) {
                $strategy = $this->strategies[$mode->value] ?? null;

                if ($strategy instanceof DropStrategy) {
                    unset($data[$key]);

                    continue;
                }

                if (null !== $strategy) {
                    $reference = 'payload.'.$path;

                    if ($this->isChangePair($value) && in_array($mode, [RedactionMode::Mask, RedactionMode::Partial, RedactionMode::Hash], true)) {
                        $data[$key] = [
                            'old' => $strategy->apply($value['old'], $reference.'.old'),
                            'new' => $strategy->apply($value['new'], $reference.'.new'),
                        ];

                        continue;
                    }

                    $result = $strategy->apply($value, $reference);

                    if ($result instanceof SecretRef) {
                        $secrets[$reference] = $result;
                        $data[$key] = ['__secret' => $reference];
                    } else {
                        $data[$key] = $result;
                    }

                    continue;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->walk($value, $path, $secrets, $fieldModes, $allowedModes, $detectEmails && ! $kept);
            }
        }

        return $data;
    }

    /** @param array<string, RedactionMode> $fieldModes */
    public function modeForKey(string $key, array $fieldModes = []): ?RedactionMode
    {
        if (isset($fieldModes[$key])) {
            return $fieldModes[$key];
        }

        $lowerKey = mb_strtolower($key);

        foreach ($this->sensitiveKeyPatterns as $pattern) {
            if (Str::is(mb_strtolower($pattern), $lowerKey)) {
                return $this->defaultMode;
            }
        }

        foreach ($this->partialKeyPatterns as $pattern) {
            if (Str::is(mb_strtolower($pattern), $lowerKey)) {
                return RedactionMode::Partial;
            }
        }

        return null;
    }

    /** @param array<string, RedactionMode> $fieldModes */
    private function modeFor(string $path, string $key, array $fieldModes): ?RedactionMode
    {
        foreach ($this->paths as $pattern => $mode) {
            if (PathMatcher::matches((string) $pattern, $path)) {
                return $mode;
            }
        }

        return $this->modeForKey($key, $fieldModes);
    }

    private function isChangePair(mixed $value): bool
    {
        return is_array($value)
            && 2 === count($value)
            && array_key_exists('old', $value)
            && array_key_exists('new', $value);
    }

    private function emailStrategy(): ?PartialMaskStrategy
    {
        $strategy = $this->strategies[RedactionMode::Partial->value] ?? null;

        return $strategy instanceof PartialMaskStrategy ? $strategy : null;
    }
}
