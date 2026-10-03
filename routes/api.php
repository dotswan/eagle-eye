<?php

declare(strict_types=1);

use Dotswan\EagleEye\Http\Controllers\ActivityLogController;
use Dotswan\EagleEye\Http\Controllers\ActivityStreamController;
use Illuminate\Support\Facades\Route;

Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('eagle-eye.activity-logs.index');
Route::get('activity-logs/traces/{traceId}', [ActivityLogController::class, 'trace'])->name('eagle-eye.activity-logs.trace');
Route::get('activity-logs/stats', [ActivityLogController::class, 'stats'])->name('eagle-eye.activity-logs.stats');
Route::get('activity-logs/tags', [ActivityLogController::class, 'tags'])->name('eagle-eye.activity-logs.tags');
Route::get('activity-logs/stream', [ActivityStreamController::class, 'stream'])->name('eagle-eye.activity-logs.stream');
Route::get('activity-logs/{id}', [ActivityLogController::class, 'show'])->name('eagle-eye.activity-logs.show');
Route::post('activity-logs/{id}/reveal', [ActivityLogController::class, 'reveal'])->name('eagle-eye.activity-logs.reveal');
