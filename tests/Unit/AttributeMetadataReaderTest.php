<?php

declare(strict_types=1);

use Dotswan\EagleEye\Attributes\ActivityField;
use Dotswan\EagleEye\Attributes\ActivityLabel;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Attributes\DoNotLog;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

#[ActivitySubject('campaign_request')]
#[ActivityLabel('reference')]
#[ActivityTags('gdpr', 'pii')]
#[DoNotLog]
final class MetadataReaderSubject
{
    #[ActivityField(label: 'Approval status', redact: RedactionMode::Hash)]
    public string $status = 'approved';
}

it('reads all package activity attributes', function (): void {
    $metadata = (new AttributeMetadataReader())->read(MetadataReaderSubject::class);

    expect($metadata->subjectType)->toBe('campaign_request')
        ->and($metadata->labelAttribute)->toBe('reference')
        ->and($metadata->tags)->toBe(['gdpr', 'pii'])
        ->and($metadata->doNotLog)->toBeTrue()
        ->and($metadata->fields['status']->label)->toBe('Approval status')
        ->and($metadata->fields['status']->redact)->toBe(RedactionMode::Hash);
});

it('reuses persistent metadata across reader instances', function (): void {
    $metadata = (new AttributeMetadataReader())->read(MetadataReaderSubject::class);
    $cache = Mockery::mock(CacheRepository::class);

    if (! $cache instanceof CacheRepository) {
        throw new LogicException('Cache repository mock unavailable.');
    }

    $cache->shouldReceive('get')->once()->andReturn($metadata);
    $cache->shouldNotReceive('forever');

    expect((new AttributeMetadataReader($cache))->read(MetadataReaderSubject::class))->toBe($metadata);
});

it('falls back to reflection when the persistent cache fails', function (): void {
    $cache = Mockery::mock(CacheRepository::class);

    if (! $cache instanceof CacheRepository) {
        throw new LogicException('Cache repository mock unavailable.');
    }

    $cache->shouldReceive('get')->once()->andThrow(new RuntimeException('cache unavailable'));
    $cache->shouldReceive('forever')->once()->andThrow(new RuntimeException('cache unavailable'));

    expect((new AttributeMetadataReader($cache))->read(MetadataReaderSubject::class)->subjectType)
        ->toBe('campaign_request');
});

it('bounds process metadata and fingerprints source changes', function (): void {
    $reader = new AttributeMetadataReader();

    foreach (range(1, 300) as $sequence) {
        $class = 'MetadataBoundedSubject'.$sequence;

        if (! class_exists($class, false)) {
            eval("final class {$class} {}");
        }

        $reader->read($class);
    }

    $cacheProperty = new ReflectionProperty($reader, 'cache');
    expect($cacheProperty->getValue($reader))->toHaveCount(256);

    $class = 'MetadataFingerprintSubject'.bin2hex(random_bytes(4));
    $file = tempnam(sys_get_temp_dir(), 'eagle-eye-metadata-');

    if (! is_string($file)) {
        throw new RuntimeException('Unable to create metadata fixture.');
    }

    file_put_contents($file, "<?php final class {$class}{}");
    require $file;
    $cacheKey = new ReflectionMethod($reader, 'cacheKey');
    $before = $cacheKey->invoke($reader, $class);
    file_put_contents($file, "<?php final class {$class} { }");
    clearstatcache(true, $file);
    $after = $cacheKey->invoke($reader, $class);
    unlink($file);

    expect($after)->not->toBe($before);
});
