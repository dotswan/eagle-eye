# Filtering

Filtering decides which drafts become entries. It runs before persistence and before the expensive pipeline stages, so excluding noisy traffic is also the most effective performance setting.

## Order of checks

For every draft:

1. **Hard suppressors.** Nothing is recorded inside `EagleEye::withoutLogging()` / `eagle_eye_silent()`, while `EagleEye::pause()` is active, during Eagle Eye's own internal work, when `enabled` is `false`, or when the application environment is not listed in `environments`.
2. **Gate (before the pipeline).** A cheap check of `filters.channels`, `filters.severity.min`, `filters.actors.except`, `filters.method`, and `filters.url`. A rejected draft costs a few array lookups.
3. **Filter chain (`ApplyFilters` pipe).** URL, method, channel, actor, tag, environment, and model-class rules plus your custom rules, evaluated deny-first.
4. **Sampling (`SampleEntry` pipe).** Deterministic per trace, after filtering.
5. **Subject opt-out (`ReadSubjectMetadata` pipe).** Drafts whose subject class has `#[DoNotLog]` are dropped.

Request-part projection (`filters.request_parts`) does not drop drafts; it removes parts of the request from entries that are kept.

## Rule semantics

Each rule returns `allow`, `deny`, or `abstain`:

- A rule's `except` list matching any candidate value returns **deny**.
- If the rule has an `only` list, a match returns **allow** and no match returns **deny**.
- Otherwise the rule **abstains**.

The chain stops at the first **deny**. If at least one rule allowed, the draft is kept. If every rule abstained, `filters.default_action` decides (`allow` by default). With `default_action = deny`, only drafts explicitly allowed by an `only` list or a custom rule are recorded.

## Pattern syntax

Every list accepts:

| Pattern | Example | Matches |
|---|---|---|
| Exact string | `health` | Exactly `health` |
| Wildcard | `admin/*`, `horizon*` | `*` matches any characters (including `/`) |
| Regular expression | `/^api\/v[0-9]+\/internal/` | A delimited PCRE pattern (starts and ends with `/`) |

## Available filters

| Key | Candidate values |
|---|---|
| `filters.url.only` / `except` | The full URL, the path, and the path without its leading slash, so both `health` and `/health` work. |
| `filters.method.only` / `except` | Upper-case HTTP method. |
| `filters.channels.only` / `except` | Channel value (`http`, `model`, `auth`, `queue`, `console`, `mail`, `notification`, `outbound_http`, `exception`, `log`, `schedule`, `query`, `custom`). |
| `filters.actors.only` / `except` | Actor type (`user`, `console`, `guest`), actor id, and `type:id`. |
| `filters.tags.only` / `except` | Tags present when the chain runs: tags on the draft, `tags.static`, and scoped `withTags()` tags. Automatic and attribute tags are added later and are not visible to tag rules. |
| `filters.environments.only` / `except` | The entry's application environment. |
| `filters.models.only` / `except` | The subject class. A configured class or interface also matches subclasses and implementations; wildcards such as `App\Models\Internal\*` are supported. |
| `filters.severity.min` | Minimum severity: `debug` < `info` < `notice` < `warning` < `error` < `critical` < `alert` < `emergency`. |
| `filters.rules` | Custom rules (see below). |

Defaults exclude health checks, Horizon, Telescope, Pulse, Debugbar, and Livewire update and asset requests from URLs, and `OPTIONS`/`HEAD` methods. See [Configuration](configuration.md#filters-and-overrides).

## Examples

```php
// config/eagle-eye.php
'filters' => [
    'default_action' => 'allow',
    'url'      => ['only' => [], 'except' => ['up', 'health', 'metrics', 'internal/*']],
    'method'   => ['only' => [], 'except' => ['OPTIONS', 'HEAD']],
    'channels' => ['only' => [], 'except' => ['notification']],
    'actors'   => ['except' => ['user:1']],              // a service account
    'models'   => ['only' => [], 'except' => [App\Models\Session::class, 'App\Models\Cache\*']],
    'severity' => ['min' => 'info'],
    'request_parts' => ['only' => [], 'except' => ['cookies', 'session', 'headers']],
    'rules'    => [App\ActivityLog\SkipHealthProbes::class],
    // ...
],
```

Keep the other keys of the published file (`tags`, `environments`) when editing it.

## Per-route overrides

`overrides` maps a route name, URL, or path pattern to a partial `filters` array. For a matching request it is merged recursively over the global filters (lists are replaced, not appended). The first matching pattern wins.

```php
'overrides' => [
    'admin/*'          => ['request_parts' => ['only' => ['query']]],
    'webhooks.*'       => ['severity' => ['min' => 'warning']],
    'api/v1/reports/*' => ['channels' => ['except' => ['model']]],
],
```

URL, method, and route-based filters and overrides match drafts that carry a request snapshot, which is the `http.request` entry. Model, mail, outbound, and other entries recorded during the same request have no request snapshot: URL and method rules abstain for them and overrides do not apply. Use channel, model, actor, or tag rules to filter those.

## Request parts

`filters.request_parts` withholds parts of the HTTP request from entries. Values are `RequestPart` cases: `headers`, `query`, `body`, `files`, `cookies`, `session`, `route_params`, `response_headers`, `response_body`, `ip`, `user_agent`. With an `only` list, every part not listed is withheld. `except` wins over `capture.http.parts` and `capture.http.body_routes`.

## Custom rules

Implement `Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface` and list the class in `filters.rules`. Rules are resolved from the container, so constructor injection works.

```php
namespace App\ActivityLog;

use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;

final class SkipHealthProbes implements FilterRuleInterface
{
    public function decide(ActivityDraft $draft): FilterDecision
    {
        return str_contains((string) $draft->request?->userAgent, 'kube-probe')
            ? FilterDecision::Deny
            : FilterDecision::Abstain;
    }
}
```

Closures returning a `FilterDecision` are also accepted in `filters.rules`, but they prevent `php artisan config:cache`; prefer classes.

Rules run for every draft that passes the gate, so keep them cheap: no database queries or HTTP calls.

## Suppressing in code

```php
use Dotswan\EagleEye\Facades\EagleEye;

EagleEye::withoutLogging(function (): void {
    // imports, seeders, bulk maintenance: nothing is recorded here
});

EagleEye::pause();   // until resume()
EagleEye::resume();
```

```php
use Dotswan\EagleEye\Attributes\DoNotLog;

#[DoNotLog]
final class LoginAttemptCounter extends Model
{
}
```

## Sampling

Sampling is not a filter: it keeps a deterministic fraction of traces after filtering. Configure it with `sampling.rate`, `sampling.channels`, and `sampling.routes`. See [Configuration › Sampling](configuration.md#sampling).
