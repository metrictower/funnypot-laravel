# Piece E · honeypot-laravel — implementation plan

Status: planning only. Implements the design spec `2026-08-19-honeypot-laravel-design.md` (source of
truth); this document does not redesign it. Package: `metrictower/funnypot-laravel`.
Date: 2026-08-19.

**Model (decision M):** E is a **thin Laravel adapter over `metrictower/funnypot-policy`**. It
normalizes an `Illuminate\Http\Request` into a `RequestEvidence` + `SiteProfile`, asks
`Funnypot\Policy\PolicyEngine::evaluate()` for a `Decision`, and **executes** that `Decision` at up to
two positions (BEFORE middleware + FALLBACK 404 hook). It implements the policy's Laravel ports
(`StateStoreInterface`, `ReputationInterface` cache-first, `Clock`, `Logger`) and delivers a
`Decision`'s already-suppressed `ReportIntent` to mainnet. It computes **no** decision logic of its own.

This is a disciplined, test-first plan. Each phase is a small, independently-verifiable increment: the
change, the test written **first**, the exact command to run it green, and done-criteria. Phases are
ordered so the suite stays green throughout and each builds on the last.

---

## Orientation

### What exists now

- **`honeypot-laravel/`** is empty except `docs/` (this plan + the design spec). No `composer.json`,
  no `src/`, no tests. Everything below is greenfield in this repo.
- **The Laravel bridge already exists inside funnypot-core** and is what E adopts, moved (same
  `Funnypot\Laravel\` FQCNs — design §4, key decision #2) and **re-pointed at the policy engine**:
  - `funnypot-core/src/Laravel/FunnypotServiceProvider.php`
  - `funnypot-core/src/Laravel/HoneypotMiddleware.php`
  - `funnypot-core/src/Laravel/LaravelRequestMapper.php`
  - `funnypot-core/src/Laravel/LaravelResponseMapper.php`
  - `funnypot-core/src/Laravel/Console/RulesUpdateCommand.php`
  - `funnypot-core/src/Laravel/Console/UpdateTemplatesCommand.php`
  - `funnypot-core/config/funnypot.php` (the publishable config)
  - `funnypot-core/composer.json` → `extra.laravel.providers` + the `illuminate/*` `suggest` block.
  The **behavior** these carried (call core's old `detect`/`respond`, wire a reporting `Observer`) is
  replaced by the policy-engine contract; only the class shells + FQCNs are reused.
- **The policy engine E consumes** — `metrictower/funnypot-policy`, PHP >= 7.3, framework-free
  (`funnypot-policy/docs/2026-08-19-funnypot-policy-design.md`):
  - `Funnypot\Policy\PolicyEngine::evaluate(RequestEvidence): Decision` (built via
    `PolicyEngine::fromArray($configArray, $ports)`).
  - `Funnypot\Policy\Decision` — `action()` (`allow|log|block|deceive`), `status()`, `fakeHandle()`
    (`?FakeResponse`), `pinTtl()`, `report()` (`?ReportIntent`), `reason()`.
  - `Funnypot\Policy\RequestEvidence`, `Funnypot\Policy\SiteProfile` (`stack()`, `routeExists($path)`,
    `isSacrificialPath($path)`), `Funnypot\Policy\PolicyConfig::fromArray()`.
  - Ports (namespace `Funnypot\Policy\Port`): `EvaluatorInterface` (`classify`/`synthesize` — held by
    the policy, wrapping core's two-phase engine), `ReputationInterface` (`lookup($ip)`, cache-first),
    `StateStoreInterface` (pins, blocklist, rule state, suppression ledger, actor counters), `Clock`
    (`now()`), `Logger` (`log()`).
- **Core seams E still consumes directly** (for the commands, all in `funnypot-core/src/`):
  `Rules\RulesLocator::useDataDir()`, `Rules\RulesUpdater`, `Support\CorePaths` (Phase 6), and
  `bin/funnypot` (the compile CLI, invoked by `funnypot:update`).

### The decisive difference from the old plan

The old E computed the response itself (`map → detect → respond`, an inline reputation gate, a core
`Observer` for reporting). Under M, **all of that is the policy's**. E's phases build the **adapter**:
request normalization (+ `SiteProfile` + seed), `Decision` execution at two positions, the four Laravel
port adapters, and report delivery. The security-critical decision logic is tested in the policy
package's own suite; E's tests prove the glue.

### How to run E's tests

Framework-free-on-host discipline (per project CLAUDE.md): from the `honeypot-laravel/` repo root,

```
composer install
php vendor/bin/phpunit
```

`orchestra/testbench` is a dev-only dependency; it pulls a real `laravel/framework` into `vendor/` for
the test run. No DB, no Redis, no container — testbench runs in-process. The CI matrix (Phase 8) runs
this same command against multiple `testbench`/Laravel versions.

### Cross-piece dependencies at a glance (detail in each phase + "Risks")

- **`funnypot-policy` is the primary dependency** (decision M): E `require`s it; core + mainnet-client
  arrive transitively (policy `require`s core and consumes mainnet-client behind `ReputationInterface`).
  E adds a direct `require` on core only for the artisan rules-update/compile commands.
- **Core-trim is a coordinated companion change that MUST ship in the same release as E v1** (design key
  decision #2): core and E cannot both declare `Funnypot\Laravel\*` at once. Phase 7 is that companion
  change in the `funnypot-core` repo.
- **`funnypot:update` needs a core-path helper** (design §4.4 / key decision #11): the command must
  locate core's `bin/funnypot`. Phase 6 adds a tiny `Funnypot\Support\CorePaths` helper **in core** and
  repoints the command at it.
- **Piece F (`mainnet-client`) is transitive, via policy/core.** E consumes F's `ReputationGate`/
  `Client`/`Cache`/`Psr16Cache` inside the `MainnetReputation` port adapter (mirror-first then
  cache-first — M5/O1) and F's relocated `Funnypot\Mainnet\Reporter` inside the queued reporter. F owns
  the decision-**N** shared fail-open breaker (mechanism) + the code-aware 429 branching; E consumes it
  on the report drain (Phase 5), the warmer (Phase 5B), and `funnypot:mirror-sync` (Phase 5C). E holds
  a **`sensor`-tier** `MAINNET_KEY` (O2). Phases 5 / 5B / 5C wire these; F ships its own 7.3 CI.

---

## Phase 1 — Package skeleton + testbench harness boots green

**Change.** Create the minimum installable package so `php vendor/bin/phpunit` runs under testbench:
- `composer.json`: `name` `metrictower/funnypot-laravel`, `type` `library`, PSR-4 **`Funnypot\` →
  `src/`** (autoload — mirroring core, **M14**) so `Funnypot\Laravel\FunnypotServiceProvider` resolves
  to `src/Laravel/FunnypotServiceProvider.php`; tests under a **separate `autoload-dev` root**
  `Funnypot\Laravel\Tests\` → `tests/`. **Do NOT use the old `Funnypot\Laravel\ → src/` mapping** — it
  would resolve the classes at `src/*` instead of `src/Laravel/*` and break the testbench boot; correct
  in Phase 1 **before** any Phase-2+ file moves. `require`: `php >=8.0`,
  **`metrictower/funnypot-policy`** (dev-main from the metrictower vcs repo — the primary dependency;
  it pulls core + mainnet-client transitively), `metrictower/funnypot-core` (dev-main — direct, for the
  Phase-6 artisan commands), `illuminate/support`, `illuminate/http`, `illuminate/console` — **real
  requires now, not `suggest`**. `require-dev`: `orchestra/testbench` (a version whose range spans the
  Laravel matrix), `phpunit/phpunit ^9.5`. Add the `repositories` vcs entries for `funnypot-policy` +
  `funnypot-core`, and `extra.laravel.providers` → `Funnypot\Laravel\FunnypotServiceProvider`.
- `phpunit.xml.dist`: one `funnypot-laravel` testsuite pointing at `tests/`.
- `tests/TestCase.php`: extends `Orchestra\Testbench\TestCase`; `getPackageProviders()` returns
  `[FunnypotServiceProvider::class]`.
- A stub `src/Laravel/FunnypotServiceProvider.php` that only `extends ServiceProvider` with empty
  `register()/boot()` (real body arrives Phase 3) — just enough for testbench to discover a provider.

**Test first.** `tests/HarnessBootTest.php`: boot testbench with the stub provider; assert the container
resolves (`$this->assertInstanceOf(Application::class, $this->app)`) and the provider is in
`$this->app->getLoadedProviders()`. **Also assert the PSR-4 root is correct (M14):**
`class_exists(\Funnypot\Laravel\FunnypotServiceProvider::class)` autoloads from `src/Laravel/`.

**Verify.** `composer install && php vendor/bin/phpunit --filter HarnessBootTest`

**Done when.** Suite green; testbench boots with the package auto-discovered; the `Funnypot\ → src/`
autoload resolves `src/Laravel/FunnypotServiceProvider.php`; `composer validate` passes.

---

## Phase 2 — Request normalization + response mapping (pure, no container)

**Change.** Move `LaravelRequestMapper` and `LaravelResponseMapper` into `src/Laravel/` (same FQCNs),
re-pointed at the policy engine's data shapes:
- **`LaravelRequestMapper::map(Illuminate\Http\Request): RequestEvidence`** — build the neutral
  `RequestEvidence` (method, `getPathInfo`, query, folded headers, body-shape, host, scheme). Three
  additions the policy engine needs:
  - **Source IP (D7):** resolve `$request->ip()` (honoring `TrustProxies`; XFF only behind a configured
    proxy) and thread it onto the evidence as the actor IP — the reputation port, the pin, and the
    reporter all read this one server-observed `REMOTE_ADDR`. No `ip_header` knob.
  - **`SiteProfile`** — a `Funnypot\Laravel\LaravelSiteProfile` whose `routeExists($path)` probes the
    **Laravel router** (attempt a route match against the compiled routes without dispatching; a
    `NotFoundHttpException` ⇒ false) and whose `isSacrificialPath($path)` tests the configured
    sacrificial set. `stack()` returns `laravel` (+ operator-declared extras).
  - **Deterministic seed** — `sha1($sourceIp . (config('funnypot.seed_salt') ?: config('app.key')))`,
    threaded onto the evidence.
- **`LaravelResponseMapper::map(FakeResponse): Illuminate\Http\Response`** — copy the `FakeResponse`'s
  `status`/`contentType`/`headers`/`body` **verbatim** (invariant 5), preserving the CRLF/NUL
  header-injection guard `preg_match('/[\r\n\x00]/', …)`. Add a small `blockResponse(int $status):
  Response` helper for a `block` outcome (empty body, app-chosen status).

**Test first.** `tests/Unit/MapperTest.php`:
- `LaravelRequestMapper::map()` on an `Illuminate\Http\Request` yields a `RequestEvidence` with the
  right method/path/query/folded-headers/body-shape/host/scheme.
- **Source-IP resolution (D7):** a request with a set `REMOTE_ADDR` and an **untrusted** `X-Forwarded-For`
  threads the `REMOTE_ADDR` (not the XFF); with `TrustProxies` trusting the peer, the XFF is honored.
- **`SiteProfile` oracle:** register a real route `/dashboard`; assert `routeExists('/dashboard')` true
  and `routeExists('/wp-login.php')` false; `isSacrificialPath('/wp-login.php')` true on a Laravel stack.
- **Seed determinism:** the same IP + salt yields the same seed; a different IP differs.
- `LaravelResponseMapper::map()` on a `FakeResponse` with a Content-Type distinct from Laravel's default
  yields an `Illuminate\Http\Response` whose status/body/Content-Type are copied verbatim.
- **Header-injection guard:** a `FakeResponse` header name/value containing `\r`/`\n`/`\x00` is dropped.

**Verify.** `php vendor/bin/phpunit --filter MapperTest`

**Done when.** Mapper tests green; the `RequestEvidence` fields, the D7 source IP (untrusted XFF
ignored), the router-backed `routeExists` oracle, the deterministic seed, and the verbatim
status/Content-Type + CRLF/NUL guard are asserted.

---

## Phase 3 — ServiceProvider: publishable config + PolicyEngine wiring + port adapters

**Change.**
- Move `config/funnypot.php` from core into `honeypot-laravel/config/funnypot.php` and extend it to the
  design §5 superset that produces the **policy config array**: the retained STYLE keys (`response_style`,
  `persona_seed`, `persona_breadth`, `severity_ceiling`, `max_body_bytes`, `latency_ms`, `seed_salt`,
  `rules.*`), plus `posture`, `position.{before,fallback}`, `actions`, `learn`, `pin`, and
  `state.cache_store`. Defaults stay INERT/FP-free per M8: `posture=honeypot`, `position.fallback=true`,
  `position.before=false`. Leave the `mainnet` + `check` + `reporting` + `suppression` + `allowlist`
  blocks for Phase 5 / 5B (add them there — keeps this phase a pure move + wiring).
- Implement the **Laravel port adapters** (design §4.7): `Funnypot\Laravel\Support\LaravelStateStore
  implements Funnypot\Policy\Port\StateStoreInterface` (over `Cache::store(config('funnypot.state.cache_store'))`),
  `Funnypot\Laravel\Support\LaravelClock implements Funnypot\Policy\Port\Clock`, and
  `Funnypot\Laravel\Support\LaravelLogger implements Funnypot\Policy\Port\Logger` (over
  `Log::channel('funnypot')`). (`MainnetReputation` arrives in Phase 5B and `LaravelGeoIp` in Phase 5D;
  until then bind a no-op/`unknown` reputation adapter and a no-op/`null` GeoIp adapter so the engine
  wires.)
- Replace the Phase-1 stub `FunnypotServiceProvider` with the real body:
  `register()` = `mergeConfigFrom` + optional `RulesLocator::useDataDir` + **build the policy config
  array** from `config('funnypot.*')` and bind `singleton(PolicyEngine::class, fn($app) =>
  PolicyEngine::fromArray($policyConfig, [ 'evaluator' => <core-backed EvaluatorInterface>, 'reputation'
  => <no-op for now>, 'stateStore' => new LaravelStateStore(...), 'clock' => new LaravelClock(),
  'logger' => new LaravelLogger(...) ]))` + `alias(PolicyEngine::class, 'funnypot.policy')`;
  `boot()` (console-only) = `publishes(... 'funnypot-config')` (register commands + middleware alias in
  Phase 4/6). **Repoint the `__DIR__ . '/../../config/funnypot.php'` paths** to the new package layout
  (confirm the `dirname` depth from `src/Laravel/` → package root/config in the test).
- The core-backed evaluator: inject the policy's default `EvaluatorInterface` wrapping core's two-phase
  engine (E constructs core's engine and hands it to the policy's evaluator; E invents no policy). If the
  policy ships its own core-backed evaluator factory, use it; otherwise E constructs a thin
  `CoreEvaluator` wrapper — confirm which at review (open item, Risk 2).

**Test first.** `tests/ProviderTest.php`:
- `app(PolicyEngine::class)` resolves and is aliased to `funnypot.policy` (same instance).
- `provides()` returns `[PolicyEngine::class]`.
- `vendor:publish --tag=funnypot-config` writes `config/funnypot.php` (assert file created, contains the
  `posture`/`position`/`actions`/`rules` keys).
- **Default config is inert/FP-free (M8):** `posture==='honeypot'`, `position.fallback===true`,
  `position.before===false`.
- `seed_salt` empty falls back to `app.key`.
- **Port adapters resolve:** the bound `PolicyEngine` was constructed with a `LaravelStateStore`,
  `LaravelClock`, and `LaravelLogger` (assert at the binding level, or via a round-trip through the
  store adapter — a `setPin`/`getPin` cycle against the array cache).

**Verify.** `php vendor/bin/phpunit --filter ProviderTest`

**Done when.** Provider/DI/publish tests green; the `PolicyEngine` singleton resolves, wired with E's
Laravel port adapters and inert-by-default config.

---

## Phase 4 — Decision execution: BEFORE middleware + FALLBACK responder + fault degradation

**Change.**
- Move `HoneypotMiddleware` into `src/Laravel/` and re-point its body to the **BEFORE-position
  `Decision` executor** (design §4.2): `handle($request, $next)` = `normalize (Phase 2) → evaluate →
  attach `funnypot.decision` attribute → execute`:
  - `allow`/`log` ⇒ `$next($request)` (a `log` additionally records via `LaravelLogger`);
  - `block` ⇒ `LaravelResponseMapper::blockResponse($decision->status() ?? 403)` short-circuit;
  - `deceive` ⇒ `LaravelResponseMapper::map($decision->fakeHandle())` short-circuit;
  - `report` non-null ⇒ `dispatch(new SendMainnetReport(...))` (job lands in Phase 5; until then a
    bound no-op or a `Bus::fake()` assertion covers it).
  - **Fault degradation (design §6, the one deliberate deviation):** wrap the whole body so any thrown
    exception degrades to `$next($request)` — never a 500. Log at `warning` to the `funnypot` channel
    before degrading so faults stay visible (Risk 3).
- Add `Funnypot\Laravel\FallbackResponder` (design §4.2b) — the **FALLBACK-position** executor:
  `handle($request): Response` = same normalize → evaluate (position=FALLBACK) → execute; on `allow`
  return the app's own 404 (never a 500). Register it in the provider `boot()` as a `Route::fallback()`
  action when `config('funnypot.position.fallback')` is on (and document the exception-`Handler` hook as
  the alternative).
- Register the `funnypot` middleware alias in the provider `boot()`.

**Test first.** `tests/MiddlewareTest.php` + `tests/FallbackResponderTest.php`, binding a **fake
`PolicyEngine`** that scripts a `Decision` per case:
- **allow/log:** `Decision::allow()`/`log()` ⇒ the wrapped route returns its own response untouched, the
  `funnypot.decision` attribute is populated, a `log` records to the `funnypot` channel.
- **block:** `Decision::block(status: 403)` ⇒ short-circuit with status 403, `$next` never called.
- **deceive:** `Decision::deceive(fakeHandle: FakeResponse{status, body, distinct Content-Type})` ⇒
  short-circuit; the Illuminate `Response` carries that **exact** status/body/Content-Type.
- **report:** a `Decision` with a non-null `report()` ⇒ exactly one `SendMainnetReport` dispatched
  (`Queue::fake()` / `Bus::fake()`).
- **Fault degradation:** a `PolicyEngine` whose `evaluate()` **throws** ⇒ the middleware returns the
  pass-through `$next` response (status 200-class), **never 500**; the fallback responder returns the
  app 404.
- **FALLBACK position:** the `FallbackResponder` on a `deceive` `Decision` emits the fake; on `allow`
  returns the app's own 404.

**Verify.** `php vendor/bin/phpunit --filter MiddlewareTest && php vendor/bin/phpunit --filter FallbackResponderTest`

**Done when.** All four `Decision` actions execute correctly at the BEFORE position, the FALLBACK
responder executes at the 404 position, and the fault-degradation path is proven not to emit a 500.

---

## Phase 5 — Reporting: mainnet block, `SendMainnetReport` delivery, `SensorId`

Reporting under M is **delivery-only**: the policy emits an already-suppressed `ReportIntent` on the
`Decision` (the 4-layer suppression + quorum + self_ips/SAFE_PATHS/OAST backstops live in the policy,
backed by E's `LaravelStateStore` from Phase 3); E just delivers it.

**Change.**
- Add the `mainnet` + `reporting` + `suppression` + `allowlist` blocks to `config/funnypot.php` exactly
  as design §5:
  - `mainnet.base_url` = `env('MAINNET_BASE_URL', 'https://api.mainnet.example')` — **host only**;
    **default is the mainnet placeholder host, NEVER `https://api.abuseipdb.com`** (D1/D2).
  - `mainnet.key` = `env('MAINNET_KEY', '')` — empty ⇒ reporter inert (**D2**). Documented as a
    **`sensor`-tier** key (O2: report rights + an escalation-check quota, metered per-install).
  - `reporting`: `enabled` default **`true`** (mainnet by default; still inert without `mainnet.key`),
    `self_ips` from `FUNNYPOT_SELF_IPS`, `queue`, `categories` fallback `[21]`. **No `mainnet_host`,
    `endpoint`, or `ip_header` keys** (removed — D1/D7).
  - `suppression` + `allowlist` blocks (the iCabbiTools prior-art numbers) that feed the policy config
    array's `suppression`/`allowlist` — E surfaces the knobs; the policy applies them via `LaravelStateStore`.
  - `breaker` block (decision **N** canonical numbers: `threshold_transport` 5, `cooldown_secs` 60,
    `quota_park_cap_secs` 21600, `drain_budget_secs` 10, `drain_max_fails` 3, `drain_limit` 200) — F owns
    the mechanism; these are E's knobs, consumed by the report drain here and the warmer/mirror in 5B/5C.
  - **RS-10 note on `state.cache_store`:** the local-state backend is operator-selectable
    (redis/database/memcached/file/array); the chosen default MUST work where the package dir is
    read-only / multi-node — E writes no local state into its own package dir. (The `state` block itself
    is added in Phase 3; Phase 5 documents the requirement in the published config comments.)
- `src/Laravel/SensorId` (**D3**): read/create a stable install UUID via the app's persistent cache
  store (key `funnypot:sensor_id`) on first use; return it for every report. Never a hardware id.
- `src/Laravel/Jobs/SendMainnetReport` (queued `ShouldQueue`): given a `Decision`'s `ReportIntent` (+ the
  D7 source IP + `SensorId`), POST `${mainnet.base_url}/v1/report` via F's relocated
  `Funnypot\Mainnet\Reporter` (transitive); body `ip, categories, comment, timestamp, sensor_id`; header
  `Key: <mainnet.key>`. **Branch on the machine-readable error `code`, not bare status (N2):** 2xx ok;
  `429 code=quota_exhausted` + transport faults (timeout/5xx/401/403/malformed) trip/respect F's shared
  decision-N breaker and **park** the row until the reset (never a tight re-probe); `429
  code=duplicate_report` **drops** the row (never loops); other 4xx drop. Inert (early return) unless
  `mainnet.key` is set. **Fingerprint-safety:** the `comment` is a fixed generic string; categories
  derive from the verdict's own tag vocabulary via a coarse map (fallback `[21]`), never raw
  matcher/CRS/ModSecurity signature strings.
- **SF-5 sync-driver guard + N drain.** At dispatch, resolve the report queue's connection; if it is
  `sync`, write the intent to a local durable queue (a `LaravelStateStore` list / DB table) instead of
  dispatching (Laravel would run `sync` jobs inline, putting the outage timeout on the request path) —
  **never inline, never `dispatchAfterResponse()`** — and log a one-time `warning` to the `funnypot`
  channel. Add a scheduled `funnypot:report-drain` command that drains that local queue with decision
  **N**'s **drain-side budget (N6):** a per-tick wall-clock budget (`breaker.drain_budget_secs`, 10 s) +
  abort after `breaker.drain_max_fails` (3) consecutive transport failures (writing the shared breaker
  marker) + `breaker.drain_limit` (200) rows/tick + attempts/age caps + a hard queue-size cap (oldest
  dropped first). On a non-`sync` driver the drain is a no-op / the job path is used directly.
- Wire the middleware/fallback `report` branch (Phase 4) to dispatch this job (or enqueue-local on `sync`).

**Test first.** `tests/ReportingTest.php` with `Http::fake()` + `Queue::fake()` + array cache:
- A `Decision` carrying a `ReportIntent` → **exactly one** `SendMainnetReport` dispatched; running the
  job POSTs to `${mainnet.base_url}/v1/report` with the `Key` header and an
  `ip,categories,comment,timestamp,sensor_id` body (`ip` = the TrustProxies-resolved `REMOTE_ADDR`,
  `sensor_id` = the persisted install UUID).
- **Default base URL is the mainnet placeholder, not AbuseIPDB (D2):** assert the resolved default is
  not `api.abuseipdb.com`.
- **`sensor_id` stability (D3):** two reports carry the **same** `sensor_id`; it survives a fresh
  `SensorId` read (persisted in cache).
- **HTTP branch (code-aware — N2):** `Http::fake` a 500 → job parks/retries; a plain 4xx → drops; a
  `429 code=quota_exhausted` → parks until the reset (breaker trips); a `429 code=duplicate_report` →
  drops without looping and without tripping the breaker.
- **SF-5 sync-driver guard:** with `QUEUE_CONNECTION=sync`, a `Decision` carrying a `ReportIntent`
  makes **zero transport calls in-request** (assert `Http` recorded nothing during the request) — the
  intent lands on the local scheduler-drained queue and a one-time `warning` is logged; a subsequent
  `funnypot:report-drain` tick delivers it.
- **N drain budget (N6):** under a total outage (`Http::fake` all failing), a `funnypot:report-drain`
  tick completes within `breaker.drain_budget_secs`, aborts after `breaker.drain_max_fails` consecutive
  transport failures (writing the shared breaker marker), and re-queued rows honor the attempts/age +
  hard-size caps.
- **Inert:** empty `mainnet.key` → the job POSTs nothing (early return).
- **Fingerprint-safety:** the POSTed `comment` matches a generic allowlist and contains none of a small
  denylist of signature-shaped tokens.
- **Suppression is the policy's, not E's:** assert E dispatches iff the `Decision.report()` is non-null
  — E applies no dedup/cap of its own (that is proven in the policy suite).

**Verify.** `php vendor/bin/phpunit --filter ReportingTest`

**Done when.** Delivery green: base URL default is the mainnet placeholder (not AbuseIPDB), the report
carries the persisted `sensor_id` + the TrustProxies-resolved source IP, the job is inert without
`mainnet.key`, fingerprint-safe, and E delivers exactly what the `Decision` decided to report. On the
`sync` driver delivery is deferred to the scheduler-drained queue (zero in-request transport — SF-5),
and the drain honors decision N's budget/caps (N6).

---

## Phase 5B — Reputation port: `MainnetReputation` mirror-first/cache-first + the out-of-band warmer (decisions F + M5 + O1 + N)

The **inbound** reputation feature, reconciled with M5: E supplies the policy's `ReputationInterface`;
the policy decides `block` (opt-in, verdict-keyed, BEFORE position — its §4 rules). E writes **no**
reputation/block logic. The request-path lookup is **mirror-first, then cache-first** (never a synchronous network call);
a fresh `Client::check` runs only out-of-band.

**Change.**
- Add the `check` block to `config/funnypot.php` (design §5): `enabled` default **`false`** (opt-in —
  spends credits + sends the visitor IP to a third party), **`block_verdicts`** (default
  `['malicious','critical']`) + optional **`min_block_score`** (default `null`) — **verdict-first per
  decision H/F, NOT a score cutoff**, mapping 1:1 onto F's `Config::fromArray` keys; `cache_ttl_hours`
  (12), `fail_mode` (`open` default | `closed`), `timeout_ms` (1500 — the **out-of-band warmer**
  timeout, not an in-path call). Fold these into the policy config array's `reputation` block
  (`enabled`, `block_verdicts`, `min_block_score`, `as_primary=false`). **No `block_threshold` key**
  (retired). No challenge band in v1.
- `Funnypot\Laravel\Reputation\MainnetReputation implements Funnypot\Policy\Port\ReputationInterface`
  (design §4.7): `lookup($ip)` resolves in three cheap, socket-free steps — (1) the **O1 local mirror**
  in `LaravelStateStore` (`source=mirror`), the primary fresh-read; (2) on a mirror miss, F's **cached**
  `CheckResult` mapped to a `ReputationVerdict` (`source=cache`); (3) on both misses, a fail-open
  `unknown` (`source=fail-open`). It **never** calls `Client::check` synchronously on the request path
  (M5). Inert (always `unknown`) when `check.enabled=false` or `mainnet.key` empty. It wraps F's
  `Client`/`Cache` (Laravel cache via
  `new Funnypot\Mainnet\Cache\Psr16Cache(Cache::store(config('funnypot.state.cache_store')))`) + the
  decision-N circuit breaker. **Containment matching (P2/Q2):** a mirror/verdict row's key may be an
  exact IP, a **CIDR** (IPv4 /24, IPv6 /64 or coarser) or an **ASN** (Q1); `lookup` matches the visitor
  by **containment / ASN-lookup, never exact-match**, delegating to **funnypot-policy's** matcher — E
  normalises an IPv6 to its /64 `score_key` before the lookup (and before reporting, Phase 5) and
  re-implements no CIDR/ASN math.
- **Out-of-band warmer (escalation-only — O1):** `src/Laravel/Jobs/WarmReputation` (queued) and/or a
  `funnypot:reputation-warm` scheduled command that calls F's `Client::check($ip)` (short timeout,
  **breakered per decision N — skipped while OPEN**, fail-open) and populates F's cache. The middleware
  may dispatch `WarmReputation($ip)` **after** the response (a terminating dispatch) when the operator
  opted in and the IP was uncertain (mirror + cache miss), so the *next* request from that actor has a
  cached verdict. **SF-5 driver guard:** on `QUEUE_CONNECTION=sync` the warmer defers to the scheduled
  `funnypot:reputation-warm` command rather than running the `check` inline. The request path itself
  never blocks on it.
- Replace the Phase-3 no-op reputation binding with `MainnetReputation` in the provider `register()`.

**Test first.** `tests/ReputationPortTest.php` with F's fake transport + array cache:
- **Mirror-first hit (O1):** an IP present in the local mirror ⇒ `lookup($ip)` returns
  `source=mirror` with **zero** outbound calls (and without consulting F's cache).
- **Cache-first hit:** on a mirror miss, a primed F cache verdict ⇒ `lookup($ip)` returns the mapped
  `ReputationVerdict` (`source=cache`) with **zero** outbound calls.
- **Both miss ⇒ no in-path call:** empty mirror + empty cache ⇒ `lookup($ip)` returns `unknown`
  (`source=fail-open`) and makes **no** network call (assert the fake transport recorded zero requests).
- **Inert:** `check.enabled=false` (key set), and separately empty `mainnet.key` ⇒ `lookup` returns
  `unknown` with no call.
- **Warmer (escalation-only + breaker):** the `WarmReputation` job / `funnypot:reputation-warm` command
  **does** call `Client::check` on a mirror+cache miss and writes the verdict into F's cache; a
  subsequent `lookup` returns it. While the decision-N breaker is OPEN the warmer fast-skips (no call);
  on `QUEUE_CONNECTION=sync` the inline warm is deferred to the scheduled command (no in-request call).
- **Config read-back (verdict-first):** the provider builds the policy `reputation` block with
  `block_verdicts` default `['malicious','critical']`, `min_block_score` null, `as_primary=false`;
  assert the retired `block_threshold` key is **absent**.
- **Policy integration (block is the policy's):** with the real `PolicyEngine` (fake evaluator + this
  `MainnetReputation` primed to `malicious`, `posture=WAF`, `position.before=true`, reputation-block
  opted in), a request to a real route ⇒ `Decision::block`; a request to a real route with reputation
  **disabled** ⇒ not blocked (proving E blocks only when the *policy* returns `block`, never on E logic).
  *(This exercises the policy's §4; it lives in E's suite as an integration smoke, not a re-test of the
  policy matrix.)*

**Verify.** `php vendor/bin/phpunit --filter ReputationPortTest`

**Done when.** `MainnetReputation` is mirror-first then cache-first (a mirror or cache hit serves with
zero calls, a both-miss makes no in-path call), inert when off/keyless, the out-of-band warmer is the
only path that calls `Client::check` (escalation-only, breaker-skipped when OPEN, SF-5-deferred on the
`sync` driver), the policy `reputation` block is verdict-first (`block_threshold` absent), and a
`Decision::block` is produced by the **policy** (not E) only when reputation-block is opted in. Phase 4's
execution behaviours remain green (re-run to confirm no regression).

---

## Phase 5C — Local-mirror-lite: `funnypot:mirror-sync` + the StateStore mirror (decision O1)

The **primary fresh-read** for reputation (O1). A scheduled command pulls the thin blacklist artifact
from mainnet into `LaravelStateStore`; the Phase-5B `lookup` reads it first, so per-IP `check` is
escalation-only and fleet reads scale as CDN egress, not origin QPS.

**Change.**
- Add the `mirror` block to `config/funnypot.php` (design §5): `enabled` (default `true`, gated behind
  `check.enabled` + `mainnet.key`), `variant` (`thin` — `{ip, verdict, expires_at}`; `full` reserved,
  O4), `sync_minutes` (60 — ~24 pulls/day).
- `Funnypot\Laravel\Reputation\MirrorSync` + the `funnypot:mirror-sync` scheduled command: a conditional
  `GET ${mainnet.base_url}/v1/blacklist?format=json&variant=thin` (`Key:` header; the stored `ETag` sent
  as `If-None-Match` → a 304 spends no bandwidth and leaves the mirror intact) whose thin rows (the `ip`
  field may carry a **CIDR or ASN `score_key`** — P2/Q2/Q1) are
  written into `LaravelStateStore` as the authoritative blocklist mirror, honoring each row's
  `expires_at`. Breaker-guarded (skipped while OPEN — N) and queue-driver-safe (SF-5: scheduled, not
  inline). Inert when `check.enabled=false` or `mainnet.key` empty.
- The Phase-5B `MainnetReputation.lookup` already reads this mirror first; this phase provides the
  populate path.

**Test first.** `tests/MirrorSyncTest.php` with F's fake transport + array cache:
- **First sync populates:** a faked `/v1/blacklist?variant=thin` body ⇒ the rows land in the store; a
  later `MainnetReputation.lookup` for a listed IP returns `source=mirror` with no outbound call.
- **Conditional GET:** the second sync sends the stored `ETag` as `If-None-Match`; a 304 leaves the
  mirror unchanged (assert no rewrite / the prior verdict survives).
- **Expiry honored:** a row past its `expires_at` is not served by `lookup` (falls through to cache/unknown).
- **Range rows (P2/Q2):** a synced **CIDR** row (a /24 or an IPv6 /64) is served by `lookup` for a
  *contained* visitor IP (via the policy matcher), not only an exact IP; an IPv6 visitor is normalised to
  its /64 `score_key` before the lookup.
- **Inert + breaker:** `check.enabled=false` or empty `mainnet.key` ⇒ no fetch; while the breaker is
  OPEN the sync fast-skips.

**Verify.** `php vendor/bin/phpunit --filter MirrorSyncTest`

**Done when.** The mirror is populated by `funnypot:mirror-sync` (conditional GET, 304-cheap, expiry-
honored), the reputation `lookup` serves listed IPs from it with zero calls, and the sync is inert
when off/keyless and breaker-skipped when OPEN.

---

## Phase 5D — Country policy: `LaravelGeoIp` port + local-DB refresh (decision R)

An optional **cheap-static country gate** in the policy ladder (R1 — after allowlist/pin, before
reputation/content, M5). E supplies the country resolved from a **LOCAL** GeoIP DB; the deny/allow
decision + the block/deceive/modifier action live in the **policy** (E writes no country policy). Off by
default and blunt (VPN/CGNAT/roaming/cloud egress — R4).

**Change.**
- Add the `country` + `geoip` blocks to `config/funnypot.php` (design §5):
  - `country`: `enabled` default **`false`** (opt-in — R4), `posture` (`denylist` default | `allowlist` —
    stricter/higher-FP, R1/R4), `action` (**`modifier`** default | `deceive` | `block` — R3), `modifier`
    (suspicion added when `action=modifier`, default 25 — feeds the policy score, never deceives alone,
    R3/M6), `list` (ISO-3166 alpha-2 codes: the deny-list members OR the allow-list). Folded into the
    policy config array's `country` block.
  - `geoip`: `enabled` default **`false`** (required by `country.enabled`), `database` (path to the local
    DB-IP Lite / GeoLite2 `mmdb`; null = the shared/packaged default), `refresh.{enabled,days}` (monthly —
    the dataset's own rhythm).
- `Funnypot\Laravel\Geo\LaravelGeoIp implements Funnypot\Policy\Port\GeoIpInterface` (design §4.7):
  `country($ip): ?string` resolves the ISO country from the local `geoip.database` (a DB-IP Lite `mmdb`
  reader), **socket-free — never a network call on the request path** (M5/R2), IPv6-capable (R2). A miss /
  unreadable DB returns `null` so the policy country gate **skips** — fail-open, never a block or 500.
  Inert (always `null`) when `geoip.enabled=false`. Bind it in the provider `register()` as the policy's
  `GeoIpInterface` port (a no-op `null` adapter until this phase, mirroring the Phase-3 reputation stub).
- `Funnypot\Laravel\Geo\GeoIpRefresh` + the scheduled `funnypot:geoip-refresh` command (R2): refresh the
  install's local DB-IP Lite dataset into `geoip.database`. It **rides the feed/freshness distribution
  seam** — the same scheduled, queue-driver-safe (SF-5) shape as `funnypot:mirror-sync`. Inert when
  `geoip.enabled=false`; a failed refresh leaves the prior DB in place (the gate keeps working on the
  last-good dataset, or skips if none).

**Test first.** `tests/CountryGateTest.php` with a fixture local GeoIP DB + array cache:
- **Resolve (R2):** `LaravelGeoIp::country($ip)` returns the expected ISO code for a known IPv4 **and**
  IPv6 fixture; `null` for an IP absent from the DB.
- **Fail-open / inert:** a missing/unreadable `geoip.database`, and separately `geoip.enabled=false`, ⇒
  `country()` returns `null` with **no throw** and **no network call** (the country gate is skipped).
- **Policy integration (the decision is the policy's):** with the real `PolicyEngine`, a `country.enabled`
  deny-list match with `action=modifier` **raises the suspicion score** (never deceives alone — R3/M6);
  `action=block` (opt-in) ⇒ `Decision::block`; with `country.enabled=false` the country never affects the
  decision (proving E gates only when the *policy* acts on the country).
- **Refresh:** a faked dataset source refreshes the local DB file; a failed refresh leaves the prior DB
  intact; the command is inert when `geoip.enabled=false`.

**Verify.** `php vendor/bin/phpunit --filter CountryGateTest`

**Done when.** `LaravelGeoIp` resolves country from a LOCAL DB (IPv6-capable, socket-free, fail-open on a
miss), inert when off; `funnypot:geoip-refresh` refreshes the local DB on the feed/freshness seam; and a
country-driven `Decision` (modifier by default, block only on the explicit opt-in) is produced by the
**policy**, not E. Phase 4/5B behaviours stay green.

---

## Phase 6 — Artisan commands (move + fix core-path resolution)

**Change.**
- Move `RulesUpdateCommand` (`funnypot:rules-update {--rollback}{--to=}{--status}{--data-dir=}`)
  verbatim into `src/Laravel/Console/` (unchanged — feeds core's `synthesize()` corpus, orthogonal to
  the policy engine).
- Move `UpdateTemplatesCommand` (`funnypot:update {templates}{--out=}`) and **fix its core-path
  resolution** (design §4.4 / key decision #11): today `$packageRoot = dirname(__DIR__, 3)` resolves to
  the command's own package root. After extraction `bin/funnypot` lives in **core**, so add
  `Funnypot\Support\CorePaths::binary(): string` + `CorePaths::compiledDefault(): string` **in core**
  and repoint the command at them.
- Enable `commands([UpdateTemplatesCommand::class, RulesUpdateCommand::class])` in the provider `boot()`.

**Test first.** `tests/CommandTest.php` (testbench runs artisan in-process):
- `funnypot:rules-update --status` with **no** `data_dir` → exits `1` with the documented message.
- `funnypot:rules-update --status` pointed at a **temp** `data_dir` → exits `0` and prints valid JSON.
- `funnypot:update` with a bogus templates path → resolves core's `bin/funnypot` (assert the reported
  binary path is inside `vendor/metrictower/funnypot-core`, not E's package root) and exits non-zero
  without fatal.
- Both commands are registered (assert present in `Artisan::all()`).

**Verify.** `php vendor/bin/phpunit --filter CommandTest`
(and, for the core helper: `cd ../funnypot-core && php vendor/bin/phpunit --filter CorePaths`)

**Done when.** Command tests green; `funnypot:update` resolves core's binary via `CorePaths`; core's
`CorePaths` helper is added with its own unit test and core's suite stays green.

---

## Phase 7 — Coordinated core-trim (companion change in `funnypot-core`)

**This phase lands in the `funnypot-core` repo and MUST ship in the same release as E v1**
(double-`Funnypot\Laravel\*`-declaration constraint — design key decision #2). Do it after E's Phases
1–6 are green against a still-intact core, then flip both together.

**Coordinate with piece C (7.3 conversion).** This phase **deletes** core's `src/Laravel/*`, so C must
**exclude those files from its 7.3 conversion scope** — the bridge should be converted or deleted once,
not both. Sequence with C: whichever of E's extraction or C's conversion lands first is reflected in the
other's inventory. Flag to the C builder before either lands.

**Change (in funnypot-core).**
- Delete `src/Laravel/*` (all six files) and `config/funnypot.php`.
- Remove the `extra.laravel.providers` block and the `illuminate/*` entries from core's `composer.json`
  `suggest`.
- Keep `Funnypot\Support\CorePaths` (added Phase 6), `Http\Responder`, and the PSR-15
  `Http\HoneypotMiddleware` in core.
- Replace/retire `tests/Laravel/StructuralTest.php` — delete it, or repoint it to assert the bridge is
  **gone** from core.
- Trim README's "Laravel: send 404s to funnypot" section and `docs/INTEGRATION.md`'s Laravel content to
  point at `metrictower/funnypot-laravel`.

**Test first.**
- In core: the removed-bridge assertion (or that `funnypot-core/src/Laravel` no longer exists) and the
  **fingerprint-safety CI gates stay green**.
- In E: after the trim is pushed, `composer update metrictower/funnypot-core metrictower/funnypot-policy`
  in `honeypot-laravel/` then re-run E's **entire** suite — proving E's own `Funnypot\Laravel\*` is now
  the sole declaration and nothing regressed.

**Verify.**
```
cd ../funnypot-core && php vendor/bin/phpunit          # core suite incl. fingerprint gates, green
cd ../honeypot-laravel && composer update metrictower/funnypot-core metrictower/funnypot-policy && php vendor/bin/phpunit
```

**Done when.** Core carries **zero** `Illuminate\*` references and no `Funnypot\Laravel\*`; core suite +
fingerprint gates green; E's full suite green against the trimmed core; no class-declaration collision
when both are installed together.

---

## Phase 8 — CI matrix, README/INTEGRATION, release polish

**Change.**
- `.github/workflows/ci.yml`: run `php vendor/bin/phpunit` across a testbench version matrix covering
  the supported Laravel range (8 → 11, design §7). Pin `orchestra/testbench` per Laravel major.
- `README.md` + `docs/INTEGRATION.md`: `composer require`, `vendor:publish --tag=funnypot-config`,
  register the `funnypot` BEFORE middleware **or** the FALLBACK `Route::fallback()` / exception-`Handler`
  hook (both entry styles + both positions, design §3), the **posture** (`honeypot|WAF|both`) +
  **position** knobs (M4/M8), the `MAINNET_BASE_URL` (host only) + `MAINNET_KEY` env pair (D1/D2), the
  `reporting.*` knobs incl. the `self_ips` warning, that the report source IP is `$request->ip()`/
  `TrustProxies` (D7 — configure it; no XFF knob), the **reputation** feature (decision F, Phase 5B):
  `check.*` knobs (`check_enabled` off by default, verdict-first `block_verdicts` + optional
  `min_block_score`, `cache_ttl_hours`, `fail_mode`), that it is **mirror-first then cache-first** (a
  fresh check runs only in the out-of-band warmer — M5/O1), that enabling it sends the visitor IP to a
  third party (GDPR — opt-in + key), and that block/reputation-decisions are the **policy's**, not E's.
  Document the **O1 local-mirror-lite** (`funnypot:mirror-sync` + the `mirror.*` knobs — schedule it;
  it is the primary fresh-read, per-IP check is escalation-only), the **sensor-tier** `MAINNET_KEY`
  (O2), the **resilience** knobs (`breaker.*` — decision N; `funnypot:report-drain` / the SF-5
  sync-driver guard; schedule the drain + warm + mirror-sync commands), the **RS-10** selectable
  `state.cache_store` (pick a backend that works read-only/multi-node), and the `suppression`/
  `allowlist`/`learn` blocks as pass-through to the policy. Document the **country policy** (decision R):
  the `country.*` knobs (`enabled` off by default, `posture` deny-list/allow-list, `action`
  block/deceive/**modifier** with modifier the default, ISO-3166 `list`) + the `geoip.*` local-DB knobs,
  that the country is resolved from a **LOCAL** GeoIP DB (DB-IP Lite — no network call, M5/R2) refreshed
  by the scheduled **`funnypot:geoip-refresh`** (schedule it — it rides the feed distribution), that
  country-blocking is blunt (VPN/CGNAT/roaming — eyes-open opt-in, R4), and that the deny/allow decision
  is the **policy's**. Note that blacklist/mirror rows may be **CIDR/ASN ranges** matched by containment
  (P2/Q2). Carry the `data_dir` guidance (dedicated
  non-web user, 0755/0644, outside web root) into the published config comments verbatim.
- Confirm `mergeConfigFrom`/`publishes` paths and `dirname` depths in the final layout.

**Test first.** `tests/ConfigShapeTest.php` asserting the published config exposes every documented key:
the STYLE keys, `posture`/`position`/`actions`/`learn`/`pin`/`state`, the `mainnet` block (`base_url`
default is the mainnet placeholder host, **not** `api.abuseipdb.com`; `key`), the `reporting` +
`suppression` + `allowlist` blocks, the `check` block (`enabled` default **false**,
**`block_verdicts`** default `['malicious','critical']`, `min_block_score` default `null`,
`cache_ttl_hours`, `fail_mode`), the **`mirror` block** (`enabled` true, `variant` `thin`, `sync_minutes`
60 — O1), the **`breaker` block** (decision N canonical numbers: `threshold_transport` 5,
`cooldown_secs` 60, `quota_park_cap_secs` 21600, `drain_budget_secs` 10, `drain_max_fails` 3,
`drain_limit` 200), the **`country` block** (`enabled` false, `posture` `denylist`, `action` `modifier`,
`modifier` 25 — decision R), and the **`geoip` block** (`enabled` false, `database` null, `refresh.days`
30 — R2). Assert the removed `mainnet_host`/`endpoint`/`ip_header` keys **and the retired score
key `block_threshold`** are absent. Plus a CI-lane smoke: green `phpunit` on each Laravel major.

**Verify.** `php vendor/bin/phpunit` green on every matrix lane in CI.

**Done when.** Green across the Laravel 8–11 matrix; README/INTEGRATION document both entry styles, both
positions, the posture/reputation/reporting/self-IP guidance; config-shape test green.

---

## Risks & open decisions

1. **Coordinated release is the single biggest risk (Phase 7).** E and the core-trim share the
   `Funnypot\Laravel\` namespace; installing both un-trimmed causes a fatal double class declaration.
   They MUST release together. Mitigation: build/verify E against intact core through Phase 6, then flip
   both in one release; never publish E to Packagist before the core-trim is merged and tagged.
2. **Who ships the core-backed `EvaluatorInterface` (Phase 3).** Open decision: the policy package ships
   a core-backed default evaluator factory (preferred — E injects core's engine and uses it), **or** E
   ships a thin `CoreEvaluator` wrapper adapting core's `classify()`+`synthesize()` to the port. Confirm
   with the policy builder; either way E owns no policy, only the wiring.
3. **Reputation warmer placement (Phase 5B) — an open item shared with the policy spec.** M5 forbids a
   sync request-path `check`; the fresh lookup must run out-of-band. E proposes a terminating-dispatch
   `WarmReputation` job (+ a `funnypot:reputation-warm` scheduled command). Confirm the trigger (every
   cold-cache actor vs. a sampled/rate-limited subset) so the warmer doesn't itself spend credits
   unboundedly. The policy spec leaves warmer placement per-adapter; this is E's answer.
4. **Fault-degradation is a behaviour addition, not a verbatim move (Phase 4).** The middleware wraps
   `evaluate`/execute in try/catch → `$next`; it must not swallow a genuine app error silently —
   decision: log at `warning` to the `funnypot` channel before degrading. (The policy engine is also
   fail-safe on its side.)
5. **Client-IP extraction trust — RESOLVED in v1 (D7).** The source IP is the server-observed
   `REMOTE_ADDR` via `$request->ip()`/`TrustProxies`, threaded from the mapper onto the evidence; XFF
   only behind an operator-configured trusted proxy. No `ip_header` knob. Residual risk: a host app that
   mis-configures `TrustProxies` re-opens XFF spoofing; the README calls this out and `self_ips` remains
   the safety net.
6. **`SiteProfile.routeExists` cost (Phase 2).** Probing the Laravel router per request must be cheap
   and side-effect-free (match only, never dispatch, no middleware run). Confirm the match path on each
   Laravel major (route caching changes the internals); fall back to a compiled-route path-regex scan if
   a direct match probe is unsafe. Getting a false "route exists" wrong only ever *suppresses* deception
   (fails safe), never deceives a real route.
7. **Octane statelessness (out of v1).** The `PolicyEngine` singleton + port adapters are
   stateless-per-request (all mutable state is behind the cache-backed `StateStoreInterface`), so a
   container-shared singleton is safe under FPM and (documented caveat) Octane. Fast-follow; no code in v1.
8. **`dev-main` policy/core dependencies.** E requires `funnypot-policy` + `funnypot-core` as `dev-main`
   from the metrictower vcs repos. CI must resolve them anonymously (both PUBLIC repos — keep them so,
   per project CLAUDE.md).
9. **Mirror-sync trigger + freshness (Phase 5C, O1).** `funnypot:mirror-sync` must be scheduled by the
   operator; the default `sync_minutes=60` gives a ~1 h staleness horizon against the artifact's own
   ≤24 h erasure SLA. Open item: whether the mirror should stamp the artifact's `meta.as_of_change_seq`
   now so a later `/v1/changes` delta feed can bootstrap consistently (SF-15 reserves it server-side) —
   E only needs to persist the value it receives. Confirm with the A1/F builders.
10. **Sync-driver local queue backing (Phase 5, SF-5 + RS-10).** On `QUEUE_CONNECTION=sync` the report
    intents are held in the `state.cache_store`; on a host whose only cache is `array` (per-process),
    the local queue does not survive the request — so the SF-5 fallback requires a persistent
    `state.cache_store` exactly as the decision-N breaker does (N1). Document this pairing; the README
    recommends a persistent store whenever `check`/reporting is enabled.

- `honeypot-laravel/` is an installable `metrictower/funnypot-laravel` package: `composer install &&
  php vendor/bin/phpunit` green from the repo root.
- The bridge classes live in `src/Laravel/` under their original `Funnypot\Laravel\` FQCNs
  (source-transparent to the existing iCabbiTools drop-in), re-pointed at the policy engine.
- **Request normalization** produces a `RequestEvidence` + a router-backed `SiteProfile` + the D7 source
  IP + the deterministic seed — proven by test.
- **`Decision` execution** at the BEFORE position (middleware) and the FALLBACK position
  (`FallbackResponder`): `allow`/`log` → pass through (+ observe); `block` → honest app-chosen status;
  `deceive` → verbatim `FakeResponse` (status/body/Content-Type); a `report` → one queued delivery; a
  policy/adapter fault degrades to pass-through / plain 404 and **never** a 500 — each proven by test.
- **Port adapters:** `LaravelStateStore` (pins/blocklist/rule-state/suppression-ledger/counters),
  `MainnetReputation` (mirror-first then cache-first, no in-path network call), `LaravelClock`,
  `LaravelLogger` — proven against the array cache + F's fake transport.
- **Reporting delivery:** a `Decision`'s `ReportIntent` is delivered to `${mainnet.base_url}/v1/report`
  (host-only + appended path — D1) with the `Key:` header, the TrustProxies-resolved `REMOTE_ADDR` (D7),
  the persisted `sensor_id` (D3), a fingerprint-safe generic comment, and a coarse category map; inert
  without `mainnet.key` (D2); the *suppression* that decides whether an intent exists is the policy's,
  backed by `LaravelStateStore`. On the `sync` driver delivery defers to the scheduler-drained queue
  (zero in-request transport — SF-5); the drain and the code-aware 429 handling honor decision N (N6/N2).
- **Reputation (decision F, reconciled with M5 + O1):** mirror-first then cache-first on the request path
  (fresh `check` only in the out-of-band, breaker-guarded warmer — escalation-only), off by default,
  inert without enable+key, fail-OPEN; the block-on-reputation decision is the **policy's** verdict-keyed
  opt-in modifier, executed by E as a `Decision::BLOCK`.
- **Local-mirror-lite (O1):** `funnypot:mirror-sync` pulls the thin blacklist artifact (conditional GET,
  304-cheap, expiry-honored) into `LaravelStateStore` as the primary fresh-read; per-IP `check` is
  escalation-only — proven by `MirrorSyncTest`. Mirror/verdict rows may be **CIDR/ASN** ranges matched by
  **containment** via funnypot-policy (P2/Q2); E normalises IPv6 to its /64 `score_key` before
  lookup/report — proven by the range-row test.
- **Country policy (decision R):** a `LaravelGeoIp` `GeoIpInterface` adapter resolves the visitor country
  from a **LOCAL** DB-IP Lite DB (IPv6-capable, socket-free, fail-open on a miss — R2/M5); the `country`
  config (deny/allow posture; action block/deceive/**modifier**, default modifier — R1/R3) drives the
  **policy's** country gate; `funnypot:geoip-refresh` refreshes the DB on the feed/freshness seam. Off by
  default — proven by `CountryGateTest` (Phase 5D).
- **Config produces the policy array:** `posture`/`position`/`actions`/`learn`/`pin`/`reputation`/
  `suppression`/`allowlist`/`self_ips`/`state`/`mirror`/`breaker`/`country`/`geoip` (+ retained
  STYLE/`mainnet`/`rules`);
  the removed `mainnet_host`/`endpoint`/`ip_header`/`block_threshold` keys absent — proven by
  `ConfigShapeTest`. E's `MAINNET_KEY` is a `sensor`-tier key (O2); `state.cache_store` is
  operator-selectable and read-only/multi-node-safe (RS-10).
- Coordinated core-trim merged: core carries zero `Illuminate\*` references, its suite + fingerprint
  gates green, and E's full suite green against the trimmed core.
- Green across the Laravel 8–11 testbench matrix in CI; README/INTEGRATION document both entry styles,
  both positions, and the reputation/reporting/self-IP guidance.

## Key decisions I made (confirm at review)

1. **E is a thin adapter over `funnypot-policy`; phases build the adapter, not a decision engine
   (decision M).** Normalize → `evaluate` → execute. The precedence, two-axis combination,
   learn-then-enforce, pin/TTL, and suppression are tested in the policy suite; E's phases prove the
   Laravel glue (normalization, execution at two positions, the four ports, delivery).
2. **Phase ordering keeps the suite green** (skeleton → normalization/mapping → provider+ports → Decision
   execution → reporting → reputation port → mirror-sync → commands), enabling `commands()` and the
   reputation binding only once their classes exist. Deferring the `mainnet`/`check`/`suppression`/
   `mirror`/`breaker` config to Phases 5/5B/5C keeps Phase 3 a pure move + wiring.
3. **Phase 7 (core-trim) is scheduled after E Phases 1–6 are green against an intact core, then both flip
   in one release** — respecting the double-declaration constraint.
4. **Both positions are shipped (M4/M8):** a BEFORE middleware and a FALLBACK responder, both calling one
   `PolicyEngine`. Posture/position are config, not code. Default: fallback deceives everything (FP-free),
   before-position FP-free-gates + SHADOW.
5. **Reputation is a mirror-first, then cache-first policy port (F reconciled with M5 + O1).** The old
   inline `ReputationGate::decide()` front-gate is replaced on the request path by an O1 local-mirror
   read (populated by `funnypot:mirror-sync`, Phase 5C) then F's cache; a fresh `Client::check` is
   escalation-only and runs only in the breaker-guarded out-of-band warmer. Block-on-reputation is the
   policy's opt-in verdict-keyed modifier, executed by E.
5b. **Resilience is decision N + SF-5.** F owns the single shared fail-open breaker (canonical
   threshold 5 / cooldown 60 s / drain 10 s·3), consumed by the report drain, warmer, and mirror-sync;
   on `QUEUE_CONNECTION=sync` report delivery + the warmer defer to scheduler-drained commands, never
   inline. E holds a `sensor`-tier `MAINNET_KEY` (O2); `state.cache_store` is selectable and must be
   persistent/read-only-safe (RS-10 + N1).
6. **Reporting is delivery-only.** The core `Observer` seam is gone from E's path; E enqueues a
   `Decision`'s already-suppressed `ReportIntent`. Suppression + quorum + self_ips/SAFE_PATHS/OAST live
   in the policy, backed by `LaravelStateStore`.
7. **Client IP is the server-observed `REMOTE_ADDR` via `$request->ip()`/`TrustProxies` (D7),** threaded
   onto the evidence; no `ip_header` knob.
8. **`SiteProfile` real-route oracle is the Laravel router** (match-only, never dispatch); the FP-safety
   input that keeps deception off real routes. `CorePaths` locates `bin/funnypot` for `funnypot:update`
   (Phase 6). Testbench matrix targets Laravel 8–11.
9. **Country policy is a LOCAL-GeoIP cheap-static gate; E supplies the country, the policy decides (R).**
   Phase 5D adds a `country` config (deny-list OR allow-list posture; action block/deceive/**modifier**,
   default modifier — R1/R3) + a `geoip` local-DB config, a `LaravelGeoIp` `GeoIpInterface` adapter over a
   LOCAL DB-IP Lite DB (no network call — R2/M5, fail-open on a miss), and `funnypot:geoip-refresh` that
   refreshes the DB on the feed/freshness seam. Off by default, blunt (R4). Separately, mirror/verdict
   rows may be **CIDR/ASN** ranges matched by **containment** via funnypot-policy (P2/Q2); E normalises
   IPv6 to its /64 before lookup/report and re-implements no CIDR/ASN math.

## Dependencies on other pieces

- **funnypot-policy (piece M): hard, primary.** E `require`s `metrictower/funnypot-policy` and consumes
  `PolicyEngine`, `Decision`, `RequestEvidence`, `SiteProfile`, `FakeResponse` (opaque via `fakeHandle`),
  `ReportIntent`, `PolicyConfig::fromArray()`, and the `Funnypot\Policy\Port\*` interfaces. E implements
  the Laravel adapters for `StateStoreInterface`/`ReputationInterface`/**`GeoIpInterface`** (decision R)/
  `Clock`/`Logger` and injects the core-backed evaluator. E also relies on the policy's **CIDR/ASN
  containment matcher** for range/ASN mirror + verdict rows (P2/Q2) — E supplies the visitor IP, the
  policy owns the matching. Policy is framework-free PHP >= 7.3 with its own CI.
- **funnypot-core (via policy; direct require for the commands).** Provides the two-phase
  `classify()`+`synthesize()` engine (behind the policy's `EvaluatorInterface`) plus, for the artisan
  commands, `Rules\RulesLocator`/`RulesUpdater`, `Http\Responder`, `Support\CorePaths`, and
  `bin/funnypot`. **Coordinated companion change (Phase 7, blocks E v1 release):** remove `src/Laravel/*`,
  `config/funnypot.php`, `extra.laravel.providers`; add `CorePaths`; trim README/INTEGRATION Laravel
  sections; keep PSR-15 `Http\HoneypotMiddleware` + `Http\Responder`. Core stays a PUBLIC repo.
- **Piece F (`mainnet-client`): transitive, via policy/core.** E consumes F's `ReputationGate`/`Client`/
  `Cache`(`Psr16Cache`)/breaker inside `MainnetReputation` (mirror-first then cache-first — Phase 5B) and
  F's relocated `Funnypot\Mainnet\Reporter` inside `SendMainnetReport` (Phase 5). F owns the decision-N
  shared breaker + code-aware 429 branching, consumed by the report drain (Phase 5), warmer (5B), and
  `funnypot:mirror-sync` (5C). No direct `require`. F ships its own 7.3 CI lane.
- **Piece C (core → PHP 7.3): sequencing only.** E tracks Laravel's PHP floor (8.0+). **Phase 7 deletes
  core's `src/Laravel/*`,** so C must **exclude those files from its 7.3 conversion scope**. Coordinate
  ordering with the C builder.
- **Piece A1 (mainnet-api): consumer relationship.** E's reporter, warmer, and `funnypot:mirror-sync`
  are clients of A1's `POST /v1/report` + `GET /v1/check` + `GET /v1/blacklist?variant=thin` (the O1
  mirror) with an operator-issued **`sensor`-tier** `MAINNET_KEY` (O2; no public signup in v1); E
  surfaces `MAINNET_BASE_URL` + `MAINNET_KEY`, A1 issues the key out of band and supplies the
  code-aware Error envelope + `Retry-After` the N breaker reads. A1's blacklist/thin-feed rows may carry
  **CIDR/ASN `score_key`s** (P2/Q2/Q1), which E's mirror stores verbatim and matches by containment.
- **Local GeoIP dataset (DB-IP Lite): a data-distribution dependency (decision R2).** E's country gate
  reads a **local** DB-IP Lite `mmdb`; `funnypot:geoip-refresh` (Phase 5D) refreshes it on the **same
  feed/freshness distribution seam** the O1 mirror rides — the dataset the dashboard + A1 enrichment
  already use. No per-request network call; the refresh is scheduled + queue-driver-safe like
  `funnypot:mirror-sync`.
- **Piece D (honeypot-wordpress): none direct.** Sibling adapter over the same `funnypot-policy` engine;
  shares the thin-adapter pattern but no code dependency.

## Review resolutions applied (2026-08-19)

- **D1** — Phase 5 config uses the canonical `mainnet.base_url` (host only) + `mainnet.key`; the reporter
  appends `/v1/report`. Removed `mainnet_host`/`endpoint`. Updated the job POST target, `ConfigShapeTest`
  (Phase 8), README knobs, and the A1/F dependency notes.
- **D2** — Phase 5 default `mainnet.base_url` is the mainnet placeholder host, never
  `https://api.abuseipdb.com`; the reporter is key-gated (`enabled` defaults on, inert without key).
- **D3** — Added a `Funnypot\Laravel\SensorId` helper (persisted install UUID via cache) in Phase 5;
  `sensor_id` on every report + stable across reports (tested).
- **D7** — Server-observed `REMOTE_ADDR` via `$request->ip()`/`TrustProxies` is the v1 source IP,
  resolved in the mapper (Phase 2, untrusted-XFF-ignored test) and threaded onto the evidence; no
  `ip_header` knob.
- **M14** — Phase 1 `composer.json` PSR-4 root `Funnypot\ → src/` with tests as a separate `autoload-dev`
  root; the old `Funnypot\Laravel\ → src/` mapping breaks the testbench boot and is fixed before any
  file moves.
- **C coordination** — Phase 7 deletes core's `src/Laravel/*`, so C excludes those files from its 7.3
  conversion scope; extraction-vs-conversion ordering called out.
- **F (mainnet-client + reputation check)** — E depended on mainnet-client transitively and added a
  Phase-5B reputation check + block. **Superseded by decision M (below):** reputation-block is now a
  policy action/config and the request-path lookup is cache-first (M5).

### M — position-blind engine + funnypot-policy (2026-08-19)

Re-pointed the plan from "build a Laravel wrapper that calls core's `detect`/`respond` + an inline
reputation gate + a core `Observer`" to "**build a thin Laravel adapter over
`metrictower/funnypot-policy`**" (decision M; program-decisions §M wins). Phase-level changes:

- **Orientation** rewritten around the policy engine + its ports/`Decision`; the old "core seams E
  consumes" list narrowed to the command-only core seams.
- **Phase 1** — `require` flips to `funnypot-policy` (primary) + `funnypot-core` (commands only).
- **Phase 2** — "move the mappers" becomes **request normalization + response mapping**: build
  `RequestEvidence` + a router-backed `SiteProfile` + the deterministic seed (M2) alongside the D7
  source IP; map a `FakeResponse`/block, not a `SynthesizedResponse` respond-result.
- **Phase 3** — the provider now builds the **policy config array** + binds `PolicyEngine::fromArray()`
  wired to the new **Laravel port adapters** (`LaravelStateStore`/`LaravelClock`/`LaravelLogger`), not a
  bare core `Engine` singleton.
- **Phase 4** — "middleware pass-through/serve-fake" becomes **`Decision` execution** (allow/log/block/
  deceive) at the **BEFORE** position + a new **FALLBACK** `FallbackResponder`; the no-500 fault
  degradation is retained.
- **Phase 5** — reporting becomes **delivery-only**: the core `Observer` is gone; E enqueues a
  `Decision`'s already-suppressed `ReportIntent`; the 4-layer suppression + backstops move into the
  policy (backed by `LaravelStateStore`), surfaced as `suppression`/`allowlist`/`self_ips` config.
- **Phase 5B** — the inline `ReputationGate::decide()` front-gate becomes the **cache-first
  `MainnetReputation` port** (M5) + an **out-of-band warmer**; block-on-reputation is the policy's
  verdict-keyed opt-in modifier, executed by E.
- **Retained unchanged:** the M14 PSR-4 fix; D1/D2/D3/D7 mechanics; Phases 6–8 (commands, coordinated
  core-trim, CI/README) — with the trim/CI updated to name `funnypot-policy` and both positions; the
  verdict-first `block_verdicts`/`min_block_score` (no `block_threshold`) config; the coordinated-release
  + fault-degradation risks.

### N / O + future-proofing review (2026-08-19)

Applied decisions **N** + **O** and review items **SF-5 / O1 / O2 / N / RS-10** (canonical:
`funnypot-mainnet/docs/2026-08-19-program-decisions.md` §N/§O + `2026-08-19-futureproofing-review.md`).
Phase-level changes:

- **Phase 5 (SF-5 + N).** `SendMainnetReport` branches on the machine-readable error `code` (N2 —
  `quota_exhausted` parks, `duplicate_report` drops without looping); added the **sync-driver guard**
  (on `QUEUE_CONNECTION=sync`, enqueue-local + a one-time warning, never inline / never
  `dispatchAfterResponse()`) and the **`funnypot:report-drain`** command with decision N's drain-side
  budget/early-abort/queue caps (N6). New `breaker` config block; tests for zero-in-request-transport
  on `sync` + the drain budget.
- **Phase 5B (O1 + N).** `MainnetReputation.lookup` is now **mirror-first, then cache-first**; the warmer
  is escalation-only, breaker-skipped when OPEN (N), and SF-5-deferred on the `sync` driver. New tests
  for the mirror-first hit + breaker-skip.
- **Phase 5C (O1 — NEW).** Added `funnypot:mirror-sync` + `MirrorSync` + the `mirror` config block: a
  conditional `GET /v1/blacklist?variant=thin` (ETag/304) populating the `LaravelStateStore` mirror as
  the primary fresh-read; `MirrorSyncTest`.
- **O2.** `MAINNET_KEY` documented as a `sensor`-tier key (report + escalation-check quota) in Phase 5
  config, the orientation F/A1 notes, and the A1 dependency.
- **RS-10.** The `state.cache_store` note (Phase 3/5): the local-state backend is operator-selectable and
  the default must be read-only/multi-node-safe; new Risk 10 pairs the SF-5 local queue + N1 breaker
  marker to the persistent-store requirement.
- **Phase 8.** `ConfigShapeTest` + README extended for the `mirror` + `breaker` blocks, the O1 mirror
  schedule, the O2 sensor key, and the N/SF-5 resilience knobs. New Risks 9 (mirror trigger/freshness +
  the reserved `as_of_change_seq` bootstrap) and 10 (sync-queue persistence).

### P/Q/R — entity + geo (2026-08-19)

Applied decisions **P** (IPv6 hardening), **Q** (range/CIDR/ASN reputation), **R** (country policy via
LOCAL GeoIP) from the canonical `funnypot-mainnet/docs/2026-08-19-program-decisions.md` §P/§Q/§R.
Phase-level changes:

- **Phase 5D (R — NEW).** Added `Funnypot\Laravel\Geo\LaravelGeoIp` (`GeoIpInterface` port over a LOCAL
  DB-IP Lite DB — socket-free, IPv6-capable, fail-open — R2/M5), the `country` config block (deny/allow
  posture; action block/deceive/**modifier**, default modifier; ISO-3166 list — R1/R3), the `geoip`
  local-DB config, and the scheduled `funnypot:geoip-refresh` (local-GeoIP-DB distribution + refresh,
  riding the feed/freshness seam — R2). `CountryGateTest`: local resolve (v4 + v6), fail-open/inert, and
  a policy-integration smoke (modifier raises the score; block only on the explicit opt-in; the decision
  is the policy's). Off by default and blunt (R4).
- **Phase 5B / 5C (P2/Q2).** `MainnetReputation.lookup` and `MirrorSync` now note that mirror/verdict rows
  may carry a **CIDR or ASN `score_key`** (Q1); the reputation lookup matches the visitor by
  **containment / ASN-lookup, not exact-match**, delegating to funnypot-policy's matcher; E normalises an
  IPv6 to its /64 `score_key` before the lookup and before reporting (P2). New `MirrorSyncTest` range-row
  case. E re-implements no CIDR/ASN math.
- **Phase 8.** `ConfigShapeTest` + README extended for the `country` + `geoip` blocks and the CIDR/ASN
  containment note.
- **Dependencies.** funnypot-policy gains the `GeoIpInterface` port + the containment matcher E relies on;
  A1's feed rows may be range/ASN; a new **local GeoIP dataset (DB-IP Lite)** data-distribution dependency
  rides the mirror's feed/freshness seam. Key decision #9 added.
- **Server-side P/Q items are A1/policy's**, not E's (reporter /64 distinctness, range auto-rollup, range
  allowlist, `scored_as`); E's only v6 obligation is the /64 normalisation-before-lookup/report +
  containment matching above.
