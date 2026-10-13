<?php

declare(strict_types=1);

namespace Dotswan\EagleEye;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use GuzzleHttp\ClientInterface;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Contracts\Foundation\Application;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\RecorderInterface;
use Dotswan\EagleEye\Contracts\Resolution\FieldLabelResolverInterface;
use Dotswan\EagleEye\Contracts\Resolution\PayloadNormalizerInterface;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Contracts\Sampling\SamplerInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActivityIdentityResolverInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActivityEntryPresenterInterface;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Contracts\Security\DurableRevealAuditInterface;
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface;
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Correlation\W3CTracePropagator;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Capture\CaptureAdapterInterface;
use Dotswan\EagleEye\Capture\AuthCapture;
use Dotswan\EagleEye\Capture\ConsoleCapture;
use Dotswan\EagleEye\Capture\EloquentCapture;
use Dotswan\EagleEye\Capture\ExceptionCapture;
use Dotswan\EagleEye\Capture\ExceptionDeduplicator;
use Dotswan\EagleEye\Capture\MailCapture;
use Dotswan\EagleEye\Capture\NotificationCapture;
use Dotswan\EagleEye\Capture\OutboundHttpCapture;
use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Capture\Query\QuerySummaryEnricher;
use Dotswan\EagleEye\Capture\Query\SqlFingerprint;
use Dotswan\EagleEye\Capture\QueryCapture;
use Dotswan\EagleEye\Capture\QueueCapture;
use Dotswan\EagleEye\Console\LokiConfigCommand;
use Dotswan\EagleEye\Console\LokiShipSpoolCommand;
use Dotswan\EagleEye\Console\ShipSpoolCommand;
use Dotswan\EagleEye\Console\CacheCommand;
use Dotswan\EagleEye\Console\DoctorCommand;
use Dotswan\EagleEye\Console\ExportCommand;
use Dotswan\EagleEye\Console\ForgetCommand;
use Dotswan\EagleEye\Console\MakeDriverCommand;
use Dotswan\EagleEye\Console\PruneCommand;
use Dotswan\EagleEye\Console\StatsCommand;
use Dotswan\EagleEye\Console\TailCommand;
use Dotswan\EagleEye\Console\TraceCommand;
use Dotswan\EagleEye\Console\TrailCommand;
use Dotswan\EagleEye\Console\VerifyCommand;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Dotswan\EagleEye\Drivers\DriverSettings;
use Dotswan\EagleEye\Data\PayloadBudget;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Redaction\Redactor;
use Dotswan\EagleEye\Redaction\Strategies\DropStrategy;
use Dotswan\EagleEye\Redaction\Strategies\EncryptStrategy;
use Dotswan\EagleEye\Redaction\Strategies\HashStrategy;
use Dotswan\EagleEye\Redaction\Strategies\MaskStrategy;
use Dotswan\EagleEye\Redaction\Strategies\PartialMaskStrategy;
use Dotswan\EagleEye\Sampling\DeterministicSampler;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Http\ApiRoutes;
use Dotswan\EagleEye\Drivers\IndexDriver;
use Dotswan\EagleEye\Drivers\MultiDriver;
use Dotswan\EagleEye\Drivers\Loki\LokiDriver;
use Dotswan\EagleEye\Drivers\Loki\LokiLabelGuard;
use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\LokiQueryTranslator;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Dotswan\EagleEye\Drivers\Loki\Writers\PushWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\SpoolWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\StdoutWriter;
use Dotswan\EagleEye\Filter\FilterChain;
use Dotswan\EagleEye\Filter\Gate;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Dotswan\EagleEye\Pipeline\Pipes\RunEnrichers;
use Dotswan\EagleEye\Pipeline\PipelineRunner;
use Dotswan\EagleEye\Resolution\AuthActorResolver;
use Dotswan\EagleEye\Resolution\ActivityEntryPresenter;
use Dotswan\EagleEye\Resolution\AttributeFieldLabelResolver;
use Dotswan\EagleEye\Resolution\ActivityPrivacyDeclaration;
use Dotswan\EagleEye\Resolution\ConventionSubjectResolver;
use Dotswan\EagleEye\Resolution\PayloadNormalizer;
use Dotswan\EagleEye\Resolution\StoredActivityIdentityResolver;
use Dotswan\EagleEye\Repository\ActivityRepository;
use Dotswan\EagleEye\Repository\ActivityTrail;
use Dotswan\EagleEye\Security\GateAccessPolicy;
use Dotswan\EagleEye\Security\HmacBlindIndex;
use Dotswan\EagleEye\Security\LaravelCipher;
use Dotswan\EagleEye\Title\PlaceholderTemplateRenderer;
use Dotswan\EagleEye\Title\Resolvers\AuthTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\ConsoleTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\ExceptionTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\FallbackTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\HttpTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\MailTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\ModelTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\NotificationTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\OutboundHttpTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\QueryTitleResolver;
use Dotswan\EagleEye\Title\Resolvers\QueueTitleResolver;
use Dotswan\EagleEye\Title\TitleResolverChain;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Dotswan\EagleEye\Capture\HttpCapture;
use Dotswan\EagleEye\Capture\Outbound\GuzzleCaptureMiddleware;
use Dotswan\EagleEye\Capture\Outbound\OutboundHttpRecorder;
use Dotswan\EagleEye\Http\Middleware\CaptureHttpActivityMiddleware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Dotswan\EagleEye\Transport\ActivityBuffer;
use Dotswan\EagleEye\Transport\FlushDeferral;
use Dotswan\EagleEye\Transport\Flusher;
use Dotswan\EagleEye\Transport\DurableRevealAudit;
use Dotswan\EagleEye\Transport\GuardedTransport;
use Dotswan\EagleEye\Transport\QueueTransport;
use Dotswan\EagleEye\Transport\SpoolTransport;
use Dotswan\EagleEye\Transport\StreamTransport;
use Dotswan\EagleEye\Transport\SyncTransport;
use Throwable;
use InvalidArgumentException;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityHasherInterface;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Contracts\Privacy\SubjectEraserInterface;
use Dotswan\EagleEye\Contracts\Redaction\RedactionModeResolverInterface;
use Dotswan\EagleEye\Integrity\CanonicalEntryHasher;
use Dotswan\EagleEye\Integrity\IntegrityVerifier;
use Dotswan\EagleEye\Integrity\IntegrityLedger;
use Dotswan\EagleEye\Privacy\SubjectEraser;
use Dotswan\EagleEye\Privacy\ErasureRegistry;
use Dotswan\EagleEye\Retention\RetentionPruner;
use Override;

final class EagleEyeServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eagle-eye.php', 'eagle-eye');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'eagle-eye');

        $this->app->singleton(DriverManager::class);
        $this->app->alias(DriverManager::class, ActivityLogDriverFactoryInterface::class);

        $this->app->singleton(ActivityRepositoryInterface::class, fn (): ActivityRepository => new ActivityRepository(
            $this->app->make(ActivityLogDriverFactoryInterface::class),
            $this->app->make(LogAccessPolicyInterface::class),
            $this->app->make(CipherInterface::class),
            $this->app->make(DurableRevealAuditInterface::class),
        ));

        $this->app->singleton(AttributeMetadataReader::class);
        $this->app->singleton(PayloadBudget::class, fn (): PayloadBudget => $this->payloadBudget());
        $this->app->singleton(PayloadNormalizerInterface::class, PayloadNormalizer::class);
        $this->app->scoped(PipelineRunner::class, static fn (Application $app): PipelineRunner => new PipelineRunner(
            $app,
            $app->make(ConfigRepository::class),
        ));
        $this->app->scoped(AuthActorResolver::class, static fn (Application $app): AuthActorResolver => new AuthActorResolver(
            $app->make(AuthFactory::class),
            $app->runningInConsole(),
        ));
        $this->app->alias(AuthActorResolver::class, ActorResolverInterface::class);
        $this->app->scoped(ActivityPrivacyDeclaration::class);
        $this->app->singleton(SubjectResolverInterface::class, ConventionSubjectResolver::class);
        $this->app->singleton(FieldLabelResolverInterface::class, AttributeFieldLabelResolver::class);
        $this->app->singleton(ActivityIdentityResolverInterface::class, StoredActivityIdentityResolver::class);
        $this->app->singleton(ActivityEntryPresenter::class);
        $this->app->alias(ActivityEntryPresenter::class, ActivityEntryPresenterInterface::class);
        $this->app->singleton(TemplateRendererInterface::class, PlaceholderTemplateRenderer::class);
        $this->app->singleton(LogAccessPolicyInterface::class, GateAccessPolicy::class);
        $this->app->singleton(DurableRevealAuditInterface::class, DurableRevealAudit::class);
        $this->app->scoped(FilterChain::class, static fn (Application $app): FilterChain => new FilterChain(
            $app->make(ConfigRepository::class),
            $app,
        ));
        $this->app->singleton(Gate::class, fn (): Gate => new Gate($this->app->make(ConfigRepository::class)));
        $this->app->singleton(Redactor::class, fn (): Redactor => $this->buildRedactor());
        $this->app->alias(Redactor::class, RedactionModeResolverInterface::class);
        $this->app->singleton(HttpCapture::class);
        $this->app->singleton(OutboundHttpRecorder::class);

        if (interface_exists(ClientInterface::class)) {
            $this->app->tag([GuzzleCaptureMiddleware::class], 'eagle-eye.guzzle_middleware');
        }
        $this->app->singleton(SamplerInterface::class, fn (): DeterministicSampler => new DeterministicSampler(
            $this->app->make(ConfigRepository::class),
        ));
        $this->app->scoped(RunEnrichers::class);
        $this->app->scoped(QuerySummaryCollector::class, fn (Application $app): QuerySummaryCollector => new QuerySummaryCollector(
            new SqlFingerprint(),
            max(0, (int) $app->make(ConfigRepository::class)->get('eagle-eye.capture.query.slow_threshold_ms', 500)),
        ));
        $this->app->tag([QuerySummaryEnricher::class], 'eagle-eye.enrichers');
        $this->app->singleton(TitleResolverChain::class, function (): TitleResolverChain {
            $tagged = [];

            foreach ($this->app->tagged('eagle-eye.title_resolvers') as $resolver) {
                if ($resolver instanceof TitleResolverInterface) {
                    $tagged[] = $resolver;
                }
            }

            $builtIn = [
                ModelTitleResolver::class,
                HttpTitleResolver::class,
                AuthTitleResolver::class,
                QueueTitleResolver::class,
                ConsoleTitleResolver::class,
                MailTitleResolver::class,
                NotificationTitleResolver::class,
                OutboundHttpTitleResolver::class,
                ExceptionTitleResolver::class,
                QueryTitleResolver::class,
                FallbackTitleResolver::class,
            ];

            return new TitleResolverChain([
                ...$tagged,
                ...array_map(fn (string $class): TitleResolverInterface => $this->app->make($class), $builtIn),
            ]);
        });
        $this->app->alias(TitleResolverChain::class, TitleResolverInterface::class);
        $this->app->singleton(TracePropagatorInterface::class, W3CTracePropagator::class);

        $this->app->scoped(TraceManager::class, static function (Application $app): TraceManager {
            $manager = new TraceManager();
            $request = $app->bound('request') ? $app->make('request') : null;

            if ($request instanceof Request && ! $app->runningInConsole()) {
                $manager->adopt($app->make(TracePropagatorInterface::class)->extract([
                    'traceparent'      => $request->header('traceparent', ''),
                    'x-correlation-id' => $request->header('x-correlation-id', ''),
                ]));
            }

            return $manager;
        });

        $this->app->scoped(CaptureSuppression::class);

        $this->app->scoped(RecorderInterface::class, fn (Application $app): Recorder => new Recorder(
            $app->make(ExceptionHandler::class),
            $app->make(ConfigRepository::class),
            $this->environmentAllowed($app),
            $app->make(Gate::class),
            $app->make(PipelineRunner::class),
            $app->make(TraceManager::class),
            $app->make(ActivityBuffer::class),
            $app->make(Flusher::class),
        ));

        $this->app->scoped(ActivityBuffer::class, fn (Application $app): ActivityBuffer => new ActivityBuffer(
            $this->configInt('eagle-eye.transport.buffer', 100, 1, 100, $app),
        ));

        $this->app->scoped(Flusher::class, fn (Application $app): Flusher => new Flusher(
            $app->make(ActivityBuffer::class),
            $app->make(TransportInterface::class),
            $app->make(ErasureBarrierInterface::class),
            $app->make(FlushDeferral::class),
        ));

        $this->app->scoped(FlushDeferral::class, function (Application $app): FlushDeferral {
            $connection = $this->erasureConnection($app);

            return new FlushDeferral(
                $connection->afterCommit(...),
            );
        });

        $this->app->singleton(TransportInterface::class, fn (): TransportInterface => $this->buildTransport());

        $this->app->scoped(EagleEyeInterface::class, fn (Application $app): EagleEye => new EagleEye(
            recorder: $app->make(RecorderInterface::class),
            repository: $app->make(ActivityRepositoryInterface::class),
            maxTags: $this->configInt('eagle-eye.tags.max', 24, 1, 100, $app),
            staticTags: $this->configStringList('eagle-eye.tags.static', $app),
        ));

        $this->app->singleton(CipherInterface::class, fn (): LaravelCipher => new LaravelCipher(
            encrypter: $this->app->make(Encrypter::class),
            key: (string) $this->app->make(ConfigRepository::class)->get('app.key'),
            cipher: (string) $this->app->make(ConfigRepository::class)->get('app.cipher', 'AES-256-CBC'),
        ));

        $this->app->singleton(BlindIndexInterface::class, fn (): HmacBlindIndex => new HmacBlindIndex(
            (string) $this->app->make(ConfigRepository::class)->get('app.key'),
        ));
        $this->app->singleton(ErasureRegistry::class, fn(Application $app): ErasureRegistry => new ErasureRegistry(
            $this->erasureConnection($app),
            $this->securityKey($app),
            $this->configInt('eagle-eye.privacy.erasure_barrier_days', 31, 1, 36_500, $app),
            $this->configInt('eagle-eye.transport.spool.max_age_days', 30, 1, 36_499, $app),
            $this->configInt('eagle-eye.privacy.erasure_max_barriers', 100_000, 1, 1_000_000, $app),
            $this->configInt('eagle-eye.privacy.erasure_max_receipts', 100_000, 1, 1_000_000, $app),
            $app->make(IntegrityLedger::class),
            $this->configInt('eagle-eye.privacy.delivery_max_attempts', 100_000, 1, 1_000_000, $app),
        ));
        $this->app->alias(ErasureRegistry::class, ErasureBarrierInterface::class);
        $this->app->singleton(IntegrityHasherInterface::class, CanonicalEntryHasher::class);
        $this->app->scoped(ExceptionDeduplicator::class);
        $this->app->singleton(IntegrityLedger::class, function (Application $app): IntegrityLedger {
            $config = $app->make(ConfigRepository::class);
            $connection = $config->get('eagle-eye.integrity.connection');

            return new IntegrityLedger(
                $app->make('db')->connection(is_string($connection) && '' !== $connection ? $connection : null),
                $app->make(IntegrityHasherInterface::class),
                $this->securityKey($app),
                $this->configInt('eagle-eye.integrity.segment_size', 1_000, 1, 10_000, $app),
            );
        });
        $this->app->alias(IntegrityLedger::class, IntegrityChainInterface::class);
        $this->app->singleton(IntegrityVerifier::class);
        $this->app->scoped(SubjectEraserInterface::class, SubjectEraser::class);
        $this->app->singleton(RetentionPruner::class);

        $this->registerDatabaseDriver();
        $this->registerIndexDriver();
        $this->registerMultiDriver();
        $this->registerLokiDriver();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eagle-eye.php' => $this->app->configPath('eagle-eye.php'),
        ], 'eagle-eye-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/eagle-eye'),
        ], 'eagle-eye-lang');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'eagle-eye-migrations');

        $this->registerQueueTracePropagation();
        $this->registerActivityTrailMacro();
        $this->registerCaptureAdapters();
        $this->registerFlushHooks();
        $this->registerApiRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheCommand::class,
                DoctorCommand::class,
                ExportCommand::class,
                ForgetCommand::class,
                LokiConfigCommand::class,
                LokiShipSpoolCommand::class,
                MakeDriverCommand::class,
                PruneCommand::class,
                ShipSpoolCommand::class,
                StatsCommand::class,
                TailCommand::class,
                TraceCommand::class,
                TrailCommand::class,
                VerifyCommand::class,
            ]);
        }
    }

    private function registerApiRoutes(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if (! (bool) $config->get('eagle-eye.api.enabled', false)) {
            return;
        }

        $prefix = (string) $config->get('eagle-eye.api.prefix', 'api/v1');
        $middleware = $config->get('eagle-eye.api.middleware', ['api']);
        $middleware = is_array($middleware) ? $middleware : ['api'];

        ApiRoutes::register($this->app->make(Router::class), $prefix, array_values($middleware));
    }

    private function buildTransport(): TransportInterface
    {
        $config = $this->app->make(ConfigRepository::class);
        $spoolPath = (string) ($config->get('eagle-eye.transport.spool.path') ?: $this->app->storagePath('eagle-eye/spool.log'));
        $spool = new SpoolTransport(
            $spoolPath,
            $this->configInt('eagle-eye.transport.spool.max_bytes', 10_485_760, 65_536, 536_870_912),
            $this->app->make(ErasureRegistry::class),
        );

        $strategy = match ((string) $config->get('eagle-eye.transport.driver', 'sync')) {
            'queue'  => $this->queueTransport(),
            'stream' => new StreamTransport((string) $config->get('eagle-eye.transport.stream.target', 'php://stdout')),
            default  => new SyncTransport(fn (): ActivityLogDriverInterface => $this->app->make(ActivityLogDriverFactoryInterface::class)->driver()),
        };

        return new GuardedTransport(
            $strategy,
            $spool,
            (string) $config->get('eagle-eye.transport.on_failure', 'spool'),
            $this->app->make(ErasureRegistry::class),
        );
    }

    private function queueTransport(): TransportInterface
    {
        if (! $this->app->bound('queue')) {
            return new SyncTransport(fn (): ActivityLogDriverInterface => $this->app->make(ActivityLogDriverFactoryInterface::class)->driver());
        }

        $config = $this->app->make(ConfigRepository::class);
        $connection = $config->get('eagle-eye.transport.queue.connection');
        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $queueName = $config->get('eagle-eye.transport.queue.queue');

        return new QueueTransport(
            $manager->connection(is_string($connection) && '' !== $connection ? $connection : null),
            is_string($queueName) && '' !== $queueName ? $queueName : null,
        );
    }

    private const array CAPTURE_ADAPTERS = [
        'model'         => EloquentCapture::class,
        'auth'          => AuthCapture::class,
        'queue'         => QueueCapture::class,
        'console'       => ConsoleCapture::class,
        'mail'          => MailCapture::class,
        'notification'  => NotificationCapture::class,
        'outbound_http' => OutboundHttpCapture::class,
        'exception'     => ExceptionCapture::class,
    ];

    private function registerCaptureAdapters(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        foreach (self::CAPTURE_ADAPTERS as $key => $default) {
            $this->registerAdapter($config, $key, $default);
        }

        $queryEnabled = (bool) $config->get('eagle-eye.capture.query.enabled', false);
        $querySummary = (bool) $config->get('eagle-eye.capture.query.summary', true);

        if ($queryEnabled || $querySummary) {
            $via = $config->get('eagle-eye.capture.query.via', QueryCapture::class);
            $via = is_string($via) && class_exists($via) ? $via : QueryCapture::class;
            $adapter = $this->app->make($via);

            if ($adapter instanceof CaptureAdapterInterface) {
                $adapter->register();
            }
        }

        $this->registerHttpCaptureMiddleware($config);
    }

    private function registerHttpCaptureMiddleware(ConfigRepository $config): void
    {
        $http = $config->get('eagle-eye.capture.http', []);
        $enabled = is_array($http) && (bool) ($http['enabled'] ?? true);

        if (! $enabled || ! class_exists(CaptureHttpActivityMiddleware::class)) {
            return;
        }

        if (! $this->app->bound(HttpKernel::class)) {
            return;
        }

        try {
            $this->app->make(HttpKernel::class)->pushMiddleware(CaptureHttpActivityMiddleware::class);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function registerAdapter(ConfigRepository $config, string $key, string $default, bool $enabledByDefault = true): void
    {
        $settings = $config->get("eagle-eye.capture.{$key}", []);
        $settings = is_array($settings) ? $settings : [];

        if (! (bool) ($settings['enabled'] ?? $enabledByDefault)) {
            return;
        }

        $via = $settings['via'] ?? $default;
        $via = is_string($via) && class_exists($via) ? $via : $default;

        $adapter = $this->app->make($via);

        if ($adapter instanceof CaptureAdapterInterface) {
            $adapter->register();
        }
    }

    private function registerFlushHooks(): void
    {
        $after = static function (): void {
            try {
                app(Flusher::class)->flush();
            } catch (Throwable) {
            }
        };

        $afterJob = static function (JobProcessed|JobFailed|JobExceptionOccurred $event) use ($after): void {
            if ('sync' === $event->connectionName) {
                return;
            }

            $after();
        };

        $this->app->terminating($after);

        if (class_exists(JobProcessed::class) && $this->app->bound('queue')) {
            $this->app->make('events')->listen(JobProcessed::class, $afterJob);
            $this->app->make('events')->listen(JobFailed::class, $afterJob);
            $this->app->make('events')->listen(JobExceptionOccurred::class, $afterJob);
            $this->app->make('events')->listen(Looping::class, $after);
            $this->app->make('events')->listen(WorkerStopping::class, $after);
        }

        if (class_exists('Laravel\\Octane\\Events\\RequestTerminated')) {
            $this->app->make('events')->listen('Laravel\\Octane\\Events\\RequestTerminated', $after);
        }

        register_shutdown_function(static function (): void {
            try {
                if (function_exists('app')) {
                    app(Flusher::class)->flush();
                }
            } catch (Throwable) {
            }
        });
    }

    private function erasureConnection(Application $app): Connection
    {
        $connection = $app->make(ConfigRepository::class)->get('eagle-eye.privacy.erasure_connection');

        return $app->make('db')->connection(is_string($connection) && '' !== $connection ? $connection : null);
    }

    private function registerIndexDriver(): void
    {
        if (! $this->databaseAvailable()) {
            return;
        }

        $this->app->make(DriverManager::class)->extend('index', function (Application $app): IndexDriver {
            $settings = DriverSettings::array(
                $app->make(ConfigRepository::class)->get('eagle-eye.drivers', []),
                'index',
            );
            $connection = $settings['connection'] ?? null;

            return new IndexDriver(
                connection: $app->make('db')->connection(is_string($connection) && '' !== $connection ? $connection : null),
                table: (string) ($settings['table'] ?? 'activity_log_index'),
                erasure: $app->make(ErasureRegistry::class),
            );
        });
    }

    private function registerMultiDriver(): void
    {
        $this->app->make(DriverManager::class)->extend('multi', function (Application $app): MultiDriver {
            $settings = DriverSettings::array(
                $app->make(ConfigRepository::class)->get('eagle-eye.drivers', []),
                'multi',
            );
            $onFailure = DriverSettings::array($settings, 'on_failure');
            $drivers = $app->make(DriverManager::class);

            $primaryName = (string) ($settings['primary'] ?? 'database');
            $acceleratorName = $settings['accelerator'] ?? null;

            $primary = $drivers->driver($primaryName);
            $accelerator = is_string($acceleratorName) && '' !== $acceleratorName ? $drivers->driver($acceleratorName) : null;

            if (! $primary instanceof FullActivityLogDriverInterface) {
                throw new InvalidArgumentException("Eagle Eye multi primary [{$primaryName}] must implement the full driver contract.");
            }

            if (null !== $accelerator && ! $accelerator instanceof FullActivityLogDriverInterface) {
                throw new InvalidArgumentException("Eagle Eye multi accelerator [{$acceleratorName}] must implement the full driver contract.");
            }

            return new MultiDriver(
                primary: $primary,
                accelerator: $accelerator,
                primaryOnFailure: (string) ($onFailure['primary'] ?? 'throw'),
                acceleratorOnFailure: (string) ($onFailure['accelerator'] ?? 'ignore'),
            );
        });
    }

    private function registerLokiDriver(): void
    {
        $this->app->make(DriverManager::class)->extend('loki', function (Application $app): LokiDriver {
            $config = $app->make(ConfigRepository::class);
            $settings = $config->get('eagle-eye.drivers.loki', []);
            $settings = is_array($settings) ? $settings : [];

            $write = DriverSettings::array($settings, 'write');
            $read = DriverSettings::array($settings, 'read');
            $labels = DriverSettings::array($settings, 'labels');
            $writeAuth = DriverSettings::array($write, 'auth');
            $readAuth = DriverSettings::array($read, 'auth');
            $auth = null === DriverSettings::stringOrNull($readAuth, 'type') ? $writeAuth : $readAuth;
            $retry = DriverSettings::array($write, 'retry');
            $selector = DriverSettings::stringOrNull($read, 'selector');

            $staticLabels = is_array($labels['static'] ?? null) ? $labels['static'] : [];
            $dynamic = is_array($labels['dynamic'] ?? null) ? array_values($labels['dynamic']) : ['env', 'channel', 'severity'];
            $backoffs = is_array($retry['backoff_ms'] ?? null) ? array_values(array_map(intval(...), $retry['backoff_ms'])) : [200, 1000, 5000];
            $spoolPath = $write['spool'] ?? null;

            $spool = is_string($spoolPath) && '' !== $spoolPath ? new SpoolWriter($spoolPath) : null;
            $path = (string) ($write['path'] ?? 'stdout');

            $writer = match ($path) {
                'push' => new PushWriter(
                    endpoint: (string) ($write['endpoint'] ?? 'http://127.0.0.1:3100'),
                    tenant: DriverSettings::stringOrNull($write, 'tenant'),
                    auth: $writeAuth,
                    gzip: (bool) ($write['gzip'] ?? true),
                    backoffMs: $backoffs,
                    spool: $spool,
                    timeout: (int) ($write['timeout'] ?? 5),
                ),
                'spool' => $spool ?? new SpoolWriter(storage_path('logs/eagle-eye-loki.spool')),
                default => new StdoutWriter((string) ($write['stream'] ?? 'php://stdout')),
            };

            return new LokiDriver(
                writer: $writer,
                reader: new LokiClient(
                    endpoint: (string) ($read['endpoint'] ?? ($write['endpoint'] ?? 'http://127.0.0.1:3100')),
                    tenant: DriverSettings::stringOrNull($read, 'tenant') ?? DriverSettings::stringOrNull($write, 'tenant'),
                    auth: $auth,
                    timeout: (int) ($read['timeout'] ?? 15),
                ),
                formatter: new LokiLineFormatter($staticLabels, array_map(strval(...), $dynamic)),
                translator: new LokiQueryTranslator(
                    structuredMetadata: null === $selector && (bool) ($read['structured_metadata'] ?? true),
                    labelNames: $dynamic,
                    selector: $selector,
                ),
                staticLabels: $staticLabels,
                defaultRangeDays: (int) ($read['default_range_days'] ?? 30),
                erasure: $app->make(ErasureRegistry::class),
                integrity: $app->make(IntegrityLedger::class),
            );
        });

        $this->app->booted(function (): void {
            $config = $this->app->make(ConfigRepository::class);
            $read = $config->get('eagle-eye.drivers.loki.read', []);
            $labels = $config->get('eagle-eye.drivers.loki.labels', []);

            if (is_array($read)) {
                $selector = DriverSettings::stringOrNull($read, 'selector');

                if (null !== $selector) {
                    LokiQueryTranslator::assertSelector($selector);
                }
            }

            if (! is_array($labels)) {
                return;
            }

            $static = is_array($labels['static'] ?? null) ? $labels['static'] : [];
            $dynamic = is_array($labels['dynamic'] ?? null) ? $labels['dynamic'] : [];

            (new LokiLabelGuard())->validate(
                array_merge($static, array_fill_keys(array_map(strval(...), $dynamic), '')),
                LokiLineFormatter::METADATA_KEYS,
            );
        });
    }

    private function registerDatabaseDriver(): void
    {
        if (! $this->databaseAvailable()) {
            return;
        }

        $this->app->make(DriverManager::class)->extend('database', function (Application $app): DatabaseDriver {
            $settings = $app->make(ConfigRepository::class)->get('eagle-eye.drivers.database', []);
            $settings = is_array($settings) ? $settings : [];
            $connection = $settings['connection'] ?? null;

            return new DatabaseDriver(
                connection: $app->make('db')->connection(is_string($connection) && '' !== $connection ? $connection : null),
                table: (string) ($settings['table'] ?? 'activity_logs'),
                erasure: $app->make(ErasureRegistry::class),
                integrity: $app->make(IntegrityLedger::class),
            );
        });
    }

    private function databaseAvailable(): bool
    {
        return interface_exists(ConnectionInterface::class) && $this->app->bound('db');
    }

    private function registerQueueTracePropagation(): void
    {
        if (! class_exists(JobProcessing::class) || ! $this->app->bound('queue')) {
            return;
        }

        Queue::createPayloadUsing(static fn(): array => [
            'eagleEyeTrace' => app(TraceManager::class)->next()->toArray(),
        ]);

        $this->app->make('events')->listen(JobProcessing::class, static function (JobProcessing $event): void {
            try {
                $manager = app(TraceManager::class);
                $manager->reset();
                $manager->adopt(self::queueTraceContext($event->job->payload()['eagleEyeTrace'] ?? null));
            } catch (Throwable) {
            }
        });
    }

    private static function queueTraceContext(mixed $trace): TraceContext
    {
        if (! is_array($trace)) {
            return TraceContext::create();
        }

        $traceId = $trace['trace_id'] ?? null;
        $correlationId = $trace['correlation_id'] ?? null;
        $causationId = $trace['causation_id'] ?? null;
        $parentSpanId = $trace['parent_span_id'] ?? null;

        if (! is_string($traceId) || 32 !== mb_strlen($traceId, '8bit')
            || ! self::validQueueToken($correlationId)
            || (null !== $causationId && ! self::validQueueToken($causationId))
            || (null !== $parentSpanId && (! is_string($parentSpanId)
                || 16 !== mb_strlen($parentSpanId, '8bit')
                || 1 !== preg_match('/\A[a-f0-9]{16}\z/', $parentSpanId)
                || str_repeat('0', 16) === $parentSpanId))) {
            return TraceContext::create();
        }

        return new TraceContext($traceId, $correlationId, $causationId, $parentSpanId);
    }

    /** @phpstan-assert-if-true string $value */
    private static function validQueueToken(mixed $value): bool
    {
        return is_string($value) && mb_strlen($value, '8bit') <= 128
            && 1 === preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $value);
    }

    private function registerActivityTrailMacro(): void
    {
        if (! class_exists(Builder::class)) {
            return;
        }

        $application = $this->app;

        Builder::macro('activityTrail', function (string $as = 'subject') use ($application): ActivityTrail {
            $model = $this->getModel();
            $id = $model->getKey();

            if (null === $id) {
                throw new InvalidArgumentException('Activity trails require a persisted Eloquent model.');
            }

            $subject = $application->make(SubjectResolverInterface::class)->resolve($model);
            $trail = $application->make(EagleEyeInterface::class)->query();

            return match ($as) {
                'actor'   => $trail->forActor($subject->type, (string) $id),
                'subject' => $trail
                    ->forSubject($subject->type, (string) $id)
                    ->forSubjectClass($subject->className),
                default => throw new InvalidArgumentException("Activity trail role [{$as}] is invalid."),
            };
        });
    }

    private function environmentAllowed(Application $app): bool
    {
        $environments = $this->configStringList('eagle-eye.environments', $app);

        return in_array('*', $environments, true) || in_array($app->environment(), $environments, true);
    }

    private function buildRedactor(): Redactor
    {
        $config = $this->app->make(ConfigRepository::class);

        $paths = [];
        $configuredPaths = $config->get('eagle-eye.privacy.paths', []);

        if (is_array($configuredPaths)) {
            foreach ($configuredPaths as $pattern => $mode) {
                $parsed = is_string($mode) ? RedactionMode::tryFrom($mode) : null;

                if (is_string($pattern) && null !== $parsed) {
                    $paths[$pattern] = $parsed;
                }
            }
        }

        $mask = $config->get('eagle-eye.privacy.mask', []);
        $mask = is_array($mask) ? $mask : [];
        $char = (string) ($mask['char'] ?? '*');

        return new Redactor(
            defaultMode: RedactionMode::tryFrom((string) $config->get('eagle-eye.privacy.default_mode', 'mask')) ?? RedactionMode::Mask,
            paths: $paths,
            sensitiveKeyPatterns: $this->configStringList('eagle-eye.privacy.sensitive_key_patterns'),
            strategies: [
                RedactionMode::Drop->value => new DropStrategy(),
                RedactionMode::Mask->value => new MaskStrategy(
                    char: '' === $char ? '*' : mb_substr($char, 0, 1),
                    keepPrefix: $this->clampedMask($mask, 'keep_prefix'),
                    keepSuffix: $this->clampedMask($mask, 'keep_suffix'),
                ),
                RedactionMode::Partial->value => new PartialMaskStrategy(),
                RedactionMode::Hash->value    => new HashStrategy($this->app->make(BlindIndexInterface::class)),
                RedactionMode::Encrypt->value => new EncryptStrategy(
                    $this->app->make(CipherInterface::class),
                    $this->app->make(BlindIndexInterface::class),
                ),
            ],
            metadata: $this->app->make(AttributeMetadataReader::class),
            partialKeyPatterns: $this->configStringList('eagle-eye.privacy.partial_key_patterns'),
            detectEmailValues: (bool) $config->get('eagle-eye.privacy.detect_email_values', true),
        );
    }

    /** @param array<string, mixed> $mask */
    private function clampedMask(array $mask, string $key): int
    {
        return max(0, min((int) ($mask[$key] ?? 0), 64));
    }

    private function payloadBudget(): PayloadBudget
    {
        return new PayloadBudget(
            maxBytes: $this->configInt('eagle-eye.payload.max_bytes', 65_536, 256, 1_048_576),
            maxDepth: $this->configInt('eagle-eye.payload.max_depth', 8, 1, 32),
            maxArrayItems: $this->configInt('eagle-eye.payload.max_array_items', 100, 1, 1000),
            maxStringLength: $this->configInt('eagle-eye.payload.max_string_length', 4096, 1, 65_536),
            maxNodes: $this->configInt('eagle-eye.payload.max_nodes', 2000, 1, 100_000),
        );
    }

    private function securityKey(Application $app): string
    {
        $config = $app->make(ConfigRepository::class);
        $key = $config->get('eagle-eye.security_key');

        if (is_string($key) && mb_strlen($key, '8bit') >= 32) {
            return $key;
        }

        if ($app->environment('local', 'testing')) {
            return (string) $config->get('app.key');
        }

        throw new InvalidArgumentException('EAGLE_EYE_SECURITY_KEY must contain at least 32 bytes and remain independent of application-key rotation.');
    }

    private function configInt(string $key, int $default, int $minimum, int $maximum, ?Application $app = null): int
    {
        $value = ($app ?? $this->app)->make(ConfigRepository::class)->get($key, $default);

        return max($minimum, min((int) $value, $maximum));
    }

    /** @return list<string> */
    private function configStringList(string $key, ?Application $app = null): array
    {
        $values = ($app ?? $this->app)->make(ConfigRepository::class)->get($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }
}
