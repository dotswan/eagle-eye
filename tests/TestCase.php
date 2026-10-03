<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tests;

use Dotswan\EagleEye\EagleEyeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [EagleEyeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('e', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('eagle-eye.security_key', str_repeat('s', 32));
        $app['config']->set('eagle-eye.default', 'null');
        $app['config']->set('eagle-eye.privacy.actor_label', 'keep');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
