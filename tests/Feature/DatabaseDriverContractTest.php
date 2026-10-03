<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Dotswan\EagleEye\Testing\DriverContractTests;

beforeEach(function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');
});

DriverContractTests::define('database', static fn (): DatabaseDriver => new DatabaseDriver(app('db')->connection()));
DriverContractTests::defineSeries('database', static fn (): DatabaseDriver => new DatabaseDriver(app('db')->connection()));
