<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Dotswan\EagleEye\Http\Resources\ActivityEntryResource;
use Illuminate\Http\Request;

/** @return list<string> */
function tsInterfaceFields(string $definitions, string $interface): array
{
    if (! preg_match('/interface '.$interface.' \{(.*?)\n\}/s', $definitions, $matches)) {
        return [];
    }

    preg_match_all('/^\s+([a-z_]+)[\?:]?[;:]/m', (string) $matches[1], $fields);

    return $fields[1];
}

it('keeps the committed typescript contract in sync with the envelope', function (): void {
    $definitions = (string) file_get_contents(__DIR__.'/../../../resources/js/types/eagle-eye.d.ts');

    $entry = (new ActivityEntryResource(apiEntry(1)))->toArray(Request::create('/', 'GET'));

    expect(tsInterfaceFields($definitions, 'EagleEyeActivityEntry'))->toBe(array_keys($entry));
    expect(tsInterfaceFields($definitions, 'EagleEyeActor'))->toBe(array_keys($entry['actor']));
    expect(tsInterfaceFields($definitions, 'EagleEyeSubject'))->toBe(array_keys($entry['subject']));
    expect(tsInterfaceFields($definitions, 'EagleEyeTrace'))->toBe(array_keys($entry['trace']));
    expect(tsInterfaceFields($definitions, 'EagleEyeRequestSnapshot'))->toBe(array_keys($entry['request']));
    expect(tsInterfaceFields($definitions, 'EagleEyeChange'))->toBe(array_keys($entry['changes'][0]));
});
