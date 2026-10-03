<?php

declare(strict_types=1);

use Dotswan\EagleEye\Drivers\Loki\LokiMisconfigurationException;
use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\Loki\LokiLabelGuard;
use Dotswan\EagleEye\Drivers\Loki\LokiQueryTranslator;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Exceptions\InvalidLabelException;

it('builds a selector with static labels and channel regex', function (): void {
    $query = (new ActivityQuery())->channels(ActivityChannel::Model, ActivityChannel::Http);
    $logql = (new LokiQueryTranslator())->logql($query, ['app' => 'my-app']);

    expect($logql)->toBe('{app="my-app",channel=~"model|http"}');
});

it('maps every query shape onto structured metadata filters', function (): void {
    $query = (new ActivityQuery())
        ->forActor('user', 8821)
        ->forSubject('campaign', '42')
        ->forTrace('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->withAnyTag(['gdpr', 'campaign:1'])
        ->whereStatus('>=', 500)
        ->search('payment declined');

    $logql = (new LokiQueryTranslator())->logql($query, ['app' => 'my-app']);

    expect($logql)
        ->toContain('{app="my-app"}')
        ->toContain('|~ "(?i)payment declined"')
        ->toContain('| actor_type="user"')
        ->toContain('| actor_id="8821"')
        ->toContain('| subject_type="campaign"')
        ->toContain('| subject_id="42"')
        ->toContain('| trace_id="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"')
        ->toContain('| json ee_tag_0="tags[0]"')
        ->toContain('ee_tag_0=~')
        ->toContain('| status_code >= 500');

    $lineFilterPos = mb_strpos($logql, '|~');
    $metadataPos = mb_strpos($logql, '| actor_type');

    expect($lineFilterPos)->toBeInt()
        ->and($metadataPos)->toBeInt();

    if (is_int($lineFilterPos) && is_int($metadataPos)) {
        expect($lineFilterPos)->toBeLessThan($metadataPos);
    }
});

it('lines fall back to | json mode without structured metadata', function (): void {
    $query = (new ActivityQuery())->forActor('user', 8821);
    $logql = (new LokiQueryTranslator(false))->logql($query, ['app' => 'my-app']);

    expect($logql)->toBe('{app="my-app"} | json ee_actor_type="actor_type", ee_actor_id="actor_id" | ee_actor_type="user" | ee_actor_id="8821"');
});

it('matches JSON encoded subject classes through exact line filters', function (): void {
    $query = (new ActivityQuery())->forSubject('App\\Models\\Campaign', '9');
    $logql = (new LokiQueryTranslator())->logql($query, ['app' => 'my-app']);

    $needle = '"subject_class":'.json_encode('App\\Models\\Campaign', JSON_THROW_ON_ERROR);
    $quoted = json_encode($needle, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    expect($logql)->toContain('|= '.$quoted)
        ->toContain('| subject_id="9"');
});

it('escapes quotes and backslashes in label values', function (): void {
    $query = (new ActivityQuery())->forActor('us"er', '4\\2');
    $logql = (new LokiQueryTranslator())->logql($query, ['app' => 'my-app']);

    expect($logql)->toBe('{app="my-app"} | actor_type="us\\"er" | actor_id="4\\\\2"');
});

it('rejects identifiers that are not valid LogQL label names', function (): void {
    $query = new ActivityQuery();
    (new LokiQueryTranslator())->logql($query, ['bad-label' => 'x']);
})->throws(LokiMisconfigurationException::class);

it('builds metric expressions for count and aggregation', function (): void {
    $query = (new ActivityQuery())->channels(ActivityChannel::Http);
    $translator = new LokiQueryTranslator();

    expect($translator->countExpression($query, ['app' => 'my-app'], 3600))
        ->toBe('sum(count_over_time({app="my-app",channel=~"http"}[3600s]))')
        ->and($translator->aggregateExpression($query, ['app' => 'my-app'], 'severity', 3600))
        ->toBe('sum by (severity) (count_over_time({app="my-app",channel=~"http"}[3600s]))');
});

it('builds series expressions with the step window', function (): void {
    $query = (new ActivityQuery())->channels(ActivityChannel::Http);
    $translator = new LokiQueryTranslator();

    expect($translator->seriesExpression($query, ['app' => 'my-app'], null, 300))
        ->toBe('sum(count_over_time({app="my-app",channel=~"http"}[300s]))')
        ->and($translator->seriesExpression($query, ['app' => 'my-app'], 'severity', 300))
        ->toBe('sum by (severity) (count_over_time({app="my-app",channel=~"http"}[300s]))');
});

it('extracts json metadata before fallback aggregation and groups actor identity', function (): void {
    $translator = new LokiQueryTranslator(structuredMetadata: false);

    expect($translator->aggregateExpression(new ActivityQuery(), ['app' => 'my-app'], 'actor_type', 60))
        ->toBe('sum by (ee_actor_type) (count_over_time({app="my-app"} | json ee_actor_type="actor_type"[60s]))')
        ->and($translator->aggregateExpression(new ActivityQuery(), ['app' => 'my-app'], 'actor', 60))
        ->toBe('sum by (ee_actor_type, ee_actor_id) (count_over_time({app="my-app"} | json ee_actor_type="actor_type", ee_actor_id="actor_id"[60s]))')
        ->and($translator->aggregateExpression(new ActivityQuery(), ['app' => 'my-app'], 'channel', 60))
        ->toBe('sum by (channel) (count_over_time({app="my-app"}[60s]))');
});

it('uses a collector selector and parameterized body filters', function (): void {
    $query = (new ActivityQuery())
        ->channels(ActivityChannel::Model, ActivityChannel::Http)
        ->severities(ActivitySeverity::Warning)
        ->forActor('user', 8821)
        ->whereStatus('>=', 500);
    $translator = new LokiQueryTranslator(selector: '{namespace="my-app-prod"}');

    expect($translator->logql($query, ['app' => 'ignored']))
        ->toBe('{namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_channel="channel", ee_severity="severity", ee_actor_type="actor_type", ee_actor_id="actor_id", ee_status_code="status_code" | ee_channel=~"model|http" | ee_severity=~"warning" | ee_actor_type="user" | ee_actor_id="8821" | ee_status_code >= 500');
});

it('puts the collector marker before search and subject class filters', function (): void {
    $query = (new ActivityQuery())
        ->forSubject('App\\Models\\Campaign', '9')
        ->search('payment declined');
    $logql = (new LokiQueryTranslator(selector: '{namespace="my-app-prod"}'))->logql($query, ['app' => 'ignored']);

    $marker = mb_strpos($logql, '|= "{\\"eagle_eye\\":true,"');
    $search = mb_strpos($logql, '|~');
    $subject = mb_strpos($logql, '|= "\\"subject_class');

    expect($marker)->toBeInt()
        ->and($search)->toBeInt()
        ->and($subject)->toBeInt();

    if (is_int($marker) && is_int($search) && is_int($subject)) {
        expect($marker)->toBeLessThan($search)
            ->and($search)->toBeLessThan($subject);
    }
});

it('uses marker and prefixed identity extraction for collector detail queries', function (): void {
    $id = '01J0000000000000000000ID1';
    $translator = new LokiQueryTranslator(selector: '{namespace="my-app-prod"}');

    expect($translator->exactIdLogql($id, ['app' => 'ignored']))
        ->toBe('{namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_id="id" | ee_id="'.$id.'"');
});

it('uses prefixed collector aggregate groups and extraction', function (string $groupBy, string $expected): void {
    $translator = new LokiQueryTranslator(selector: '{namespace="my-app-prod"}');

    expect($translator->aggregateExpression(new ActivityQuery(), ['app' => 'ignored'], $groupBy, 60))->toBe($expected);
})->with([
    'channel'  => ['channel', 'sum by (ee_channel) (count_over_time({namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_channel="channel"[60s]))'],
    'severity' => ['severity', 'sum by (ee_severity) (count_over_time({namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_severity="severity"[60s]))'],
    'actor'    => ['actor', 'sum by (ee_actor_type, ee_actor_id) (count_over_time({namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_actor_type="actor_type", ee_actor_id="actor_id"[60s]))'],
    'actor id' => ['actor_id', 'sum by (ee_actor_id) (count_over_time({namespace="my-app-prod"} |= "{\\"eagle_eye\\":true," | json ee_actor_id="actor_id"[60s]))'],
]);

it('anchors the action filter in structured metadata mode', function (): void {
    $query = (new ActivityQuery())->actions('model.updated', 'user.login');
    $logql = (new LokiQueryTranslator())->logql($query, ['app' => 'my-app']);

    expect($logql)->toBe('{app="my-app"} | action=~"^(model\\\\.updated|user\\\\.login)$"');
});

it('extracts and anchors the action filter in collector mode', function (): void {
    $query = (new ActivityQuery())->actions('model.updated');
    $logql = (new LokiQueryTranslator(selector: '{namespace="my-app-prod"}'))->logql($query, ['app' => 'ignored']);

    expect($logql)->toContain('| json ee_action="action"')
        ->toContain('| ee_action=~"^(model\\\\.updated)$"');
});

it('validates collector selectors', function (string $selector): void {
    LokiQueryTranslator::assertSelector($selector);

    expect(true)->toBeTrue();
})->with([
    '{a="b"}',
    '{a="b", c=~"d.*"}',
    '{a="escaped\"quote"}',
]);

it('rejects invalid collector selectors', function (string $selector): void {
    LokiQueryTranslator::assertSelector($selector);
})->with([
    '',
    'a="b"',
    '{}',
    '{a="b"} | json',
    '{1a="b"}',
    '{a=b}',
])->throws(LokiMisconfigurationException::class);

it('builds an exact backend line predicate for detail identity', function (): void {
    $id = '01J0000000000000000000ID1';

    expect((new LokiQueryTranslator())->exactIdLogql($id, ['app' => 'my-app']))
        ->toContain('| json ee_id="id" | ee_id="'.$id.'"');
});

it('excludes exact already traversed identities without regex injection', function (): void {
    $translator = new LokiQueryTranslator();
    expect($translator->excludingIds('{app="my-app"}', []))->toBe('{app="my-app"}')
        ->and($translator->excludingIds('{app="my-app"}', ['a.b', 'second']))
        ->toContain('| ee_id!~"a\\\\.b|second"');
});

it('asks Loki for a bounded complete ranking corpus', function (): void {
    expect((new LokiQueryTranslator())->topAggregateExpression(new ActivityQuery(), ['app' => 'my-app'], 'actor', 60, 1001))
        ->toStartWith('topk(1001,');
});

it('converts datetimes to unix nanoseconds', function (): void {
    $at = new DateTimeImmutable('2026-09-04 10:14:02.140000', new DateTimeZone('UTC'));

    expect((new LokiQueryTranslator())->toNanoseconds($at))->toBe('1788516842140000000');
});

it('rejects disallowed labels at boot', function (): void {
    (new LokiLabelGuard())->validate(['actor_id' => '42'], []);
})->throws(InvalidLabelException::class, 'actor_id');

it('rejects label names colliding with structured metadata keys', function (): void {
    (new LokiLabelGuard())->validate([], ['channel']);
})->throws(InvalidLabelException::class, 'channel');

it('accepts the shipped label set', function (): void {
    (new LokiLabelGuard())->validate(
        ['app' => 'my-app', 'env' => 'production', 'channel' => '', 'severity' => '', 'service' => 'web', 'tenant' => 'acme'],
        LokiLineFormatter::METADATA_KEYS,
    );

    expect(true)->toBeTrue();
});
