<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Dotswan\EagleEye\Http\Resources\ActivityEntryResource;
use Illuminate\Http\Request;

it('freezes the v1 envelope shape (changing it requires a schema_version bump)', function (): void {
    $entry = apiEntry(1);
    $actual = (new ActivityEntryResource($entry))->toArray(Request::create('/', 'GET'));

    $fixture = __DIR__.'/snapshots/envelope-v1.json';

    if (! is_file($fixture) || false !== getenv('EAGLE_EYE_UPDATE_SNAPSHOTS')) {
        file_put_contents(
            $fixture,
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    $expected = json_decode((string) file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);

    expect($actual)->toBe($expected)
        ->and($actual['schema_version'])->toBe(ActivityEntryResource::ENVELOPE_SCHEMA_VERSION);
});
