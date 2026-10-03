<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Controllers;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActivityEntryPresenterInterface;
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\TraceTree;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Http\Requests\ActivityQueryRequest;
use Dotswan\EagleEye\Http\Requests\StatsQueryRequest;
use Dotswan\EagleEye\Http\Resources\ActivityEntryResource;
use Dotswan\EagleEye\Http\Resources\StatsResource;
use Dotswan\EagleEye\Http\Resources\TraceTreeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ActivityLogController extends Controller
{
    private const int TRACE_PAGE_ENTRIES = 15;

    public function index(
        ActivityQueryRequest $request,
        ActivityRepositoryInterface $repository,
        ActivityEntryPresenterInterface $presenter,
    ): AnonymousResourceCollection {
        $this->authorizeView($request);

        $query = $request->toQuery();
        $result = $repository->search($query);

        $collection = ActivityEntryResource::collection($presenter->present($result->entries));

        $collection->additional([
            'meta' => $this->meta() + [
                'count' => ['value' => $result->count ?? count($result->entries), 'exact' => $result->exactCount],
            ],
            'links' => ['next' => $result->nextCursor?->encode()],
        ]);

        return $collection;
    }

    public function show(string $id, Request $request, ActivityRepositoryInterface $repository, ActivityEntryPresenterInterface $presenter): ActivityEntryResource
    {
        $this->authorizeView($request);

        $entry = $repository->find($id);

        if (! $entry instanceof ActivityEntry || $entry->id !== $id) {
            abort(404, 'Activity entry not found.');
        }

        return (new ActivityEntryResource($presenter->presentOne($entry)))->additional(['meta' => $this->meta()]);
    }

    public function trace(
        string $traceId,
        Request $request,
        ActivityRepositoryInterface $repository,
        ActivityEntryPresenterInterface $presenter,
    ): JsonResponse {
        $this->authorizeView($request);

        $cursor = $request->query('cursor');

        if (null !== $cursor && (! is_string($cursor) || '' === $cursor || mb_strlen($cursor, '8bit') > 512)) {
            throw ValidationException::withMessages(['cursor' => ['The trace cursor is invalid.']]);
        }

        try {
            $query = (new ActivityQuery())->forTrace($traceId)->orderByTime(SortOrder::Ascending)->limit(self::TRACE_PAGE_ENTRIES);
            $query->cursor(is_string($cursor) ? Cursor::decode($cursor) : null);
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => ['The trace cursor is invalid.']]);
        }

        $result = $repository->search($query);
        $trees = TraceTree::assemble($presenter->present($result->entries));

        return response()->json([
            'data' => array_map(
                static fn (TraceTree $tree): array => (new TraceTreeResource($tree))->toArray($request),
                $trees,
            ),
            'meta' => $this->meta() + [
                'trace' => [
                    'complete' => ! $result->hasMore,
                    'returned' => count($result->entries),
                    'next'     => $result->nextCursor?->encode(),
                ],
            ],
        ]);
    }

    public function stats(StatsQueryRequest $request, ActivityRepositoryInterface $repository): StatsResource
    {
        $this->authorizeView($request);

        $groupBy = $request->groupBy();
        $interval = $request->intervalValue();

        try {
            $groups = $repository->aggregate($request->toQuery(), $groupBy, $interval);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['group_by' => [$exception->getMessage()]]);
        }

        return new StatsResource([
            'groups'   => $groups,
            'group_by' => $groupBy,
            'interval' => $interval,
        ]);
    }

    public function tags(Request $request, ActivityRepositoryInterface $repository): JsonResponse
    {
        $this->authorizeView($request);

        $prefix = $request->query('prefix');
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        if (false === $limit || (is_string($prefix) && mb_strlen($prefix, '8bit') > 256)) {
            throw ValidationException::withMessages(['limit' => ['The tag query is invalid.']]);
        }

        return response()->json([
            'data' => $repository->tags(is_string($prefix) && '' !== $prefix ? $prefix : null, $limit),
            'meta' => $this->meta(),
        ]);
    }

    public function reveal(
        string $id,
        Request $request,
        ActivityRepositoryInterface $repository,
        LogAccessPolicyInterface $policy,
    ): JsonResponse {
        $this->authorizeView($request);
        $entry = $repository->find($id);

        if (! $entry instanceof ActivityEntry || $entry->id !== $id) {
            abort(404, 'Activity entry not found.');
        }

        $path = $request->input('path');

        if (! is_string($path) || '' === $path || mb_strlen($path, '8bit') > 256 || 1 !== preg_match('/^[A-Za-z0-9_.-]+$/', $path)) {
            throw ValidationException::withMessages(['path' => ['A secret path is required.']]);
        }

        if (! $policy->canReveal($entry, $path, $request->user())) {
            abort(403, 'Revealing this secret is not permitted.');
        }

        // reveal() writes its own audit entry.
        $value = $repository->reveal($entry, $path, $request->user());

        return response()->json([
            'data' => ['path' => $path, 'value' => $value],
            'meta' => $this->meta(),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        $policy = app(LogAccessPolicyInterface::class);

        if (! $policy->canView($request->user())) {
            abort(403, 'Viewing the activity log is not permitted.');
        }
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        $driver = app(ActivityLogDriverFactoryInterface::class)->driver();
        return [
            'driver'       => $driver->name(),
            'capabilities' => array_values(array_map(
                static fn (Capability $capability): string => $capability->value,
                array_filter(Capability::cases(), static fn (Capability $capability): bool => Capability::LiveTail !== $capability && $driver->supports($capability)),
            )),
        ];
    }
}
