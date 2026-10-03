<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\DescribesActivity;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Metadata\ActivityMetadata;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Dotswan\EagleEye\Contracts\Redaction\RedactionModeResolverInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;
use Throwable;

final readonly class ConventionSubjectResolver implements SubjectResolverInterface
{
    public function __construct(
        private AttributeMetadataReader $metadata,
        private ConfigRepository $config,
        private RedactionModeResolverInterface $redactor,
        private ActivityPrivacyDeclaration $privacy,
    ) {
    }

    public function resolve(object $subject): Subject
    {
        $metadata = $this->metadata->read($subject);
        $className = $subject::class;
        $id = $this->resolveId($subject);

        return new Subject(
            type: $metadata->subjectType ?? Str::snake(class_basename($className)),
            id: $id,
            label: $this->resolveLabel($subject, $metadata, $id),
            className: $className,
        );
    }

    private function resolveId(object $subject): string
    {
        $getKey = [$subject, 'getKey'];
        $value = is_callable($getKey) ? $getKey() : data_get($subject, 'id');
        $id = $this->scalarString($value);

        if (null === $id || '' === $id) {
            throw new InvalidArgumentException('Unable to resolve activity subject ID for ['.$subject::class.'].');
        }

        return $id;
    }

    private function resolveLabel(object $subject, ActivityMetadata $metadata, string $id): string
    {
        if ($subject instanceof DescribesActivity) {
            $label = mb_trim($subject->activityLogLabel());

            if ('' !== $label) {
                return $label;
            }
        }

        $fieldModes = $this->fieldModes($subject);
        $attributes = array_filter([
            $metadata->labelAttribute,
            $this->configuredLabelAttribute($subject::class),
            ...$this->configuredConventionalAttributes(),
        ], is_string(...));

        foreach (array_unique($attributes) as $attribute) {
            $mode = $this->redactor->modeForKey($attribute, $fieldModes);

            if ($mode instanceof RedactionMode && RedactionMode::Keep !== $mode) {
                continue;
            }

            $label = $this->readAttribute($subject, $attribute);

            if (null !== $label && '' !== $label) {
                return $label;
            }
        }

        return Str::headline(class_basename($subject)).' #'.$this->shortId($id);
    }

    /** @return array<string, RedactionMode> */
    private function fieldModes(object $subject): array
    {
        return array_replace($this->redactor->fieldModes($subject::class), $this->privacy->for($subject));
    }

    private function shortId(string $id): string
    {
        return Str::isUuid($id) ? mb_substr($id, 0, 8) : $id;
    }

    /** @param class-string $className */
    private function configuredLabelAttribute(string $className): ?string
    {
        $perClass = $this->config->get('eagle-eye.subjects.per_class', []);

        if (! is_array($perClass) || ! isset($perClass[$className]) || ! is_array($perClass[$className])) {
            return null;
        }

        $attribute = $perClass[$className]['label_attribute'] ?? null;

        return is_string($attribute) && '' !== mb_trim($attribute) ? mb_trim($attribute) : null;
    }

    /** @return list<string> */
    private function configuredConventionalAttributes(): array
    {
        $attributes = $this->config->get('eagle-eye.subjects.label_attributes', []);

        if (! is_array($attributes)) {
            return [];
        }

        return array_values(array_filter($attributes, is_string(...)));
    }

    private function readAttribute(object $subject, string $attribute): ?string
    {
        try {
            return $this->scalarString(data_get($subject, $attribute));
        } catch (Throwable) {
            return null;
        }
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return mb_trim((string) $value);
        }

        return $value instanceof Stringable ? mb_trim((string) $value) : null;
    }
}
