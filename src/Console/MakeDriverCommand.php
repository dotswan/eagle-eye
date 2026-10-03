<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;

final class MakeDriverCommand extends EagleEyeCommand
{
    protected $signature = 'activity-log:make-driver {name} {--path=}';

    protected $description = 'Scaffold a custom driver and its contract test.';

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->stringArgument('name'));

        if ('' === $name || 1 !== preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
            $this->error('Driver name must contain letters and numbers only.');

            return self::INVALID;
        }

        $root = $this->option('path');
        $root = is_string($root) && '' !== $root ? $root : app_path('ActivityLog');
        $driverPath = $root.'/Drivers/'.$name.'Driver.php';
        $testPath = $root.'/Tests/'.$name.'DriverContractTest.php';
        $configPath = $root.'/Config/'.Str::kebab($name).'.php';

        if ($files->exists($driverPath) || $files->exists($testPath) || $files->exists($configPath)) {
            $this->error('Driver, configuration, or contract test already exists.');

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($driverPath));
        $files->ensureDirectoryExists(dirname($testPath));
        $files->ensureDirectoryExists(dirname($configPath));
        $files->put($driverPath, $this->driverStub($name));
        $files->put($testPath, $this->testStub($name));
        $files->put($configPath, $this->configStub($name));

        $this->info("Created [{$driverPath}], [{$configPath}], and [{$testPath}].");

        return self::SUCCESS;
    }

    private function driverStub(string $name): string
    {
        $namespace = $this->hostNamespace().'\\ActivityLog\\Drivers';

        return str_replace(
            ['{{ name }}', '{{ namespace }}'],
            [$name, $namespace],
            <<<'PHP'
<?php

declare(strict_types=1);

namespace {{ namespace }};

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Enums\Capability;
use LogicException;

final class {{ name }}Driver implements FullActivityLogDriverInterface
{
    public function write(ActivityEntry $entry): void
    {
        throw new LogicException('Implement persistent activity storage.');
    }

    public function writeBatch(array $entries): array
    {
        throw new LogicException('Implement persistent batch storage.');
    }

    public function name(): string
    {
        return '{{ name }}';
    }

    public function healthy(): bool
    {
        return false;
    }

    public function supports(Capability $capability): bool
    {
        return false;
    }

    public function find(string $id): ?ActivityEntry
    {
        throw new LogicException('Implement activity lookup.');
    }

    public function search(ActivityQuery $query): QueryResult
    {
        throw new LogicException('Implement activity search.');
    }

    public function count(ActivityQuery $query): int
    {
        throw new LogicException('Implement activity count.');
    }

    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        throw new LogicException('Implement activity aggregation.');
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        throw new LogicException('Implement bounded activity ranking.');
    }

    public function tags(?string $prefix = null, int $limit = 50): array
    {
        throw new LogicException('Implement tag lookup.');
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        throw new LogicException('Implement retention pruning.');
    }

    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        throw new LogicException('Implement subject erasure.');
    }

    public function install(): void
    {
        throw new LogicException('Implement driver installation.');
    }

    public function stats(): array
    {
        throw new LogicException('Implement driver statistics.');
    }
}
PHP,
        );
    }

    private function testStub(string $name): string
    {
        $namespace = $this->hostNamespace().'\\ActivityLog\\Drivers';

        return str_replace(
            ['{{ name }}', '{{ namespace }}'],
            [$name, $namespace],
            <<<'PHP'
<?php

declare(strict_types=1);

use {{ namespace }}\{{ name }}Driver;
use Dotswan\EagleEye\Testing\DriverContractTests;

DriverContractTests::define('{{ name }}', fn (): {{ name }}Driver => new {{ name }}Driver());
PHP,
        );
    }

    private function configStub(string $name): string
    {
        $namespace = $this->hostNamespace().'\\ActivityLog\\Drivers';

        return str_replace(
            ['{{ name }}', '{{ namespace }}'],
            [$name, $namespace],
            <<<'PHP'
<?php

declare(strict_types=1);

use {{ namespace }}\{{ name }}Driver;

return [
    'driver' => {{ name }}Driver::class,
];
PHP,
        );
    }

    private function hostNamespace(): string
    {
        $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $mappings = is_array($manifest) ? ($manifest['autoload']['psr-4'] ?? []) : [];

        if (is_array($mappings)) {
            foreach ($mappings as $namespace => $path) {
                if (is_string($namespace) && is_string($path) && 'app/' === mb_rtrim($path, '/').'/') {
                    return mb_rtrim($namespace, '\\');
                }
            }
        }

        throw new LogicException('The host Composer manifest must define a PSR-4 namespace for app/.');
    }
}
