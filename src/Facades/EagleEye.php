<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Facades;

use Closure;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Drivers\MemoryDriver;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Repository\ActivityTrail;
use Dotswan\EagleEye\Testing\EagleEyeFake;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static ActivityEntry|null record(ActivityDraft|string $activity, array<string, mixed> $payload = [], ActivityChannel $channel = ActivityChannel::Custom, ActivitySeverity $severity = ActivitySeverity::Info)
 * @method static ActivityTrail query(?ActivityQuery $query = null)
 * @method static void pause()
 * @method static void resume()
 * @method static bool isPaused()
 * @method static mixed withTags(list<string> $tags, Closure $callback)
 * @method static mixed withoutLogging(Closure $callback)
 */
final class EagleEye extends Facade
{
    protected static $cached = false;

    public static function fake(int $maxEntries = 1000): EagleEyeFake
    {
        $application = self::getFacadeApplication();

        if (null === $application) {
            throw new RuntimeException('Eagle Eye facade has no application instance.');
        }

        $driver = new MemoryDriver($maxEntries);
        $manager = $application->make(DriverManager::class);
        $manager->extend('memory', fn (): MemoryDriver => $driver);
        $manager->forgetDrivers();
        $application->make(ConfigRepository::class)->set('eagle-eye.default', 'memory');
        $application->make(ConfigRepository::class)->set('eagle-eye.transport.buffer', 1);

        return new EagleEyeFake($driver);
    }

    protected static function getFacadeAccessor(): string
    {
        return EagleEyeInterface::class;
    }
}
