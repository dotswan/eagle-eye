<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Requests;

use Throwable;
use DateTimeImmutable;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\SortOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Rule as ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;

final class ActivityQueryRequest extends FormRequest
{
    private const int MAX_RANGE_DAYS = 366;

    private ?DateTimeImmutable $fromDate = null;

    private ?DateTimeImmutable $toDate = null;

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'actor_type'   => ['sometimes', 'required_with:actor_id', 'string', 'max:64'],
            'actor_id'     => ['sometimes', 'required_with:actor_type', 'string', 'max:64'],
            'subject_type' => ['sometimes', 'required_with:subject_id', 'string', 'max:128'],
            'subject_id'   => ['sometimes', 'required_with:subject_type', 'string', 'max:64'],
            'channel'      => ['sometimes', Rule::enum(ActivityChannel::class)],
            'trace_id'     => ['sometimes', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'tags'         => ['sometimes', 'string', 'max:255'],
            'search'       => ['sometimes', 'string', 'max:255'],
            'status'       => ['sometimes', 'string', 'regex:/^(>=|<=|!=|>|<|=)?\d{3}$/'],
            'from'         => ['sometimes', 'required_with:to', 'string', 'max:64'],
            'to'           => ['sometimes', 'required_with:from', 'string', 'max:64'],
            'cursor'       => ['sometimes', 'string', 'max:512'],
            'per_page'     => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort'         => ['sometimes', 'in:asc,desc'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query->has('from') !== $this->query->has('to')) {
                $validator->errors()->add('from', 'Activity queries require from and to dates together.');

                return;
            }

            $validated = $validator->validated();

            if (! isset($validated['from'], $validated['to']) || ! is_string($validated['from']) || ! is_string($validated['to'])) {
                return;
            }

            $timezone = (string) config('app.timezone', 'UTC');
            $this->fromDate = IsoDateParser::parse($validated['from'], $timezone);
            $this->toDate = IsoDateParser::parse($validated['to'], $timezone);

            if ($this->toDate instanceof DateTimeImmutable && 10 === mb_strlen($validated['to'], '8bit')) {
                $this->toDate = $this->toDate->modify('+1 day');
            }

            if (! $this->fromDate instanceof DateTimeImmutable || ! $this->toDate instanceof DateTimeImmutable) {
                $validator->errors()->add('from', 'Activity dates must be real ISO-8601 dates.');

                return;
            }

            if ($this->fromDate >= $this->toDate) {
                $validator->errors()->add('to', 'The activity end must follow the start.');

                return;
            }

            if ($this->toDate->getTimestamp() - $this->fromDate->getTimestamp() > self::MAX_RANGE_DAYS * 86_400) {
                $validator->errors()->add('to', 'The activity range may not exceed 366 days.');
            }
        }];
    }

    public function toQuery(): ActivityQuery
    {
        $query = new ActivityQuery();
        $validated = $this->validated();

        if ($this->fromDate instanceof DateTimeImmutable && $this->toDate instanceof DateTimeImmutable) {
            $query->between($this->fromDate, $this->toDate);
        }

        if (isset($validated['actor_type'], $validated['actor_id'])) {
            $query->forActor((string) $validated['actor_type'], (string) $validated['actor_id']);
        }

        if (isset($validated['subject_type'], $validated['subject_id'])) {
            $query->forSubject((string) $validated['subject_type'], (string) $validated['subject_id']);
        }

        if (isset($validated['channel']) && null !== ($channel = ActivityChannel::tryFrom((string) $validated['channel']))) {
            $query->channels($channel);
        }

        if (isset($validated['trace_id'])) {
            $query->forTrace((string) $validated['trace_id']);
        }

        if (isset($validated['tags'])) {
            $query->withAnyTag(array_values(array_filter(explode(',', (string) $validated['tags']))));
        }

        if (isset($validated['search'])) {
            $query->search((string) $validated['search']);
        }

        if (isset($validated['status']) && preg_match('/^(>=|<=|!=|>|<|=)?(\d{3})$/', (string) $validated['status'], $matches)) {
            $query->whereStatus('' !== $matches[1] ? $matches[1] : '=', (int) $matches[2]);
        }

        if (isset($validated['sort'])) {
            $query->orderByTime('asc' === $validated['sort'] ? SortOrder::Ascending : SortOrder::Descending);
        }

        $query->limit((int) ($validated['per_page'] ?? 50));

        if (isset($validated['cursor'])) {
            try {
                $query->cursor(Cursor::decode((string) $validated['cursor']));
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => ['The activity cursor is invalid.']]);
            }
        }

        return $query;
    }
}
