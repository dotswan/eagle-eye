<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('activity_logs', static function (Blueprint $table): void {
            ActivityLogSchema::define($table);
        });

        ActivityLogSchema::createIndex(Schema::getFacadeRoot());
        ActivityLogSchema::createErasureState(Schema::getFacadeRoot());
        ActivityLogSchema::createIntegrityState(Schema::getFacadeRoot());
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_integrity_entries');
        Schema::dropIfExists('activity_integrity_segments');
        Schema::dropIfExists('activity_integrity_state');
        Schema::dropIfExists('activity_erasure_receipts');
        Schema::dropIfExists('activity_erasure_barriers');
        Schema::dropIfExists('activity_delivery_attempts');
        Schema::dropIfExists('activity_security_state');
        Schema::dropIfExists('activity_log_index');
        Schema::dropIfExists('activity_logs');
    }
};
