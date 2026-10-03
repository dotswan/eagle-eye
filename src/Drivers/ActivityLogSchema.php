<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

final class ActivityLogSchema
{
    public static function create(SchemaBuilder $schema, string $table): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, static function (Blueprint $blueprint): void {
            self::define($blueprint);
        });
    }

    /**
     * Header-only projection (~250 B/row, no payload) that accelerates
     * unbounded-time actor/subject queries, exact counts, stable rankings
     * and trace assembly for append backends such as Loki.
     */
    public static function createIndex(SchemaBuilder $schema, string $table = 'activity_log_index'): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, static function (Blueprint $blueprint): void {
            $blueprint->string('id', 64)->primary();
            $blueprint->dateTime('logged_at', 6)->index();
            $blueprint->string('channel', 16)->index();
            $blueprint->string('severity', 16)->index();
            $blueprint->string('action', 191)->index();
            $blueprint->string('actor_type', 32)->nullable();
            $blueprint->string('actor_id', 64)->nullable();
            $blueprint->string('actor_label')->nullable();
            $blueprint->string('subject_type', 64)->nullable();
            $blueprint->string('subject_id', 64)->nullable();
            $blueprint->string('subject_label')->nullable();
            $blueprint->string('subject_class')->nullable();
            $blueprint->string('trace_id', 32)->index();
            $blueprint->string('correlation_id', 64)->nullable()->index();
            $blueprint->string('causation_id', 64)->nullable()->index();
            $blueprint->string('route', 191)->nullable()->index();
            $blueprint->string('method', 8)->nullable();
            $blueprint->unsignedSmallInteger('status_code')->nullable()->index();
            $blueprint->unsignedInteger('duration_ms')->nullable();
            $blueprint->json('tags')->nullable();
            $blueprint->string('title')->nullable();
            $blueprint->dateTime('created_at', 6);

            $blueprint->index(['actor_type', 'actor_id', 'logged_at']);
            $blueprint->index(['subject_type', 'subject_id', 'logged_at']);
        });
    }

    public static function createErasureState(SchemaBuilder $schema): void
    {
        if (! $schema->hasTable('activity_security_state')) {
            $schema->create('activity_security_state', static function (Blueprint $table): void {
                $table->string('name', 32)->primary();
                $table->string('key_fingerprint', 64)->nullable();
                $table->unsignedBigInteger('version')->default(0);
            });
            $schema->getConnection()->table('activity_security_state')->insert(['name' => 'coordination', 'version' => 0]);
        }

        if (! $schema->hasTable('activity_delivery_attempts')) {
            $schema->create('activity_delivery_attempts', static function (Blueprint $table): void {
                $table->string('attempt_id', 26);
                $table->string('identity_hmac', 64)->index();
                $table->dateTime('started_at', 6);
                $table->primary(['attempt_id', 'identity_hmac']);
            });
        }

        if (! $schema->hasTable('activity_erasure_barriers')) {
            $schema->create('activity_erasure_barriers', static function (Blueprint $table): void {
                $table->string('identity_hmac', 64)->primary();
                $table->string('request_id', 26);
                $table->dateTime('created_at', 6);
                $table->dateTime('expires_at', 6)->index();
            });
        }

        if (! $schema->hasTable('activity_erasure_receipts')) {
            $schema->create('activity_erasure_receipts', static function (Blueprint $table): void {
                $table->string('id', 26)->primary();
                $table->string('identity_hmac', 64)->index();
                $table->string('state', 24)->index();
                $table->json('outcomes');
                $table->dateTime('requested_at', 6);
                $table->dateTime('completed_at', 6)->nullable();
                $table->string('receipt_hmac', 64)->nullable();
            });
        }
    }

    public static function createIntegrityState(SchemaBuilder $schema): void
    {
        if (! $schema->hasTable('activity_integrity_state')) {
            $schema->create('activity_integrity_state', static function (Blueprint $table): void {
                $table->string('name', 32)->primary();
                $table->string('active_segment_id', 32)->nullable();
                $table->string('last_segment_id', 32)->nullable();
                $table->unsignedBigInteger('version')->default(0);
            });
            $schema->getConnection()->table('activity_integrity_state')->insert([
                'name'              => 'chain',
                'active_segment_id' => null,
                'last_segment_id'   => null,
                'version'           => 0,
            ]);
        }

        if (! $schema->hasTable('activity_integrity_segments')) {
            $schema->create('activity_integrity_segments', static function (Blueprint $table): void {
                $table->string('id', 32)->primary();
                $table->string('predecessor_segment_id', 32)->nullable();
                $table->string('first_entry_id', 64);
                $table->string('last_entry_id', 64);
                $table->unsignedInteger('entry_count');
                $table->string('head_hash', 64);
                $table->string('state', 16)->index();
                $table->dateTime('opened_at', 6);
                $table->dateTime('sealed_at', 6)->nullable();
                $table->string('anchor_hmac', 64);
                $table->index(['state', 'opened_at']);
            });
        }

        if (! $schema->hasTable('activity_integrity_entries')) {
            $schema->create('activity_integrity_entries', static function (Blueprint $table): void {
                $table->string('entry_id', 64)->primary();
                $table->string('segment_id', 32)->index();
                $table->unsignedInteger('sequence');
                $table->string('previous_hash', 64)->nullable();
                $table->string('entry_hash', 64);
                $table->string('lifecycle', 24)->default('pending');
                $table->string('lifecycle_hmac', 64)->default('');
                $table->string('metadata_hmac', 64)->default('');
                $table->string('actor_hmac', 64)->nullable()->index();
                $table->string('subject_hmac', 64)->nullable()->index();
                $table->string('class_hmac', 64)->nullable()->index();
                $table->dateTime('logged_at', 6)->nullable()->index();
                $table->string('channel', 16)->nullable();
                $table->string('severity', 16)->nullable();
                $table->json('tags')->nullable();
                $table->unique(['segment_id', 'sequence']);
            });
        }
    }

    public static function define(Blueprint $table): void
    {
        $table->string('id', 64)->primary();
        $table->unsignedTinyInteger('schema_version')->default(1);
        $table->dateTime('logged_at', 6)->index();
        $table->string('channel', 16);
        $table->string('action', 191);
        $table->string('severity', 16);
        $table->string('title')->nullable();
        $table->string('summary')->nullable();
        $table->string('actor_type', 32)->nullable();
        $table->string('actor_id', 64)->nullable();
        $table->string('actor_label')->nullable();
        $table->string('actor_url', 2048)->nullable();
        $table->string('subject_type', 64)->nullable();
        $table->string('subject_id', 64)->nullable();
        $table->string('subject_label')->nullable();
        $table->string('subject_class')->nullable();
        $table->string('subject_url', 2048)->nullable();
        $table->string('trace_id', 32);
        $table->string('correlation_id', 64)->nullable()->index();
        $table->string('causation_id', 64)->nullable()->index();
        $table->string('parent_span_id', 16)->nullable();
        $table->json('tags')->nullable();
        $table->string('ip', 45)->nullable();
        $table->string('user_agent')->nullable();
        $table->string('method', 8)->nullable();
        $table->string('url', 2048)->nullable();
        $table->string('route', 191)->nullable();
        $table->unsignedSmallInteger('status_code')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->unsignedInteger('memory_kb')->nullable();
        $table->string('environment', 32);
        $table->string('hostname')->nullable();
        $table->json('payload');
        $table->json('secrets')->nullable();
        $table->unsignedInteger('payload_bytes')->default(0);
        $table->string('integrity_hash', 64)->nullable();
        $table->dateTime('created_at', 6);

        $table->index(['channel', 'logged_at']);
        $table->index(['actor_type', 'actor_id', 'logged_at']);
        $table->index(['subject_type', 'subject_id', 'logged_at']);
        $table->index(['trace_id', 'logged_at']);
        $table->index(['route', 'status_code', 'logged_at']);
    }
}
