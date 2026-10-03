<?php

declare(strict_types=1);

use Dotswan\EagleEye\Capture\HttpCapture;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Facades\EagleEye;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('captures opt-in request and response bodies for a matched route', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['orders/*']);
    $request = Request::create(
        '/orders/store',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"status":"draft","email":"buyer@example.com"}',
    );
    $request->files->set('document', UploadedFile::fake()->create('secret.pdf', 100, 'application/pdf'));

    app(HttpCapture::class)->capture($request, new JsonResponse(['id' => 12, 'state' => 'created'], 201));

    $fake->assertLogged(static function (ActivityEntry $entry): bool {
        $body = $entry->payload['request']['body'] ?? [];
        $files = $entry->payload['request']['files'] ?? [];

        return 'http.request' === $entry->action
            && 'draft' === ($body['status'] ?? null)
            && 'b***@example.com' === ($body['email'] ?? null)
            && 12 === ($entry->payload['response']['body']['id'] ?? null)
            && 's***' === ($files[0]['name'] ?? null)
            && ! array_key_exists('path', $files[0] ?? [])
            && ! array_key_exists('tmp_name', $files[0] ?? []);
    });
});

it('captures multipart form bodies when the route matches', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['checkout']);
    $request = Request::create('/checkout', 'POST', ['first_name' => 'Jane', 'last_name' => 'Doe', 'quantity' => '2']);

    app(HttpCapture::class)->capture($request, new Response('ok', 200));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'J***' === ($entry->payload['request']['body']['first_name'] ?? null)
        && 'D***' === ($entry->payload['request']['body']['last_name'] ?? null)
        && '2' === ($entry->payload['request']['body']['quantity'] ?? null));
});

it('stores no body for an unmatched route', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['admin/*']);
    $request = Request::create('/orders/store', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"status":"draft"}');

    app(HttpCapture::class)->capture($request, new JsonResponse(['id' => 12], 201));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
        && ! array_key_exists('body', $entry->payload['request'] ?? [])
        && ! array_key_exists('body', $entry->payload['response'] ?? []));
});

it('omits a form body over the configured byte limit', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['orders.store']);
    config()->set('eagle-eye.capture.http.body_max_bytes', 1024);
    $request = Request::create('/orders.store', 'POST', ['notes' => str_repeat('x', 5000)]);

    app(HttpCapture::class)->capture($request, new Response('ok', 200));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'body_too_large' === ($entry->payload['request']['body']['__omitted'] ?? null));
});

it('keeps a global request part exception ahead of a matched body route', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['orders.store']);
    config()->set('eagle-eye.filters.request_parts.except', ['body']);
    $request = Request::create('/orders.store', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"status":"draft"}');

    app(HttpCapture::class)->capture($request, new Response('ok', 200));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
        && ! array_key_exists('body', $entry->payload['request'] ?? []));
});

it('still records a matched route when an upload is invalid', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['orders.store']);
    $request = Request::create('/orders.store', 'POST');
    $request->files->set('document', new UploadedFile(sys_get_temp_dir().'/missing-upload-'.uniqid(), 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true));

    app(HttpCapture::class)->capture($request, new Response('ok', 200));

    $fake->assertLogged(static function (ActivityEntry $entry): bool {
        $file = $entry->payload['request']['files'][0] ?? [];

        return 'http.request' === $entry->action
            && 'b***' === ($file['name'] ?? null)
            && null === ($file['size'] ?? null)
            && UPLOAD_ERR_INI_SIZE === ($file['error'] ?? null);
    });
});

it('records streamed and binary responses on a matched route without a body', function (string $kind): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.body_routes', ['exports/*']);
    $streamed = false;
    $file = tempnam(sys_get_temp_dir(), 'eagle-eye-body-');
    expect($file)->toBeString();
    file_put_contents($file, 'binary contents');

    try {
        $response = 'stream' === $kind
            ? new StreamedResponse(function () use (&$streamed): void {
                $streamed = true;
            }, 200)
            : new BinaryFileResponse($file);

        app(HttpCapture::class)->capture(Request::create('/exports/report', 'GET'), $response);

        expect($streamed)->toBeFalse();
        $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
            && 200 === $entry->request?->statusCode
            && ! array_key_exists('body', $entry->payload['response'] ?? []));
    } finally {
        unlink($file);
    }
})->with(['stream', 'download']);
