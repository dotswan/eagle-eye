<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Security\DurableRevealAuditInterface;
use Dotswan\EagleEye\Contracts\Security\DurableSecurityAuditDriverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

final readonly class DurableRevealAudit implements DurableRevealAuditInterface
{
    public function __construct(
        private ActivityLogDriverFactoryInterface $drivers,
        private CaptureSuppression $suppression,
        private ConfigRepository $config,
        private Application $app,
    ) {
    }

    public function record(ActivityEntry $entry, string $path, ?object $viewer = null): void
    {
        $viewerId = $viewer instanceof Authenticatable ? $viewer->getAuthIdentifier() : null;
        $draft = ActivityDraft::make('eagle-eye.secret.revealed', ActivityChannel::Custom, ActivitySeverity::Notice)
            ->withActor(null === $viewerId ? new Actor('console') : new Actor('user', (string) $viewerId))
            ->withSubject(new Subject('activity_entry', $entry->id))
            ->withPayload(['revealed_path' => $path, 'revealed_entry' => $entry->id, 'outcome' => 'audit_committed'])
            ->withTrace(TraceContext::create())
            ->withTags(['security', 'audit']);
        $draft->title = 'Activity secret revealed';
        $draft->environment = app()->environment();

        $configured = $this->config->get('eagle-eye.privacy.reveal_audit_driver');
        $driverName = is_string($configured) && '' !== $configured ? $configured : null;
        $driver = $this->drivers->driver($driverName);

        if ($this->app->isProduction() && ('database' !== $driverName || ! $driver instanceof DurableSecurityAuditDriverInterface)) {
            throw new LogicException('Production secret reveal requires the explicitly configured database reveal audit driver.');
        }

        if ($driver instanceof DurableSecurityAuditDriverInterface && $driver->hasUncommittedTransaction()) {
            throw new LogicException('Secret reveal is unavailable while the audit database connection has an uncommitted transaction.');
        }

        $this->suppression->run(function () use ($draft, $driver): void {
            BatchWriter::write($driver, [$draft->freeze()]);
        });
    }
}
