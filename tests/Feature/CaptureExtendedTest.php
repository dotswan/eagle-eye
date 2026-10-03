<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Exception\TransportException;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Capture\EventCapture;
use Dotswan\EagleEye\Capture\ExceptionDeduplicator;
use Dotswan\EagleEye\Capture\MailCapture;
use Dotswan\EagleEye\Capture\OutboundHttpCapture;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Testing\LoggedExceptionCollection;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Testing\EagleEyeFake;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Auth\GenericUser;
use Symfony\Component\Mime\Email;

final readonly class ProbeCaptureEvent
{
    public function __construct(public bool $fails = false)
    {
    }
}

final class ProbeEventCapture extends EventCapture
{
    public int $handled = 0;

    protected function listeners(): array
    {
        return [ProbeCaptureEvent::class => function (ProbeCaptureEvent $event): void {
            $this->handled++;

            if ($event->fails) {
                throw new RuntimeException('probe failure');
            }

            $this->record(ActivityDraft::make('probe.event'));
        }];
    }

    public function drops(string $class): bool
    {
        return $this->dropsEagleEyeWork($class);
    }
}

/** @return list<string> */
function extendedActions(EagleEyeFake $fake): array
{
    return array_map(static fn (ActivityEntry $entry): string => $entry->action, $fake->recorded());
}

it('suppresses and isolates generic event capture handlers', function (): void {
    $fake = EagleEye::fake();
    $capture = new ProbeEventCapture(app('events'), app());
    $capture->register();

    event(new ProbeCaptureEvent());
    app(CaptureSuppression::class)->run(static fn () => event(new ProbeCaptureEvent()));
    event(new ProbeCaptureEvent(true));

    expect($capture->handled)->toBe(2)
        ->and($capture->drops('Dotswan\\EagleEye\\InternalJob'))->toBeTrue()
        ->and($capture->drops('App\\Jobs\\BusinessJob'))->toBeFalse();
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'probe.event' === $entry->action);
});

it('captures queue lifecycle events end to end with the dispatching trace', function (): void {
    config()->set('queue.default', 'sync');
    $fake = EagleEye::fake();

    $origin = eagleEye()->record('origin.request');
    dispatch(new ProbeDispatchJob());

    expect($origin)->not->toBeNull();

    if (! $origin instanceof ActivityEntry) {
        return;
    }

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'queue.processed' === $entry->action
        && str_ends_with((string) ($entry->payload['job'] ?? ''), 'ProbeDispatchJob'));
    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'queue.processed' === $entry->action
            && $entry->trace->traceId === $origin->trace->traceId,
    );
});

it('captures console command lifecycle and skips eagle eye commands', function (): void {
    $fake = EagleEye::fake();

    $input = new ArrayInput(['--force' => true]);
    $output = new BufferedOutput();
    event(new CommandStarting('cache:clear', $input, $output));
    event(new CommandFinished('cache:clear', $input, $output, 0));
    event(new CommandFinished('activity-log:ship', new ArrayInput([]), new BufferedOutput(), 0));

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'console.started' === $entry->action);
    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'console.finished' === $entry->action
            && 0 === ($entry->payload['exit_code'] ?? null)
            && array_key_exists('arguments', $entry->payload)
            && array_key_exists('options', $entry->payload)
            && array_key_exists('memory_peak_mb', $entry->payload)
            && is_numeric($entry->payload['duration_ms'] ?? null),
    );
    $fake->assertNotLogged(
        static fn (ActivityEntry $entry): bool => 'activity-log:ship' === ($entry->payload['command'] ?? null),
    );
});

it('captures scheduled task lifecycle', function (): void {
    $fake = EagleEye::fake();
    $task = (new Schedule())->exec('echo hello');

    event(new ScheduledTaskFinished($task, 0.25));

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'schedule.finished' === $entry->action
            && ActivityChannel::Schedule === $entry->channel,
    );
});

it('captures mail details without message bodies', function (): void {
    config()->set('eagle-eye.capture.mail.events', ['sending', 'sent']);
    $fake = EagleEye::fake();
    (new MailCapture(app('events'), app()))->register();

    $email = (new Email())->subject('Welcome')->to('sara@example.test')->cc('cc@example.test')->text('body never stored');
    event(new MessageSending($email, ['mailer' => 'array']));

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'mail.sending' === $entry->action
            && 'Welcome' === ($entry->payload['subject'] ?? null)
            && 1 === ($entry->payload['to_count'] ?? null)
            && 1 === ($entry->payload['cc_count'] ?? null)
            && ['s***@example.test'] === ($entry->payload['to'] ?? null)
            && 'array' === ($entry->payload['mailer'] ?? null)
            && !str_contains((string) json_encode($entry->payload), 'body never stored'),
    );
});

it('stores full mail recipients when explicitly configured', function (): void {
    config()->set('eagle-eye.capture.mail.events', ['sending']);
    config()->set('eagle-eye.capture.mail.store_recipients', 'full');
    $fake = EagleEye::fake();
    (new MailCapture(app('events'), app()))->register();

    $email = (new Email())->subject('Welcome')->to('sara@example.test')->text('body never stored');
    event(new MessageSending($email, ['mailer' => 'array']));

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'mail.sending' === $entry->action
            && ['sara@example.test'] === ($entry->payload['to'] ?? null),
    );
});

it('captures notification failures and drops sending by default', function (): void {
    $fake = EagleEye::fake();
    $notification = new class () extends Notification {};

    event(new NotificationSending(new stdClass(), $notification, 'mail'));
    event(new NotificationFailed(new stdClass(), $notification, 'mail', ['message' => 'smtp down']));

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'notification.sending' === $entry->action);
    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'notification.failed' === $entry->action
            && 'mail' === ($entry->payload['channel'] ?? null)
            && 'smtp down' === ($entry->payload['error'] ?? null)
            && isset($entry->payload['notification_short']),
    );
});

it('captures outbound http traffic and injects trace headers', function (): void {
    $fake = EagleEye::fake();

    Http::fake(fn () => Http::response('ok'));

    $response = Http::get('https://api.example.test/orders');

    expect($response->ok())->toBeTrue();

    $fake->assertLogged(
        static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
            && 200 === ($entry->payload['status'] ?? null)
            && 'api.example.test' === ($entry->payload['host'] ?? null),
    );

    $psrRequest = new Psr7Request('GET', 'https://api.example.test/orders');
    $injected = OutboundHttpCapture::injectTraceHeaders($psrRequest);

    expect($injected->getHeaderLine('traceparent'))->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/')
        ->and($injected->getHeaderLine('X-Correlation-Id'))->not->toBe('');
});

it('dedupes repeated exception reports within one request', function (): void {
    $fake = EagleEye::fake();

    try {
        throw new RuntimeException('boom');
    } catch (RuntimeException $exception) {
        report($exception);
        report($exception);
        report($exception);
    }

    $matching = array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action);

    expect($matching)->toHaveCount(1);
});

it('maps every logged exception severity', function (): void {
    $fake = EagleEye::fake();
    $events = [
        new MessageLogged('emergency', 'one', ['exception' => new RuntimeException('one')]),
        new MessageLogged('alert', 'two', ['exception' => new RuntimeException('two')]),
        new MessageLogged('critical', 'three', ['exception' => new RuntimeException('three')]),
        new MessageLogged('warning', 'four', ['exception' => new RuntimeException('four')]),
        new MessageLogged('notice', 'five', ['exception' => new RuntimeException('five')]),
        new MessageLogged('info', 'six', ['exception' => new RuntimeException('six')]),
        new MessageLogged('debug', 'seven', ['exception' => new RuntimeException('seven')]),
        (new ReflectionClass(MessageLogged::class))->newInstanceArgs([
            'unknown',
            'eight',
            ['exception' => new RuntimeException('eight')],
        ]),
    ];

    foreach ($events as $event) {
        event($event);
    }

    $severities = array_values(array_map(
        static fn (ActivityEntry $entry): string => $entry->severity->value,
        array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action),
    ));

    expect($severities)->toBe(['emergency', 'alert', 'critical', 'warning', 'notice', 'info', 'debug', 'error']);
});

it('captures slow queries only when enabled over the threshold', function (): void {
    $fake = EagleEye::fake();

    DB::select('select 1');

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'query.slow' === $entry->action);
});

it('captures the same exception again after a worker scope resets', function (): void {
    $fake = EagleEye::fake();
    $exception = new RuntimeException('repeated across scopes');

    report($exception);
    report($exception);
    app(Application::class)->forgetScopedInstances();
    report($exception);
    report($exception);

    $matching = array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action);

    expect($matching)->toHaveCount(2);
});

it('does not suppress an exception in the next scope after logging was paused', function (): void {
    $fake = EagleEye::fake();
    $exception = new RuntimeException('paused scope');

    EagleEye::pause();
    report($exception);
    app(Application::class)->forgetScopedInstances();
    report($exception);

    $matching = array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action);

    expect($matching)->toHaveCount(1);
});

it('resolves exception deduplication from the active worker sandbox', function (): void {
    $fake = EagleEye::fake();
    $exception = new RuntimeException('sandbox exception');

    report($exception);
    $application = app(Application::class);
    $sandbox = clone $application;
    $sandbox->forgetScopedInstances();
    Container::setInstance($sandbox);

    try {
        report($exception);
        report($exception);
    } finally {
        Container::setInstance($application);
    }

    $matching = array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action);

    expect($matching)->toHaveCount(2);
});

it('bounds exception deduplication to the latest 256 distinct fingerprints', function (): void {
    $deduplicator = new ExceptionDeduplicator();

    for ($index = 0; $index < 1024; $index++) {
        expect($deduplicator->isDuplicate('exception-'.$index))->toBeFalse();
    }

    expect($deduplicator->isDuplicate('exception-1023'))->toBeTrue()
        ->and($deduplicator->isDuplicate('exception-768'))->toBeTrue()
        ->and($deduplicator->isDuplicate('exception-767'))->toBeFalse()
        ->and($deduplicator->isDuplicate('exception-0'))->toBeFalse();
});

it('does not retain reported exception objects', function (): void {
    $fake = EagleEye::fake();
    $exception = new RuntimeException('released after reporting');
    $reference = WeakReference::create($exception);

    event(new MessageLogged('error', 'exception', ['exception' => $exception]));
    $loggedExceptions = app(LoggedExceptionCollection::class);
    expect($loggedExceptions)->toHaveCount(1);
    $loggedExceptions->forget(0);
    unset($exception);

    expect($reference->get())->toBeNull();
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action);
});

it('recorded entries cover every activity channel', function (): void {
    $fake = EagleEye::fake();

    foreach (ActivityChannel::cases() as $channel) {
        eagleEye()->record('channel.probe', [], $channel);
    }

    $channels = array_unique(array_map(
        static fn (ActivityEntry $entry): string => $entry->channel->value,
        $fake->recorded(),
    ));

    expect($channels)->toHaveCount(13);
});

final class StateProbeModel extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}

final class ProbeDispatchJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        eagleEye()->record('probe.dispatched');
    }
}

it('records the guard on auth events', function (): void {
    $fake = EagleEye::fake();
    $user = new GenericUser(['id' => 7, 'name' => 'Sara']);

    event(new Login('web', $user, false));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'auth.login' === $entry->action
        && 'web' === ($entry->payload['guard'] ?? null)
        && str_contains($entry->title, 'web'));
});

it('raises severity and records state on a failing status transition', function (): void {
    $fake = EagleEye::fake();
    $model = new StateProbeModel();
    $model->setRawAttributes(['id' => 1, 'status' => 'pending'], true);
    $model->setAttribute('status', 'failed');

    event('eloquent.updated: '.StateProbeModel::class, [$model]);

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'model.updated' === $entry->action
        && ActivitySeverity::Warning === $entry->severity
        && 'status' === ($entry->payload['state']['field'] ?? null)
        && 'failed' === ($entry->payload['state']['new'] ?? null));
});

it('records mail.failed when a transport exception is reported', function (): void {
    $fake = EagleEye::fake();

    report(new TransportException('smtp down'));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'mail.failed' === $entry->action
        && 'smtp down' === ($entry->payload['error'] ?? null));
});

it('captures outbound response path, duration and error body', function (): void {
    $fake = EagleEye::fake();
    Http::fake(['api.example.test/*' => Http::response('{"error":"boom"}', 500, ['Content-Type' => 'application/json'])]);

    Http::get('https://api.example.test/orders?page=2');

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action
        && '/orders' === ($entry->payload['path'] ?? null)
        && ['page'] === ($entry->payload['query_keys'] ?? null)
        && 500 === ($entry->payload['status'] ?? null)
        && is_int($entry->payload['duration_ms'] ?? null)
        && ['error' => 'boom'] === ($entry->payload['response_body'] ?? null));
    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.request' === $entry->action);
});

it('does not capture outbound calls to except hosts or under suppression', function (): void {
    config()->set('eagle-eye.capture.outbound_http.except_hosts', ['api.example.test']);
    $fake = EagleEye::fake();
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://api.example.test/orders');
    app(CaptureSuppression::class)->run(static fn () => Http::get('https://other.example.test/orders'));

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.response' === $entry->action);
});

it('skips an update recorded before the created model syncs its original', function (): void {
    $fake = EagleEye::fake();
    $model = new StateProbeModel();
    $model->setRawAttributes(['id' => 1, 'status' => 'pending'], false);

    event('eloquent.created: '.StateProbeModel::class, [$model]);
    event('eloquent.updated: '.StateProbeModel::class, [$model]);

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'model.updated' === $entry->action);
});

it('does not capture read-only artisan commands', function (): void {
    $fake = EagleEye::fake();
    $input = new ArrayInput([]);
    $output = new BufferedOutput();

    event(new CommandStarting('route:list', $input, $output));
    event(new CommandFinished('route:list', $input, $output, 0));
    event(new CommandStarting('config:show', $input, $output));
    event(new CommandFinished('config:show', $input, $output, 0));

    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => in_array(
        $entry->payload['command'] ?? null,
        ['route:list', 'config:show'],
        true,
    ));
});

it('records duration for a Laravel Http connection failure', function (): void {
    $fake = EagleEye::fake();
    $clientRequest = new ClientRequest(new Psr7Request('GET', 'https://api.example.test/orders'));

    event(new RequestSending($clientRequest));
    event(new ConnectionFailed($clientRequest, new ConnectionException('refused')));

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'outbound_http.failed' === $entry->action
        && is_int($entry->payload['duration_ms'] ?? null)
        && 'refused' === ($entry->payload['error'] ?? null));
});
