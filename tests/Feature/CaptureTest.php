<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\DoNotLog;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Http\Middleware\CaptureHttpActivityMiddleware;
use Dotswan\EagleEye\Tests\Support\AlwaysFailingDriver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Dotswan\EagleEye\Capture\HttpCapture;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaptureTestPost extends Model
{
    protected $table = 'capture_posts';

    protected $guarded = [];

    public $timestamps = false;
}

enum CapturePostState: string
{
    case Draft = 'draft';
    case Published = 'published';
}

class CaptureCastPost extends Model
{
    protected $table = 'capture_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'meta'         => 'array',
            'state'        => CapturePostState::class,
        ];
    }
}

#[ActivitySubject('hidden_post')]
#[DoNotLog]
class HiddenCapturePost extends Model
{
    protected $table = 'capture_posts';

    protected $guarded = [];

    public $timestamps = false;
}

final class CaptureChainJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        eagleEye()->record('chain.job');
    }
}

beforeEach(function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('capture_posts');
    $schema->create('capture_posts', function ($table): void {
        $table->increments('id');
        $table->string('title')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->text('meta')->nullable();
        $table->string('state')->nullable();
        foreach (['notes_a', 'notes_b', 'notes_c', 'notes_d', 'notes_e'] as $column) {
            $table->text($column)->nullable();
        }
    });

    Route::get('/eagle-eye-probe', fn (): string => 'ok');
    Route::get('/eagle-eye-error', fn (): ResponseFactory|Response => response('boom', 500));
});

it('captures model writes with zero application code', function (): void {
    $fake = EagleEye::fake();

    $post = CaptureTestPost::create(['title' => 'Quarterly report', 'status' => 'draft']);
    $post->update(['status' => 'approved', 'notes_a' => 'final']);

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'model.created' === $entry->action
            && ActivityChannel::Model === $entry->channel
            && ['old' => null, 'new' => 'draft'] === ($entry->payload['changes']['status'] ?? null),
    );
    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'model.updated' === $entry->action
            && 2 === count($entry->payload['changes'] ?? [])
            && ['old' => 'draft', 'new' => 'approved'] === ($entry->payload['changes']['status'] ?? null)
            && null === ($entry->payload['changes']['title']['new'] ?? null),
    );
});

it('captures raw storage values for cast attributes on both sides', function (): void {
    $fake = EagleEye::fake();

    $post = CaptureCastPost::create([
        'title'        => 'Cast',
        'published_at' => '2026-09-15 12:59:58',
        'meta'         => ['a' => 1],
        'state'        => CapturePostState::Draft,
    ]);
    $post->update([
        'published_at' => '2026-09-15 13:00:00',
        'meta'         => ['a' => 2],
        'state'        => CapturePostState::Published,
    ]);

    $fake->assertLogged(static function (ActivityEntry $entry): bool {
        if ('model.updated' !== $entry->action) {
            return false;
        }

        $changes = $entry->payload['changes'] ?? [];

        return ['old' => '2026-09-15 12:59:58', 'new' => '2026-09-15 13:00:00'] === ($changes['published_at'] ?? null)
            && ['old' => '{"a":1}', 'new' => '{"a":2}'] === ($changes['meta'] ?? null)
            && ['old' => 'draft', 'new' => 'published'] === ($changes['state'] ?? null);
    });
});

it('captures committed model writes and omits rolled back writes', function (): void {
    $fake = EagleEye::fake();

    DB::beginTransaction();
    CaptureTestPost::create(['title' => 'rolled back']);
    DB::rollBack();

    $fake->assertNotLogged(
        static fn (ActivityEntry $entry): bool => 'rolled back' === ($entry->payload['changes']['title']['new'] ?? null),
    );

    DB::beginTransaction();
    CaptureTestPost::create(['title' => 'committed']);
    DB::commit();

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'committed' === ($entry->payload['changes']['title']['new'] ?? null),
    );
});

it('captures auth events with zero application code', function (): void {
    $fake = EagleEye::fake();
    app()->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.10']));

    event(new Login('web', new GenericUser(['id' => 42, 'name' => 'Sara']), false));

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'auth.login' === $entry->action
            && 'user' === $entry->actor?->type
            && '42' === $entry->actor->id
            && 'Sara' === $entry->actor->label
            && '192.0.2.0' === $entry->request?->ip,
    );
});

it('suppresses auth event capture inside internal operations', function (): void {
    $fake = EagleEye::fake();

    app(CaptureSuppression::class)->run(
        static function (): void {
            event(new Login('web', new GenericUser(['id' => 42]), false));
        },
    );

    $fake->assertCount(0);
});

it('captures http requests after the response is sent', function (): void {
    $fake = EagleEye::fake();
    $kernel = app(Kernel::class);

    $probeRequest = Request::create('/eagle-eye-probe?tab=summary', 'GET');
    $responserose = $kernel->handle($probeRequest);
    $kernel->terminate($probeRequest, $responserose);

    $errorRequest = Request::create('/eagle-eye-error', 'GET');
    $errorResponserose = $kernel->handle($errorRequest);
    $kernel->terminate($errorRequest, $errorResponserose);

    expect($responserose->getStatusCode())->toBe(200)
        ->and($errorResponserose->getStatusCode())->toBe(500);

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
            && 200 === $entry->request?->statusCode
            && ['tab' => 'summary'] === ($entry->payload['request']['query'] ?? []),
    );
    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
            && 500 === $entry->request?->statusCode
            && ! array_key_exists('body', $entry->payload['response'] ?? [])
            && in_array('status:5xx', $entry->tags, true),
    );
});

it('captures framework response variants without consuming or changing them', function (string $kind): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.response.body', 'always');
    $streamed = false;
    $file = tempnam(sys_get_temp_dir(), 'eagle-eye-download-');
    expect($file)->toBeString();
    file_put_contents($file, 'download contents');

    try {
        $response = match ($kind) {
            'json'     => new JsonResponse(['ok' => true]),
            'redirect' => new RedirectResponse('/destination'),
            'stream'   => new StreamedResponse(function () use (&$streamed): void {
                $streamed = true;
            }),
            'download' => new BinaryFileResponse($file),
            default    => new Response('ordinary'),
        };
        $response->headers->set('X-Application-Header', 'preserved');
        Route::get('/eagle-eye-response-variant', static fn (): SymfonyResponse => $response);
        $request = Request::create('/eagle-eye-response-variant');
        $request->headers->set('X-Correlation-Id', 'response-variant');
        $kernel = app(Kernel::class);
        $actual = $kernel->handle($request);
        $content = $actual->getContent();
        $kernel->terminate($request, $actual);

        expect($actual)->toBe($response)
            ->and($actual->getContent())->toBe($content)
            ->and($actual->headers->get('X-Application-Header'))->toBe('preserved')
            ->and($actual->headers->get('X-Correlation-Id'))->toBe('response-variant')
            ->and($streamed)->toBeFalse();
        $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
            && $response->getStatusCode() === $entry->request?->statusCode
            && 'response-variant' === $entry->trace->correlationId
            && (in_array($kind, ['stream', 'download'], true)
                ? ! array_key_exists('body', $entry->payload['response'] ?? [])
                : true));
    } finally {
        unlink($file);
    }
})->with(['ordinary', 'json', 'redirect', 'stream', 'download']);

it('rejects HTTP capture before collecting excluded request parts', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.parts', ['body']);
    $request = new class () extends Request {
        public bool $bodyRead = false;

        public function getContent(bool $asResource = false): mixed
        {
            $this->bodyRead = true;

            return parent::getContent($asResource);
        }
    };
    $request->initialize(server: ['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/up', 'CONTENT_TYPE' => 'application/json']);
    app(HttpCapture::class)->capture($request, new Response('ok'));

    expect($request->bodyRead)->toBeFalse();
    $fake->assertCount(0);
});

it('does not collect withheld request parts on an accepted HTTP event', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.parts', ['body']);
    config()->set('eagle-eye.filters.request_parts.except', ['body']);
    $request = new class () extends Request {
        public bool $bodyRead = false;

        public function getContent(bool $asResource = false): mixed
        {
            $this->bodyRead = true;

            return parent::getContent($asResource);
        }
    };
    $request->initialize(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/orders', 'CONTENT_TYPE' => 'application/json']);
    app(HttpCapture::class)->capture($request, new Response('ok'));

    expect($request->bodyRead)->toBeFalse();
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action);
});

it('captures JSON input and redacts structured error responses', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.http.parts', ['body']);
    $request = Request::create('/orders', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"status":"draft","password":"input-secret"}');
    app(HttpCapture::class)->capture($request, new JsonResponse(['error' => ['token' => 'response-secret'], 'code' => 'invalid'], 422));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'draft' === ($entry->payload['request']['body']['status'] ?? null)
        && 'invalid' === ($entry->payload['response']['body']['code'] ?? null));
    expect(json_encode($fake->recorded(), JSON_THROW_ON_ERROR))->not->toContain('input-secret', 'response-secret');
});

it('omits oversized and opaque response bodies', function (string $body, string $contentType): void {
    $fake = EagleEye::fake();
    app(HttpCapture::class)->capture(Request::create('/orders'), new Response($body, 500, ['Content-Type' => $contentType]));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'http.request' === $entry->action
        && ! array_key_exists('body', $entry->payload['response'] ?? []));
    expect(json_encode($fake->recorded(), JSON_THROW_ON_ERROR))->not->toContain('unstructured-secret');
})->with([
    ['unstructured-secret', 'text/plain'],
    ['"unstructured-secret"', 'application/json'],
    ['{"password":"unstructured-secret"', 'application/json'],
    [str_repeat(' ', 16_385).'{"password":"unstructured-secret"}', 'application/json'],
]);

it('builds a correlated chain across http model and queue entries', function (): void {
    $fake = EagleEye::fake();
    config()->set('queue.default', 'sync');

    Route::get('/eagle-eye-chain', function (): string {
        CaptureTestPost::create(['title' => 'chain', 'status' => 'new']);
        dispatch(new CaptureChainJob());

        return 'done';
    });

    $kernel = app(Kernel::class);
    $chainRequest = Request::create('/eagle-eye-chain', 'GET');
    $chainRequest->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
    $chainRequest->headers->set('X-Correlation-Id', 'chain-request');
    $chainResponserose = $kernel->handle($chainRequest);
    $kernel->terminate($chainRequest, $chainResponserose);

    expect($chainResponserose->getStatusCode())->toBe(200);

    $entries = $fake->recorded();
    $actions = array_map(static fn (ActivityEntry $entry): string => $entry->action, $entries);
    $traces = array_unique(array_map(static fn (ActivityEntry $entry): string => $entry->trace->traceId, $entries));

    expect($actions)->toContain('model.created')
        ->and($actions)->toContain('chain.job')
        ->and($actions)->toContain('http.request')
        ->and($traces)->toBe(['4bf92f3577b34da6a3ce929d0e0e4736'])
        ->and($chainResponserose->headers->get('X-Correlation-Id'))->toBe('chain-request');
});

it('never causes lazy loading violations while capturing models', function (): void {
    $fake = EagleEye::fake();
    Model::preventLazyLoading();

    try {
        $post = CaptureTestPost::create(['title' => 'lazy', 'status' => 'new']);
        $post->update(['status' => 'done']);
    } finally {
        Model::preventLazyLoading(false);
    }

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'model.updated' === $entry->action);
});

it('never breaks the response when storage is down', function (): void {
    config()->set('eagle-eye.transport.buffer', 1);
    config()->set('eagle-eye.transport.spool.path', storage_path('eagle-eye/capture-'.uniqid().'/spool.log'));

    $manager = app(DriverManager::class);
    $manager->extend('failing', static fn (): AlwaysFailingDriver => new AlwaysFailingDriver());
    $manager->forgetDrivers();
    config()->set('eagle-eye.default', 'failing');

    $kernel = app(Kernel::class);
    $outageRequest = Request::create('/eagle-eye-probe', 'GET');
    $outageResponserose = $kernel->handle($outageRequest);
    $kernel->terminate($outageRequest, $outageResponserose);

    expect($outageResponserose->getStatusCode())->toBe(200);
});

it('suppresses capture through do-not-log attributes and model filters', function (): void {
    $fake = EagleEye::fake();

    config()->set('eagle-eye.filters.models.except', [CaptureTestPost::class]);

    HiddenCapturePost::create(['title' => 'hidden']);
    CaptureTestPost::create(['title' => 'filtered']);

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => ActivityChannel::Model === $entry->channel);
});

it('suppresses capture inside eagle eye silent blocks', function (): void {
    $fake = EagleEye::fake();

    eagle_eye_silent(function (): void {
        CaptureTestPost::create(['title' => 'quiet']);
    });

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => ActivityChannel::Model === $entry->channel);
});

it('supplies middleware handle in far below the latency budget', function (): void {
    $middleware = app(CaptureHttpActivityMiddleware::class);
    $durations = [];

    for ($i = 0; $i < 1_000; $i++) {
        $request = Request::create('/eagle-eye-probe', 'GET');
        $started = hrtime(true);
        $middleware->handle($request, static fn (): Response => new Response('ok', 200));
        $durations[] = (hrtime(true) - $started) / 1_000; // microseconds
    }

    sort($durations);
    $p95 = $durations[(int) (0.95 * (count($durations) - 1))];

    expect($p95)->toBeLessThan(2_000.0); // 2 ms in microseconds
})->group('performance');
