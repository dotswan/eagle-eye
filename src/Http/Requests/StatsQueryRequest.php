<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Requests;

use DateTimeImmutable;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StatsQueryRequest extends FormRequest
{
    private const int MAX_RANGE_DAYS = 366;

    private ?DateTimeImmutable $fromDate = null;

    private ?DateTimeImmutable $toDate = null;

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'group_by' => ['sometimes', 'required', 'string', 'max:32', 'in:channel,action,severity,actor_type,subject_type,logged_at'],
            'interval' => ['sometimes', 'required', 'string', 'max:16', 'in:hour,day,month'],
            'from'     => ['sometimes', 'required', 'string', 'max:64', 'required_with:to'],
            'to'       => ['sometimes', 'required', 'string', 'max:64', 'required_with:from'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query->has('from') !== $this->query->has('to')) {
                $validator->errors()->add('from', 'Statistics require from and to dates together.');

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
                $validator->errors()->add('from', 'Statistics dates must be real ISO-8601 dates.');

                return;
            }

            if ($this->fromDate >= $this->toDate) {
                $validator->errors()->add('to', 'The statistics end must follow the start.');

                return;
            }

            if ($this->toDate->getTimestamp() - $this->fromDate->getTimestamp() > self::MAX_RANGE_DAYS * 86_400) {
                $validator->errors()->add('to', 'The statistics range may not exceed 366 days.');
            }
        }];
    }

    public function groupBy(): string
    {
        return (string) ($this->validated('group_by') ?? 'channel');
    }

    public function intervalValue(): string
    {
        return (string) ($this->validated('interval') ?? 'day');
    }

    public function toQuery(): ActivityQuery
    {
        $query = new ActivityQuery();

        if ($this->fromDate instanceof DateTimeImmutable && $this->toDate instanceof DateTimeImmutable) {
            return $query->between($this->fromDate, $this->toDate);
        }

        $to = new DateTimeImmutable();

        return $query->between($to->modify('-'.self::MAX_RANGE_DAYS.' days'), $to);
    }

}
