<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Metadata;

use Dotswan\EagleEye\Attributes\ActivityField;
use Dotswan\EagleEye\Attributes\ActivityLabel;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Attributes\DoNotLog;
use InvalidArgumentException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ReflectionClass;
use Throwable;

final class AttributeMetadataReader
{
    private const int CACHE_LIMIT = 256;

    /** @var array<class-string, ActivityMetadata> */
    private array $cache = [];

    public function __construct(private readonly ?CacheRepository $persistentCache = null)
    {
    }

    public function read(object|string $subject): ActivityMetadata
    {
        $className = is_object($subject) ? $subject::class : $subject;

        if (! class_exists($className)) {
            throw new InvalidArgumentException("Activity metadata class [{$className}] does not exist.");
        }

        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        $cacheKey = $this->cacheKey($className);
        $cached = null;

        try {
            $cached = $this->persistentCache?->get($cacheKey);
        } catch (Throwable) {
        }

        if ($cached instanceof ActivityMetadata) {
            return $this->remember($className, $cached);
        }

        $metadata = $this->reflect($className);

        try {
            $this->persistentCache?->forever($cacheKey, $metadata);
        } catch (Throwable) {
        }

        return $this->remember($className, $metadata);
    }

    /** @param class-string $className */
    private function reflect(string $className): ActivityMetadata
    {
        $reflection = new ReflectionClass($className);
        $subject = $reflection->getAttributes(ActivitySubject::class)[0] ?? null;
        $label = $reflection->getAttributes(ActivityLabel::class)[0] ?? null;
        $tags = $reflection->getAttributes(ActivityTags::class)[0] ?? null;
        $fields = [];

        foreach ($reflection->getProperties() as $property) {
            $field = $property->getAttributes(ActivityField::class)[0] ?? null;

            if (null !== $field) {
                $fields[$property->getName()] = $field->newInstance();
            }
        }

        return new ActivityMetadata(
            subjectType: $subject?->newInstance()->type,
            labelAttribute: $label?->newInstance()->attribute,
            tags: $tags?->newInstance()->tags ?? [],
            doNotLog: [] !== $reflection->getAttributes(DoNotLog::class),
            fields: $fields,
        );
    }

    /** @param class-string $className */
    private function cacheKey(string $className): string
    {
        $reflection = new ReflectionClass($className);
        $file = $reflection->getFileName();
        $fingerprint = is_string($file) && is_file($file) ? hash_file('sha256', $file) : false;
        $version = is_string($fingerprint) ? $fingerprint : 'internal';

        return 'eagle-eye:metadata:v1:'.hash('sha256', $className.':'.$version);
    }

    /** @param class-string $className */
    private function remember(string $className, ActivityMetadata $metadata): ActivityMetadata
    {
        if (count($this->cache) >= self::CACHE_LIMIT) {
            array_shift($this->cache);
        }

        return $this->cache[$className] = $metadata;
    }
}
