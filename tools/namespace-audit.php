<?php

declare(strict_types=1);

$roots = ['src', 'config', 'lang', 'stubs'];
$allowed = ['Dotswan\\EagleEye', 'Illuminate'];
$violations = [];

$isAllowed = static function (string $symbol, string $path) use ($allowed): bool {
    $symbol = mb_ltrim(str_replace('\\\\', '\\', $symbol), '\\');

    $symfonyCaptureTypes = [
        dirname(__DIR__).'/src/Capture/HttpCapture.php'                           => ['Symfony\\Component\\HttpFoundation\\Response'],
        dirname(__DIR__).'/src/Http/Middleware/CaptureHttpActivityMiddleware.php' => ['Symfony\\Component\\HttpFoundation\\Response'],
        dirname(__DIR__).'/src/Capture/MailCapture.php'                           => ['Symfony\\Component\\Mime\\Email', 'Symfony\\Component\\Mime\\Address'],
        dirname(__DIR__).'/src/Capture/ConsoleCapture.php'                        => ['Symfony\\Component\\Console\\Input\\InputInterface'],
        dirname(__DIR__).'/src/Capture/ExceptionCapture.php'                      => ['Symfony\\Component\\Mailer\\Exception\\TransportExceptionInterface'],
    ];

    foreach ($symfonyCaptureTypes as $allowedPath => $symbols) {
        if (in_array($symbol, $symbols, true) && $path === $allowedPath) {
            return true;
        }
    }

    $externalCaptureTypes = [
        dirname(__DIR__).'/src/Capture/Outbound/GuzzleCaptureMiddleware.php' => ['Psr\\Http\\Message\\RequestInterface', 'Psr\\Http\\Message\\ResponseInterface'],
        dirname(__DIR__).'/src/Capture/OutboundHttpCapture.php'              => ['Psr\\Http\\Message\\RequestInterface'],
        dirname(__DIR__).'/src/Capture/Outbound/OutboundHttpRecorder.php'    => ['Psr\\Http\\Message\\RequestInterface', 'Psr\\Http\\Message\\ResponseInterface'],
        dirname(__DIR__).'/src/EagleEyeServiceProvider.php'                  => ['GuzzleHttp\\ClientInterface'],
    ];

    foreach ($externalCaptureTypes as $allowedPath => $symbols) {
        if (in_array($symbol, $symbols, true) && $path === $allowedPath) {
            return true;
        }
    }

    foreach ($allowed as $namespace) {
        if ($symbol === $namespace || str_starts_with($symbol, $namespace.'\\')) {
            return true;
        }
    }

    return str_contains($path, DIRECTORY_SEPARATOR.'Testing'.DIRECTORY_SEPARATOR)
        && ('PHPUnit' === $symbol || str_starts_with($symbol, 'PHPUnit\\'));
};

foreach ($roots as $root) {
    $path = dirname(__DIR__).DIRECTORY_SEPARATOR.$root;

    if (! is_dir($path)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

    foreach ($files as $file) {
        if (! $file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (false === $contents) {
            throw new RuntimeException("Unable to read {$file->getPathname()}");
        }

        foreach (['App\\', 'Modules\\'] as $forbidden) {
            if (str_contains(str_replace('\\\\', '\\', $contents), $forbidden)) {
                $violations[$file->getPathname().':'.$forbidden] = $file->getPathname().": forbidden namespace {$forbidden}";
            }
        }

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (! $isAllowed($token[1], $file->getPathname())) {
                $violations[$file->getPathname().':'.$token[1]] = $file->getPathname().": unexpected namespace {$token[1]}";
            }
        }
    }
}

if ([] !== $violations) {
    fwrite(STDERR, implode(PHP_EOL, $violations).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, 'Eagle Eye namespace audit passed.'.PHP_EOL);
