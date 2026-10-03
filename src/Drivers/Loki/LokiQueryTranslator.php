<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivityLogField;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use InvalidArgumentException;
use Dotswan\EagleEye\Data\Query\Criterion;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use DateTimeImmutable;
use Dotswan\EagleEye\Data\Query\ActivityQuery;

/**
 * Translates an ActivityQuery into LogQL. Filter ordering follows the
 * documented performance rule: line filters (|=) first, then metadata
 * filters, then parameterized JSON extraction when body fields are queried.
 *
 * @internal
 */
final readonly class LokiQueryTranslator
{
    /**
     * @param list<string> $labelNames stream labels the driver emits; severity
     *                                 filtering requires the `severity` label
     */
    public function __construct(
        private bool $structuredMetadata = true,
        private array $labelNames = ['env', 'channel', 'severity'],
        private ?string $selector = null,
    ) {
    }

    public function collectorMode(): bool
    {
        return null !== $this->selector;
    }

    public static function assertSelector(string $selector): void
    {
        $pattern = '/^\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*(=|!=|=~|!~)\s*"(?:[^"\\\\]|\\\\.)*"(\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*\s*(=|!=|=~|!~)\s*"(?:[^"\\\\]|\\\\.)*")*\s*\}$/';

        if ($selector !== mb_trim($selector) || 1 !== preg_match($pattern, $selector)) {
            throw new LokiMisconfigurationException('Invalid Loki read selector.');
        }
    }

    /**
     * @param array<string, string> $staticLabels
     */
    public function logql(ActivityQuery $query, array $staticLabels): string
    {
        return $this->renderLogql($query, $staticLabels);
    }

    /**
     * @param array<string, string> $staticLabels
     * @param list<string> $extraJsonFields
     */
    private function renderLogql(ActivityQuery $query, array $staticLabels, array $extraJsonFields = []): string
    {
        $selector = $this->selector($query, $staticLabels);
        $filters = [];

        if ($this->collectorMode()) {
            $filters[] = $this->markerFilter();
        }

        if (null !== $query->search && '' !== $query->search) {
            $filters[] = sprintf('|~ %s', $this->quote('(?i)'.$this->escapeRegex($query->search)));
        }

        if (null !== $query->subjectType && str_contains($query->subjectType, '\\')) {
            $filters[] = sprintf('|= %s', $this->quote('"subject_class":'.json_encode($query->subjectType, JSON_THROW_ON_ERROR)));
        }

        if (null !== $query->subjectClass) {
            $filters[] = sprintf('|= %s', $this->quote('"subject_class":'.json_encode($query->subjectClass, JSON_THROW_ON_ERROR)));
        }

        $filters = array_merge($filters, $this->metadataFilters($query, $extraJsonFields));

        return [] === $filters ? $selector : $selector.' '.implode(' ', $filters);
    }

    /** @param array<string, string> $staticLabels */
    public function exactIdLogql(string $id, array $staticLabels): string
    {
        $marker = $this->collectorMode() ? ' '.$this->markerFilter() : '';

        return $this->selector(new ActivityQuery(), $staticLabels).$marker.' | json ee_id="id" | ee_id='.$this->quote($id);
    }

    /** @param list<string> $ids */
    public function excludingIds(string $logql, array $ids): string
    {
        if ([] === $ids) {
            return $logql;
        }

        return $logql.' | json ee_id="id" | ee_id!~'.$this->quote(implode('|', array_map($this->escapeRegex(...), $ids)));
    }

    /**
     * Instant query returning the approximate total count for the range.
     *
     * @param array<string, string> $staticLabels
     */
    public function countExpression(ActivityQuery $query, array $staticLabels, int $rangeSeconds): string
    {
        return sprintf(
            'sum(count_over_time(%s[%ds]))',
            $this->logql($query, $staticLabels),
            $rangeSeconds,
        );
    }

    /**
     * Instant query that groups the entry count of the range by the given
     * stream label or structured-metadata key.
     *
     * @param array<string, string> $staticLabels
     */
    public function aggregateExpression(
        ActivityQuery $query,
        array $staticLabels,
        string $groupBy,
        int $rangeSeconds,
    ): string {
        $jsonGroup = $this->collectorMode()
            || (! $this->structuredMetadata && ('actor' === $groupBy || in_array($groupBy, LokiLineFormatter::METADATA_KEYS, true)));
        $groupFields = 'actor' === $groupBy
            ? [ActivityLogField::ActorType->value, ActivityLogField::ActorId->value]
            : [$this->sanitizeIdentifier($groupBy)];
        $groups = implode(', ', array_map(
            fn (string $field): string => $jsonGroup ? $this->jsonIdentifier($field) : $field,
            $groupFields,
        ));
        $logql = $this->renderLogql($query, $staticLabels, $jsonGroup ? $groupFields : []);

        if (! $jsonGroup && ('actor' === $groupBy || in_array($groupBy, LokiLineFormatter::METADATA_KEYS, true)) && ! str_contains($logql, '| json')) {
            $logql .= ' | json';
        }

        return sprintf(
            'sum by (%s) (count_over_time(%s[%ds]))',
            $groups,
            $logql,
            $rangeSeconds,
        );
    }

    /** @param array<string, string> $staticLabels */
    public function topAggregateExpression(ActivityQuery $query, array $staticLabels, string $groupBy, int $rangeSeconds, int $limit): string
    {
        return sprintf('topk(%d, %s)', max(1, min($limit, EnvelopeBudget::MAX_AGGREGATE_GROUPS + 1)), $this->aggregateExpression($query, $staticLabels, $groupBy, $rangeSeconds));
    }

    /**
     * Metric-range expression for one series point: `count_over_time` over
     * the step window, grouped when a group key is supplied.
     *
     * @param  array<string, string>  $staticLabels
     */
    public function seriesExpression(ActivityQuery $query, array $staticLabels, ?string $groupBy, int $stepSeconds): string
    {
        if (null === $groupBy) {
            return sprintf('sum(count_over_time(%s[%ds]))', $this->logql($query, $staticLabels), $stepSeconds);
        }

        return $this->aggregateExpression($query, $staticLabels, $groupBy, $stepSeconds);
    }

    /** @param array<string, string> $staticLabels */
    private function selector(ActivityQuery $query, array $staticLabels): string
    {
        if (null !== $this->selector) {
            return $this->selector;
        }

        $matchers = [];

        foreach ($staticLabels as $name => $value) {
            $matchers[$name] = sprintf('%s=%s', $this->sanitizeIdentifier($name), $this->quote($value));
        }

        if ([] !== $query->channels) {
            $channels = array_map(
                fn (ActivityChannel $channel): string => $this->escapeRegex($channel->value),
                $query->channels,
            );
            $matchers['channel'] = sprintf('channel=~%s', $this->quote(implode('|', $channels)));
        }

        if ([] !== $query->severities) {
            if (! in_array('severity', $this->labelNames, true)) {
                throw new InvalidArgumentException(
                    'Loki severity filtering requires the `severity` stream label; enable it in eagle-eye.drivers.loki.labels.dynamic.'
                );
            }

            $severities = array_map(
                fn (ActivitySeverity $severity): string => $this->escapeRegex($severity->value),
                $query->severities,
            );
            $matchers['severity'] = sprintf('severity=~%s', $this->quote(implode('|', $severities)));
        }

        return sprintf('{%s}', implode(',', array_values($matchers)));
    }

    /**
     * Metadata filters only; class/FQCN matches are emitted as line
     * filters by logql() so they can be evaluated before parsing.
     *
     * @param list<string> $extraJsonFields
     * @return list<string>
     */
    private function metadataFilters(ActivityQuery $query, array $extraJsonFields = []): array
    {
        if ($this->collectorMode() || ! $this->structuredMetadata) {
            return $this->jsonFilters($query, $extraJsonFields);
        }

        $filters = [];

        if (null !== $query->actorType) {
            $filters[] = sprintf('| actor_type=%s', $this->quote($query->actorType));
        }

        if (null !== $query->actorId) {
            $filters[] = sprintf('| actor_id=%s', $this->quote($query->actorId));
        }

        if (null !== $query->subjectType && ! str_contains($query->subjectType, '\\')) {
            $filters[] = sprintf('| subject_type=%s', $this->quote($query->subjectType));
        }

        if (null !== $query->subjectId) {
            $filters[] = sprintf('| subject_id=%s', $this->quote($query->subjectId));
        }

        if (null !== $query->traceId) {
            $filters[] = sprintf('| trace_id=%s', $this->quote($query->traceId));
        }

        if ([] !== $query->actions) {
            $actions = array_map($this->escapeRegex(...), $query->actions);
            $filters[] = sprintf('| action=~%s', $this->quote('^('.implode('|', $actions).')$'));
        }

        if ([] !== $query->tags) {
            $tags = $this->tagExpressions($query);
            $filters[] = '| json '.implode(', ', $tags['extractions']);
            $filters[] = '| ('.implode(' or ', $tags['matches']).')';
        }

        if ($query->status instanceof Criterion) {
            $operator = '=' === $query->status->operator ? '==' : $query->status->operator;
            $filters[] = sprintf('| status_code %s %d', $operator, (int) $query->status->value);
        }

        return $filters;
    }

    /**
     * @param list<string> $extraFields
     * @return list<string>
     */
    private function jsonFilters(ActivityQuery $query, array $extraFields = []): array
    {
        $fields = $extraFields;
        $filters = [];

        if ($this->collectorMode() && [] !== $query->channels) {
            $field = ActivityLogField::Channel->value;
            $fields[] = $field;
            $channels = array_map(
                fn (ActivityChannel $channel): string => $this->escapeRegex($channel->value),
                $query->channels,
            );
            $filters[] = sprintf('| %s=~%s', $this->jsonIdentifier($field), $this->quote(implode('|', $channels)));
        }

        if ($this->collectorMode() && [] !== $query->severities) {
            $field = ActivityLogField::Severity->value;
            $fields[] = $field;
            $severities = array_map(
                fn (ActivitySeverity $severity): string => $this->escapeRegex($severity->value),
                $query->severities,
            );
            $filters[] = sprintf('| %s=~%s', $this->jsonIdentifier($field), $this->quote(implode('|', $severities)));
        }

        if ([] !== $query->actions) {
            $field = ActivityLogField::Action->value;
            $fields[] = $field;
            $actions = array_map($this->escapeRegex(...), $query->actions);
            $filters[] = sprintf('| %s=~%s', $this->jsonIdentifier($field), $this->quote('^('.implode('|', $actions).')$'));
        }

        $criteria = [
            ActivityLogField::ActorType->value   => $query->actorType,
            ActivityLogField::ActorId->value     => $query->actorId,
            ActivityLogField::SubjectType->value => null !== $query->subjectType && ! str_contains($query->subjectType, '\\') ? $query->subjectType : null,
            ActivityLogField::SubjectId->value   => $query->subjectId,
            ActivityLogField::TraceId->value     => $query->traceId,
        ];

        foreach ($criteria as $field => $value) {
            if (null === $value) {
                continue;
            }

            $fields[] = $field;
            $filters[] = sprintf('| %s=%s', $this->jsonIdentifier($field), $this->quote($value));
        }

        if ($query->status instanceof Criterion) {
            $field = ActivityLogField::StatusCode->value;
            $fields[] = $field;
            $operator = '=' === $query->status->operator ? '==' : $query->status->operator;
            $filters[] = sprintf('| %s %s %d', $this->jsonIdentifier($field), $operator, (int) $query->status->value);
        }

        $tags = [] === $query->tags ? null : $this->tagExpressions($query);

        if ([] !== $fields || null !== $tags) {
            array_unshift($filters, $this->jsonExtraction($fields, $tags['extractions'] ?? []));
        }

        if (null !== $tags) {
            $filters[] = '| ('.implode(' or ', $tags['matches']).')';
        }

        return $filters;
    }

    /** @return array{extractions: list<string>, matches: list<string>} */
    private function tagExpressions(ActivityQuery $query): array
    {
        $escaped = array_map($this->escapeRegex(...), array_values(array_filter($query->tags, static fn (string $tag): bool => '' !== $tag)));
        $extractions = [];
        $matches = [];

        for ($index = 0; $index < EnvelopeBudget::MAX_TAGS; $index++) {
            $name = 'ee_tag_'.$index;
            $extractions[] = $name.'="tags['.$index.']"';
            $matches[] = $name.'=~'.$this->quote([] === $escaped ? '\\b\\B' : implode('|', $escaped));
        }

        return ['extractions' => $extractions, 'matches' => $matches];
    }

    /**
     * @param list<string> $fields
     * @param list<string> $extraExpressions
     */
    private function jsonExtraction(array $fields, array $extraExpressions = []): string
    {
        $fields = array_values(array_unique($fields));
        $expressions = array_map(
            fn (string $field): string => sprintf('%s="%s"', $this->jsonIdentifier($field), $this->sanitizeIdentifier($field)),
            $fields,
        );

        $expressions = array_merge($expressions, $extraExpressions);

        return '| json '.implode(', ', $expressions);
    }

    private function jsonIdentifier(string $field): string
    {
        return 'ee_'.$this->sanitizeIdentifier($field);
    }

    private function markerFilter(): string
    {
        return '|= '.$this->quote('{"'.LokiLineFormatter::STDOUT_MARKER.'":true,');
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '\\"', str_replace('\\', '\\\\', $value)).'"';
    }

    /**
     * RE2-friendly escaping: preg_quote escapes characters RE2 does not
     * treat specially (e.g. `\:`), which Loki then rejects.
     */
    private function escapeRegex(string $value): string
    {
        $escaped = '';

        foreach (mb_str_split($value) as $char) {
            $escaped .= in_array($char, ['.', '[', ']', '{', '}', '(', ')', '^', '$', '*', '+', '?', '|', '\\'], true)
                ? '\\'.$char
                : $char;
        }

        return $escaped;
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new LokiMisconfigurationException("Invalid LogQL identifier [{$identifier}].");
        }

        return $identifier;
    }

    public function toNanoseconds(DateTimeImmutable $at): string
    {
        return (string) ($at->getTimestamp() * 1_000_000_000 + ((int) $at->format('u') * 1000));
    }
}
