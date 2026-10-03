<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Controllers;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ActivityStreamController extends Controller
{
    public function stream(
        Request $request,
        ActivityLogDriverFactoryInterface $drivers,
        LogAccessPolicyInterface $policy,
    ): JsonResponse {
        if (! $policy->canView($request->user())) {
            abort(403, 'Viewing the activity log is not permitted.');
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'driver'       => $drivers->driver()->name(),
                'capabilities' => [],
                'reason'       => 'The package retrieval API does not implement live tailing.',
            ],
        ], 501);
    }
}
