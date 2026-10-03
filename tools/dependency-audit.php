<?php

declare(strict_types=1);

$manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$requirements = is_array($manifest) && isset($manifest['require']) && is_array($manifest['require'])
    ? array_keys($manifest['require'])
    : [];
$violations = array_values(array_filter(
    $requirements,
    static fn (string $package): bool => ! in_array($package, ['php', 'ext-mbstring'], true)
        && ! str_starts_with($package, 'illuminate/'),
));

if ([] !== $violations) {
    fwrite(STDERR, 'Forbidden runtime dependencies: '.implode(', ', $violations).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, 'Eagle Eye runtime dependency audit passed.'.PHP_EOL);
