# Piece E · honeypot-laravel — design spec

Status: design/planning only. No product code in this document.
Package: `metrictower/funnypot-laravel`
Author: architect-orchestrated planning (subagent E)
Date: 2026-08-19

**Consumes (primary):** `metrictower/funnypot-policy` — the position-blind **decision engine**
(decision M; [`funnypot-policy/docs/2026-08-19-funnypot-policy-design.md`](../../funnypot-policy/docs/2026-08-19-funnypot-policy-design.md)).
E is a **thin Laravel adapter** over it: request normalization, `Decision` execution, a Laravel
`StateStoreInterface`, hook placement (BEFORE middleware + FALLBACK 404 hook), and publishable config
that produces the policy config array.
**Consumes (transitive, via funnypot-policy):** `metrictower/funnypot-core` (the two-phase
`classify()`+`synthesize()` deception engine — M2, held behind the policy's `EvaluatorInterface`) ·
`metrictower/mainnet-client` (F's `ReputationGate`/`Client::check` behind the policy's
`ReputationInterface`, plus the relocated `Funnypot\Mainnet\Reporter`, née piece B). The
reputation-check/block feature is now a **policy action/config**, not bespoke E logic (decision M).

## 1. What this is

`metrictower/funnypot-laravel` is a first-class Laravel package that drops the funnypot deception
stack into any Laravel app as a **thin adapter over `metrictower/funnypot-policy`** (decision M). The
package does **not** decide whether a request is an attack, whether to deceive, whether to block, or
whether to report — that is the shared policy engine's job, so the exact same decision matrix,
learn-then-enforce machine, pin/TTL, and report-suppression rules run identically in WordPress (piece
D), Laravel (this piece), and the standalone app. E's job is the framework glue only:

1. **Normalize** the incoming `Illuminate\Http\Request` into a neutral `RequestEvidence` + a
   `SiteProfile` (declared stack `laravel` + a **real-route oracle** backed by the Laravel router, so a
   fake `/wp-login.php` never collides with a route that actually exists).
2. **Ask** `Funnypot\Policy\PolicyEngine::evaluate()` for a `Decision`.
3. **Execute** that `Decision`: `allow`/`log` → the app proceeds (optionally observed); `deceive` →
   emit core's byte-exact fake via the `Decision`'s `fakeHandle` and short-circuit; `block` → emit an
   honest app-chosen `403`; and if the `Decision` carries a `report`, enqueue it (suppression already
   applied by the policy).

The engine runs at up to **two positions** (M4): a **BEFORE-position** terminating middleware and a
**FALLBACK-position** 404 hook. Which position(s) are active, and which action each band earns, are
**operator config knobs** (posture `honeypot | WAF | both`, §5/§8) — choosing "honeypot vs WAF" and
"before vs fallback" is a configuration choice, never a code change. A single install may run both.

E also provides the Laravel implementations of the policy's **ports**: a `StateStoreInterface` over
Laravel's cache (pins, learn-then-enforce state, the suppression ledger), a `ReputationInterface`
over F's `mainnet-client` (**cache-first**, never a synchronous network call in the request path — M5),
a `Clock`, and a `Logger` over the `funnypot` log channel. It ships the queued mainnet reporter that
delivers a `Decision`'s `ReportIntent`, and the artisan commands for signed rules-updates and template
recompiles.

Crucially, **this package becomes the new home of the Laravel bridge that today lives inside
funnypot-core** (`funnypot-core/src/Laravel/*`); extracting it lets core return to being genuinely
framework-agnostic (its only Illuminate references disappear) while Laravel users get a real,
testable, auto-discovered package.

## 2. Scope

### v1 (this spec)
- **Package skeleton:** `composer.json` requiring `metrictower/funnypot-policy` (the primary
  dependency; core + mainnet-client arrive transitively via policy — policy `require`s core and consumes
  mainnet-client behind `ReputationInterface`), `illuminate/support` + `illuminate/http` +
  `illuminate/console` (real requires now, not "suggest"), auto-discovery via `extra.laravel.providers`.
  E adds a direct `require` on `metrictower/funnypot-core` **only** for the artisan rules-update/compile
  commands (they touch core's `Rules\RulesUpdater` + `bin/funnypot`). **PSR-4 root `Funnypot\ → src/`**
  (mirroring core — M14), so `Funnypot\Laravel\FunnypotServiceProvider` resolves to
  `src/Laravel/FunnypotServiceProvider.php`; tests map under a **separate `autoload-dev` root**
  (`Funnypot\Laravel\Tests\ → tests/`). The old `Funnypot\Laravel\ → src/` mapping is wrong (it would
  look for the classes at `src/*`, not `src/Laravel/*`, breaking the testbench boot) and is fixed here
  **before** any file moves.
- **`FunnypotServiceProvider`** — merge + publish `config/funnypot.php`, build the policy config array
  from it, construct the `PolicyEngine` singleton wired to E's Laravel port adapters
  (`LaravelStateStore`, `MainnetReputation`, `LaravelClock`, `LaravelLogger`) and the core-backed
  evaluator, register commands, register the `funnypot` middleware alias, and (optionally) the
  FALLBACK route/hook.
- **`HoneypotMiddleware` (`handle($request, $next)`)** — the **BEFORE-position** `Decision` executor:
  normalize → `evaluate` → execute (`allow`/`log` ⇒ `$next`; `block` ⇒ honest status; `deceive` ⇒
  emit the `fakeHandle`; `report` ⇒ enqueue). Off-position / `allow` is byte-identical to `$next`.
- **`FallbackResponder`** — the **FALLBACK-position** `Decision` executor for the 404 path (via a
  `Route::fallback()` registration or the exception `Handler` on a `NotFoundHttpException`). Same
  normalize → evaluate → execute, run in FALLBACK position (M8 default: deceive everything at the
  fallback, FP-free by construction).
- **`LaravelRequestMapper` / `LaravelResponseMapper`** — Illuminate ⇄ policy/core mapping. The request
  mapper builds `RequestEvidence` + the `SiteProfile` (real-route oracle over the Laravel router) +
  threads the TrustProxies-resolved source IP (D7) and derives the deterministic actor seed; the
  response mapper turns a `FakeResponse` (`deceive`) or a block status into an `Illuminate\Http\Response`.
- **Policy port adapters (NEW):** `LaravelStateStore implements Funnypot\Policy\Port\StateStoreInterface`
  (Laravel cache), `MainnetReputation implements Funnypot\Policy\Port\ReputationInterface` (cache-first
  over mainnet-client), `LaravelClock`, `LaravelLogger`.
- **Reporting:** the queued `SendMainnetReport` job delivers a `Decision`'s `ReportIntent` to
  `${MAINNET_BASE_URL}/v1/report` with a `Key:` header + the persisted `sensor_id` (D3) + the
  TrustProxies-resolved source IP (D7). Mainnet is the default destination and is **key-gated**: inert
  whenever `MAINNET_KEY` is unset. The 4-layer suppression + quorum that gate whether a report fires
  live **in the policy** (§9 of the policy spec), backed by E's `LaravelStateStore` — E only delivers
  what the `Decision` already decided to report. Delivery is **queue-driver-safe (SF-5):** on
  `QUEUE_CONNECTION=sync` the job is redirected to a local scheduler-drained queue instead of running
  inline (and `dispatchAfterResponse()` is never used for it), so a mainnet outage never pins the
  FPM worker; the scheduled drain carries decision **N**'s wall-clock budget + shared breaker (N6).
- **Local-mirror-lite — the PRIMARY fresh-read (O1):** a scheduled `funnypot:mirror-sync` command
  pulls the **thin blacklist artifact** (`GET ${MAINNET_BASE_URL}/v1/blacklist?format=json&variant=thin`,
  ETag/`If-None-Match` conditional GET, ~24 pulls/day, quota-cheap) into E's `LaravelStateStore` as
  the authoritative local blocklist mirror. The reputation port consults this mirror **first**; only
  an *uncertain* IP (not on the mirror) escalates to a per-IP `check`. Fleet growth becomes CDN
  egress, not origin QPS. Off unless `check_enabled` + `MAINNET_KEY`.
- **Reputation check + block (decision F, now a policy action):** the `check.*` operator config feeds
  the policy config array's `reputation` block; E's `MainnetReputation` adapter supplies the
  **mirror-first, then cached** verdict to the policy, which decides `block` (opt-in, BEFORE position,
  verdict-keyed) per its §4 rules. E owns no reputation/block logic of its own — it is a
  `Decision::BLOCK` the middleware executes. Off by default, inert without `check_enabled` +
  `MAINNET_KEY`, fail-OPEN; the per-IP `check` is escalation-only (O1) and never runs synchronously
  on the request path (M5), guarded by decision N's shared breaker.
- **Artisan commands** `funnypot:rules-update` and `funnypot:update` (moved verbatim from core; the
  latter repointed to resolve core's `bin/funnypot`).
- `orchestra/testbench` test suite.
- Follow-up task defined here (not done here): trim funnypot-core — remove `src/Laravel/*`,
  `config/funnypot.php`, and the `extra.laravel.providers` block; trim its README/INTEGRATION.md
  Laravel sections to point at this package.

### Non-goals / fast-follow (explicitly out of v1)
- **Not** a replacement for the standalone funnypot app (dashboard, SSH server, TCP emulators).
  This package only embeds the HTTP detect/deceive path via the policy engine.
- **No** learn-then-enforce **admin UI** in v1 (the shadow→tuning→enforce one-click promotions + the
  kill-switch). v1 drives the state machine from config + a small artisan surface; a Livewire/Blade
  promotion console is fast-follow. The policy's state machine itself is fully wired (backed by the
  `LaravelStateStore`).
- **No** challenge/tarpit action (cut program-wide in v1 — M3; a PHP-FPM tarpit is a self-DoS). The
  `Decision` action set is `allow | log | block | deceive`.
- **No** in-request synchronous reputation network call (M5). E's `MainnetReputation` is mirror-first,
  then cache-first (O1); the out-of-band cache warmer + `funnypot:mirror-sync` (queued jobs / scheduled
  commands) are defined here as small v1 pieces (§4.7) but a fresh network `check` / artifact pull
  never runs on the request path.
- **No** octane/swoole-specific singleton-reset handling in v1 beyond documenting that the engine +
  its ports are stateless-per-request (fast-follow if a stale-state bug surfaces under Octane).
- **No** dependency on the funnypot *app* (`metrictower/funnypot`). E depends on `funnypot-policy`
  (and core for the commands). The reporter is F's `Funnypot\Mainnet\Reporter` behind a Laravel queue,
  not a re-export of the app's `Funnypot\App\ThreatIntel\AbuseIpdb`.
- **No** publishing of persona/template corpora from this package — those ship inside core.

## 3. Architecture

```
Laravel app request  ─►  Kernel middleware stack
                              │
                              ▼   BEFORE position (posture: WAF / both / honeypot-observe)
          ┌────────────────────────────────────────────────────┐
          │ Funnypot\Laravel\HoneypotMiddleware                 │
          │   $evidence = LaravelRequestMapper::map($request)   │  (RequestEvidence + SiteProfile +
          │       + SiteProfile(router real-route oracle, D7 IP)│   TrustProxies source IP + seed)
          │   $decision = $policy->evaluate($evidence)          │  Funnypot\Policy\PolicyEngine
          │   execute($decision):                               │
          │     allow|log  → $next($request) (+ observe/log)    │
          │     block       → response('', $decision->status()) ┼─► honest 403 (short-circuit)
          │     deceive     → map($decision->fakeHandle())      ┼─► byte-exact fake (short-circuit)
          │     report?     → dispatch(SendMainnetReport)       │
          └───────┬─────────────────────────────────────────────┘
                  │ allow/log
                  ▼
             $next($request) ─►  … app … ─► 404?
                                              │  FALLBACK position (posture: honeypot / both)
                                              ▼
                          Funnypot\Laravel\FallbackResponder  (Route::fallback() / exception Handler)
                              same normalize → evaluate → execute, position = FALLBACK
                              (M8 default: deceive everything — FP-free by construction)

   config/funnypot.php ──► FunnypotServiceProvider ──► PolicyEngine::fromArray($policyConfig, ports)
                                                            ports = LaravelStateStore (cache),
                                                                    MainnetReputation (cache-first),
                                                                    LaravelGeoIp (local GeoIP DB),
                                                                    LaravelClock, LaravelLogger,
                                                                    core-backed EvaluatorInterface
   artisan funnypot:rules-update / funnypot:update ──► core RulesUpdater / bin/funnypot
```

- **Stack:** PHP 8.0+ (this package tracks Laravel's floor, NOT the PHP-7.3 floor of core/policy;
  Laravel 8/9/10/11 all run modern PHP, so E is independent of piece C at runtime). Laravel 8.x → 11.x
  target range.
- **Where it runs:** inside the host Laravel app's HTTP kernel. The BEFORE-position middleware is a
  terminating middleware the app opts into (alias `funnypot`, or pushed onto the `web`/global stack);
  the FALLBACK-position hook is a `Route::fallback()` route or the exception `Handler` on a
  `NotFoundHttpException`. Both entry styles are supported and documented.
- **Two positions, one brain:** both entry points call the same `PolicyEngine::evaluate()` with the
  same ports; only the `position` differs. The operator's posture config decides which positions are
  active and what each band earns.
- **Statelessness:** the `PolicyEngine` singleton + the port adapters hold compiled config only; all
  mutable state lives behind the `StateStoreInterface` (Laravel cache), so a container-shared singleton
  is safe under FPM and (with the documented caveat) Octane.

## 4. The concrete surface

All classes keep the **`Funnypot\Laravel\`** namespace they use today in core, so the FQCNs already
documented in `funnypot-core/docs/INTEGRATION.md` and README (`LaravelRequestMapper`,
`LaravelResponseMapper`, `FunnypotServiceProvider`) remain byte-identical for the existing iCabbiTools
drop-in — the move is source-transparent to consumers. The internals change (they now feed the policy
engine instead of calling core's old `detect`/`respond` directly), but the class names and entry
points are preserved.

### 4.1 `Funnypot\Laravel\FunnypotServiceProvider extends Illuminate\Support\ServiceProvider`
Moved from `funnypot-core/src/Laravel/FunnypotServiceProvider.php`, re-pointed at the policy engine:
- `register()`:
  - `mergeConfigFrom(config/funnypot.php, 'funnypot')`; if `funnypot.rules.data_dir` set,
    `RulesLocator::useDataDir($dir)` (core, unchanged).
  - Build the **policy config array** from `config('funnypot.*')` (§5) and bind the `PolicyEngine`
    singleton: `PolicyEngine::fromArray($policyConfig, [ports])` where the ports are E's Laravel
    adapters — `LaravelStateStore` (over `Cache::store(config('funnypot.state.cache_store'))`),
    `MainnetReputation` (over F's `mainnet-client`, cache-first), `LaravelClock`, `LaravelLogger`
    (over `Log::channel('funnypot')`), and the **core-backed `EvaluatorInterface`** (the policy's
    default evaluator wrapping core's two-phase `classify()`+`synthesize()` engine; E injects core's
    engine, invents no policy).
  - `alias(PolicyEngine::class, 'funnypot.policy')`.
- `boot()`: `publishes([... => config_path('funnypot.php')], 'funnypot-config')`;
  `commands([UpdateTemplatesCommand::class, RulesUpdateCommand::class])`; register the `funnypot`
  middleware alias; when `config('funnypot.position.fallback')` is enabled, register the
  `Route::fallback()` route (or document the exception-`Handler` hook) that dispatches
  `FallbackResponder`.
- `provides(): [PolicyEngine::class]`.

### 4.2 `Funnypot\Laravel\HoneypotMiddleware` — BEFORE-position `Decision` executor
Moved from `funnypot-core/src/Laravel/HoneypotMiddleware.php`, re-pointed at the policy engine.
```php
public const ATTRIBUTE_DECISION = 'funnypot.decision';
public function __construct(private \Funnypot\Policy\PolicyEngine $policy) {}
public function handle(\Illuminate\Http\Request $request, \Closure $next); // -> Response|mixed
```
Flow (BEFORE position):
1. `$evidence = LaravelRequestMapper::map($request)` — `RequestEvidence` + `SiteProfile` + the
   TrustProxies-resolved source IP (D7) + the deterministic seed (§4.3).
2. `$decision = $this->policy->evaluate($evidence)` — pure data, no side effects.
3. **Attach** `$decision` as the `funnypot.decision` request attribute (host-app logging/scoring reads it).
4. **Execute** the `Decision`:
   - `allow` / `log` ⇒ `$next($request)` (a `log` additionally records via `LaravelLogger`); the
     default request path is byte-identical to today's.
   - `block` ⇒ short-circuit with `response('', $decision->status() ?? 403)` — an **honest** refusal,
     app-chosen status, never model-chosen (invariant 5). Only reached in a protect-mode posture.
   - `deceive` ⇒ `LaravelResponseMapper::map($decision->fakeHandle())` — the byte-exact `FakeResponse`
     from core's `synthesize()`, Content-Type matching the request, app-chosen status; short-circuit.
   - if `$decision->report()` is non-null ⇒ `dispatch(new SendMainnetReport($decision->report(), …))`
     (the policy already applied suppression; E only delivers — §4.5).
5. **Fail-safe (invariant 2):** the whole body is wrapped so any thrown exception degrades to
   `$next($request)` — never a 500, never a spurious block. A 500 is itself a tell.

Registered by the host app in `$routeMiddleware` (alias `funnypot`) or pushed onto the `web`/global
stack; the package does NOT force itself into the kernel (a honeypot that auto-injects globally is
harder to reason about — the app opts in).

### 4.2b `Funnypot\Laravel\FallbackResponder` — FALLBACK-position `Decision` executor (NEW)
The 404 entry point. Registered either as a `Route::fallback()` action or invoked from the exception
`Handler::render()` on a `NotFoundHttpException`:
```php
public function __construct(private \Funnypot\Policy\PolicyEngine $policy) {}
public function handle(\Illuminate\Http\Request $request): \Illuminate\Http\Response;
```
Same `normalize → evaluate → execute`, with the policy told the **FALLBACK** position. At the fallback,
the counterfactual is already a 404, so `deceive` is FP-free by construction (M5/M8) — the classic
honeypot upgrade of a 404. `block`/`allow`/`log`/`report` are handled identically to §4.2; on `allow`
the responder returns the app's own 404 (never a 500). This is the mechanism behind today's
"send 404s to funnypot" INTEGRATION.md pattern, now routed through the policy engine.

### 4.3 `Funnypot\Laravel\LaravelRequestMapper` / `LaravelResponseMapper`
- **`LaravelRequestMapper::map(Illuminate\Http\Request): RequestEvidence`** — build the neutral
  `RequestEvidence` (method, `getPathInfo`, query, folded headers, body-shape, host, scheme). Three
  additions the policy engine needs:
  - **Source IP (D7):** resolve `$request->ip()` (honoring the app's `TrustProxies`, so
    `X-Forwarded-For` is trusted only behind a configured proxy) and thread it onto the evidence as the
    actor IP — the reputation port, the pin, and the reporter all read this single server-observed
    `REMOTE_ADDR`, never a hand-parsed header. No `ip_header` knob.
  - **`SiteProfile`** — build a `Funnypot\Laravel\LaravelSiteProfile` (stack `laravel` +
    operator-declared extras) whose `routeExists($path)` probes the **Laravel router** (attempt a
    route match against the compiled routes without dispatching — a `NotFoundHttpException` ⇒ false),
    and whose `isSacrificialPath($path)` tests the configured sacrificial set for non-matching paths.
    This is the one input only the host can supply (only Laravel knows its own routes); it is what keeps
    deception FP-free by construction (a fake `/wp-login.php` on a Laravel app that has no such route).
  - **Deterministic seed** — `sha1($sourceIp . config('funnypot.seed_salt' | APP_KEY))`, threaded onto
    the evidence so core's stateless `synthesize()` produces coherent multi-step fakes (M2); the seed is
    also what the policy pins (M5 deception-consistency).
- **`LaravelResponseMapper::map(FakeResponse): Illuminate\Http\Response`** — turn a `deceive` outcome's
  `FakeResponse` (`{status, headers, body, contentType}` from core's `synthesize()`) into an Illuminate
  `Response`, copying status + Content-Type + headers **verbatim** (never re-derived — invariant 5:
  Content-Type must match the request, status is app/engine-chosen, never model-chosen), preserving the
  CRLF/NUL header-injection guard (`preg_match('/[\r\n\x00]/', …)`) that mirrors core's
  `Http\ResponseEmitter`. A `block` outcome maps to a plain `response('', $status)` (also app-chosen).

### 4.4 Artisan commands (moved verbatim from `src/Laravel/Console/`)
- `funnypot:rules-update` — `RulesUpdateCommand`. Signature:
  `{--rollback} {--to=} {--status} {--data-dir=}`. Fetch+verify+hot-swap a signed `funnypot-rules`
  release into `funnypot.rules.data_dir` via `Funnypot\Rules\RulesUpdater`; exit 0 = rules good, 1 =
  update attempted+failed (honeypot stays up on prior release); emits a STALE warning past
  `funnypot.rules.staleness_alarm_hours`. Scheduler-friendly (`->onFailure()`). Unchanged — the signed
  rules artifacts feed core's `classify()`/`synthesize()` corpus, orthogonal to the policy engine.
- `funnypot:update` — `UpdateTemplatesCommand`. Signature: `{templates} {--out=}`. `passthru`s to
  core's `bin/funnypot compile` in a subprocess so a bad corpus can't take the web app down
  mid-request. **Note:** `bin/funnypot` and `resources/compiled/` live in **core**, not this package;
  the command is repointed to resolve core's install path via a small `Funnypot\Support\CorePaths`
  helper in core (design §4.4 unchanged; flagged as a key decision).

### 4.5 Reporting: delivering a `Decision`'s `ReportIntent` (repurposed from the old Observer)
Under M there is **no core `Observer`** in the request path. The policy engine emits a `ReportIntent`
on the `Decision` (`$decision->report()`) **only** after it has applied the 4-layer suppression +
aggregate quorum + allowlist/self_ips/SAFE_PATHS/OAST backstops (policy §9), all keyed through E's
`LaravelStateStore`. E's reporting therefore shrinks to **delivery**:
- **`Funnypot\Laravel\Jobs\SendMainnetReport` (queued `ShouldQueue`).** Dispatched by the
  middleware/fallback responder when `$decision->report()` is non-null. It POSTs to
  `${MAINNET_BASE_URL}/v1/report` (host-only base URL; the reporter appends `/v1/report`) via F's
  relocated `Funnypot\Mainnet\Reporter` (reached transitively): body `ip, categories, comment,
  timestamp, sensor_id`; header `Key: <MAINNET_KEY>`. Queued so mainnet latency never touches request
  latency. **Status branching is by machine-readable error `code`, not bare status (N2):** 2xx ok;
  `429 code=quota_exhausted` and transport faults (timeout/5xx/401/403/malformed) trip / respect
  decision N's shared breaker and **park** the row until the reset, never a tight 30 s re-probe;
  `429 code=duplicate_report` **drops** the row (never re-queues into a loop); other 4xx drop. F's
  relocated `Reporter` owns the breaker + code branching; E only enqueues + delivers.
- **`QUEUE_CONNECTION=sync` driver guard (SF-5).** On the `sync` connection Laravel runs dispatched
  jobs inline, which would put the mainnet POST (and its outage timeout/retries) back on the request
  path. So E resolves the report queue's connection at dispatch: if it resolves to `sync`, the intent
  is instead written to a local durable queue (a `LaravelStateStore` list / DB table) and drained by
  the scheduled `funnypot:report-drain` command — **never inline, never via `dispatchAfterResponse()`**
  (which still pins the FPM worker until the response is fully sent). A one-time `warning` to the
  `funnypot` channel names the sync driver and the fallback. The scheduled drain runs decision N's
  **drain-side budget (N6):** a per-tick wall-clock budget (10 s) + abort after 3 consecutive
  transport failures (writing the shared breaker marker so the check path also fast-skips) +
  attempts/age caps and a hard queue-size cap on re-queued rows (oldest dropped first). The net
  invariant: an outage bounds work + storage and **never slows the protected app**, on any driver.
- **Source IP = server-observed `REMOTE_ADDR` (D7).** The `ReportIntent`'s IP is the TrustProxies-
  resolved `$request->ip()` threaded onto the evidence — never a hand-parsed `X-Forwarded-For`. This
  closes the spoofable third-party report-poisoning hole and mirrors D §4.4.
- **Sensor identity (`sensor_id`, D3).** A `Funnypot\Laravel\SensorId` helper generates + persists a
  stable install UUID (app cache, key `funnypot:sensor_id`) on first run; it is sent as `sensor_id` on
  every report. Convenience label only; the mainnet server computes sensor distinctness on the
  server-observed source IP, not on this client-supplied id. Never a hardware id.
- **Key-gated, inert without a key (D2).** Mainnet is the default destination; delivery is entirely
  inert unless `MAINNET_KEY` is set (same fail-safe shape as the app's `AbuseIpdb`). The suppression /
  self-guard / dedup / cap that decide *whether* an intent exists live in the policy, not here — E
  provides `self_ips` + the cache store the policy uses, and delivers what survives.
- **Fingerprint-safety (invariant 1).** The `comment` is a fixed generic string (e.g. "Automated
  honeypot detection"); categories derive from the policy/verdict's own tag vocabulary via a coarse map
  (fallback `[21]` web-app-attack), never raw nuclei matcher words / CRS rule ids / ModSecurity markers.

### 4.6 `Funnypot\Http\Responder` (unchanged, in core)
Core keeps a framework-agnostic `Http\Responder` for the standalone/PSR-15 path; E's FALLBACK entry
(§4.2b) is the Laravel-native equivalent that routes through the policy engine. E does not wrap
core's `Responder`; it stays in core.

### 4.7 Policy port adapters (NEW — E's core responsibility under M)
E supplies the Laravel implementations of the policy's injected ports:
- **`LaravelStateStore implements Funnypot\Policy\Port\StateStoreInterface`.** Over Laravel's cache
  (`Cache::store(config('funnypot.state.cache_store'))`). Implements the full port: deception-
  consistency pins (`getPin`/`setPin`), the local blocklist (`isBlocked`), learn-then-enforce per-rule
  state (`ruleState`/`putRuleState`/`bumpRuleEvaluated`), the suppression ledger (`seenVerdict`,
  `incrAlertCount`, `bufferReport`/`takeReportBuffer`, `aggregateScore`), and the rolling per-actor
  counters (`actorFacts`, `incr`). This is the **same** injected store the reputation cache uses (a
  shared cache store, distinct key namespaces). A persistent store (redis/database/memcached) is
  recommended; the array driver works for a single-process test.
- **`MainnetReputation implements Funnypot\Policy\Port\ReputationInterface`.** Over F's `mainnet-client`.
  **Mirror-first, then cache-first, request-path-safe (M5 + O1):** `lookup($ip)` resolves in three
  cheap, local, socket-free steps — (1) the **local blacklist mirror** in `LaravelStateStore` (the
  O1 primary fresh-read, refreshed out-of-band by `funnypot:mirror-sync`); a mirror hit returns its
  verdict with `source='mirror'`; (2) on a mirror miss, F's **cached** per-IP verdict
  (`source='cache'`); (3) on both misses, a fail-open `unknown` (`source='fail-open'`) — it **never**
  makes a synchronous network call on the request path. An uncertain IP (mirror + cache miss) is the
  only **escalation** candidate: a fresh `Client::check` runs **out-of-band** in a small warmer (a
  queued `WarmReputation` job dispatched after the response, or a scheduled `funnypot:reputation-warm`
  command) that populates F's cache; the next request reads it. The warmer honors decision N's shared
  breaker (a fresh `check` is skipped while OPEN) and is itself queue-driver-safe (SF-5: on `sync` it
  falls back to the scheduled command, never inline). Inert (always `unknown`) unless the operator
  enabled checking AND `MAINNET_KEY` is set. This **reconciles decision F with M5**: F's short-timeout
  inline `ReputationGate::decide()` is replaced, *on the request path*, by the mirror + cache read;
  F's `Client`, `Cache` (via `Psr16Cache` over Laravel cache), and circuit breaker (decision N) still
  power the out-of-band warm, the mirror sync, and the escalation `check`. **Entity + containment
  (P2/Q2):** a mirror/verdict row's `score_key` may be an exact IP, a **CIDR** (IPv4 /24, IPv6 /64 or
  coarser) or an **ASN** (Q1), so `lookup` matches the visitor by **CIDR-containment / ASN-lookup, never
  exact-match** — the containment matcher is **funnypot-policy's** (E passes the visitor IP + the mirror
  set to it and normalises an IPv6 to its /64 `score_key` before the lookup and before reporting, per
  P2); E re-implements no CIDR/ASN math.
- **`Funnypot\Laravel\Reputation\MirrorSync` + the `funnypot:mirror-sync` command (NEW — O1).** The
  local-mirror-lite fresh-read: a scheduled conditional `GET ${MAINNET_BASE_URL}/v1/blacklist?format=json&variant=thin`
  (`Key:` header, stored `ETag`/`If-None-Match` → 304 spends no bandwidth) whose thin rows
  (`{ip, verdict, expires_at}`, where the `ip` field may carry a **CIDR or ASN `score_key`** — P2/Q2/Q1)
  are written into `LaravelStateStore` as the authoritative blocklist
  mirror, honoring each row's `expires_at`. Runs ~24×/day (cadence configurable), breaker-guarded and
  queue-driver-safe like the warmer. This is what makes fleet reputation reads scale as CDN egress
  rather than per-IP origin QPS, and is the primary consumer of the G3 blacklist artifact.
- **`LaravelGeoIp implements Funnypot\Policy\Port\GeoIpInterface` (NEW — decision R).** Resolves a
  visitor IP's ISO-3166 country from a **LOCAL GeoIP DB** (DB-IP Lite / GeoLite2 `mmdb`, the same
  dataset the honeypot dashboard + A1 enrichment already use): `country($ip): ?string`, **socket-free,
  never a network call** on the request path (M5/R2), resolving IPv6 as well as IPv4 (R2). A miss /
  unreadable DB returns `null` (unknown) so the policy's country gate simply **skips** — a GeoIP fault
  never blocks and never 500s. Inert (always `null`) when `geoip.enabled=false`. This is the input to
  the policy's **R1 cheap-static country gate** (after allowlist/pin, before reputation/content — M5);
  the deny-list / allow-list posture and the block/deceive/modifier action live in the policy, keyed
  from E's `country` config (§5). E supplies the country string only; it owns no country policy.
- **`Funnypot\Laravel\Geo\GeoIpRefresh` + the `funnypot:geoip-refresh` command (NEW — R2).** The
  local-GeoIP-DB **distribution + refresh** concern: a scheduled pull that refreshes the install's local
  DB-IP Lite dataset (monthly cadence — the dataset's own release rhythm) into the configured
  `geoip.database` path. It **rides the feed/freshness distribution seam** (the same scheduled,
  queue-driver-safe shape as `funnypot:mirror-sync`), so a fleet install keeps a fresh local DB without
  a per-request network call (M5/R2). Inert when `geoip.enabled=false`; a failed refresh leaves the
  prior DB in place (the country gate keeps working on the last-good dataset, or skips if none).
- **`LaravelClock implements Funnypot\Policy\Port\Clock`** — `now(): int` over Laravel's clock (so
  dwell/TTL/decay windows are testable via `Carbon::setTestNow`).
- **`LaravelLogger implements Funnypot\Policy\Port\Logger`** — `log($level, $message, $context)` over
  `Log::channel('funnypot')` (PSR-3-shaped; never logs a signature string / raw payload / secret —
  policy §10).

## 5. Data / config model

Published `config/funnypot.php` (moved from core, re-pointed). E's config is a Laravel front-end that
**produces the policy config array** (the canonical shape is owned by the funnypot-policy spec §8, built
via `Funnypot\Policy\PolicyConfig::fromArray()`); the keys below map onto it 1:1. Two axes stay:
**STYLE** (`response_style` = `FUNNYPOT_STYLE` minimal|realistic|taunt — how a fake *looks*, synthesis
config passed straight into core) and **POSTURE** (`honeypot|WAF|both` — what the deployment IS).
Defaults stay **INERT / FP-free**: fallback deceives everything (safe by construction), everything at
the before-position is either FP-free-by-construction or in SHADOW (M8).

Synthesis / STYLE keys (unchanged, passed into core's `synthesize()` via the policy): `response_style`,
`persona_seed`, `persona_breadth`, `severity_ceiling`, `max_body_bytes`, `latency_ms`, `seed_salt`
(defaults to `APP_KEY`), and the `rules` block (`data_dir`, `channel`, `pinned_version`, `repo`,
`staleness_alarm_hours`).

Posture / position / decision-matrix (→ the policy config array):
```php
'posture'  => env('FUNNYPOT_POSTURE', 'honeypot'),  // honeypot | WAF | both (preset selector, M1/M8)
'position' => [
    'before'   => (bool) env('FUNNYPOT_POSITION_BEFORE', false),   // BEFORE-position middleware
    'fallback' => (bool) env('FUNNYPOT_POSITION_FALLBACK', true),  // FALLBACK 404 hook (default on)
],
// per-band action ceiling on REAL routes (the policy §5 ladder allow→log→block→deceive). Sacrificial /
// 404-counterfactual paths are governed by the day-1 carve-out and always may deceive.
'actions' => [
    'clean'         => 'allow',
    'suspicious'    => 'log',       // uncertainty band → never deceive (policy §5)
    'attack_class'  => 'block',     // specific class on a real route → block
    'scanner_probe' => 'deceive',   // counterfactual 404 → deceive
],
'learn' => [
    'shadow_days'       => (int) env('FUNNYPOT_SHADOW_DAYS', 7),
    'shadow_min_reqs'   => (int) env('FUNNYPOT_SHADOW_MIN_REQS', 5000),
    'baseline_excluded' => [],      // known-FP-prone rule ids, pre-excluded (policy §6)
    'kill_switch'       => (bool) env('FUNNYPOT_KILL_SWITCH', false),
],
'pin' => [ 'ttl_seconds' => (int) env('FUNNYPOT_PIN_TTL', 3600) ],  // deception-consistency (M5)
```

New `mainnet` block — the canonical address + key (D1). `MAINNET_BASE_URL` is **host only, no path**;
the reporter appends `/v1/report` itself. `MAINNET_KEY` gates both the reporter (D2) and the reputation
check (F):
```php
'mainnet' => [
    // Host only, no path. The reporter appends /v1/report. Defaults to the mainnet placeholder host —
    // NEVER the real AbuseIPDB host (that would 404 + hit the wrong service). D1/D2.
    'base_url' => env('MAINNET_BASE_URL', 'https://api.mainnet.example'),
    // Operator-issued SENSOR-tier key (O2): report rights + an escalation-check quota sized for
    // per-visitor traffic, metered per-install (sensor_id / server-observed source_ip), NOT per shared
    // key (D3 blesses key sharing). Empty => reporter + check + mirror-sync all inert.
    'key'      => env('MAINNET_KEY', ''),
    // sensor_id is not env-configured: generated + persisted on first run (install UUID). D3.
],
```

New `check` block — the inbound reputation gate, now a **policy `reputation` action/config** (decision
F, keyed under M's §4 rules). Off by default; when enabled it supplies the *cached* verdict the policy
combines as a modifier (never primary, never deceive-on-rep-alone — policy §4). Reputation-block is the
opt-in inversion (BEFORE position, verdict-keyed):
```php
'check' => [
    'enabled'         => (bool) env('FUNNYPOT_CHECK_ENABLED', false), // opt-in: spends credits + sends the visitor IP to a third party (GDPR)
    'block_verdicts'  => array_values(array_filter(array_map('trim',
                             explode(',', env('FUNNYPOT_CHECK_BLOCK_VERDICTS', 'malicious,critical'))))),
    'min_block_score' => (($v = env('FUNNYPOT_CHECK_MIN_BLOCK_SCORE')) === null || $v === '')
                             ? null : (int) $v,
    'cache_ttl_hours' => (int) env('FUNNYPOT_CHECK_CACHE_TTL_HOURS', 12),  // F verdict-cache TTL
    'fail_mode'       => env('FUNNYPOT_CHECK_FAIL_MODE', 'open'),          // 'open' (default) | 'closed'
    'timeout_ms'      => (int) env('FUNNYPOT_CHECK_TIMEOUT_MS', 1500),     // out-of-band warmer only (M5: no sync call in-path)
],

// O1 local-mirror-lite: the PRIMARY fresh-read. funnypot:mirror-sync pulls the thin blacklist artifact
// on cron (conditional GET, ETag/304) into LaravelStateStore; per-IP check is escalation-only. Inert
// unless check.enabled + mainnet.key (a mirror pull spends the sensor key's read quota).
'mirror' => [
    'enabled'       => (bool) env('FUNNYPOT_MIRROR_ENABLED', true),   // on when check is on; the O1 default read path
    'variant'       => env('FUNNYPOT_MIRROR_VARIANT', 'thin'),        // thin {ip,verdict,expires_at} | full (O4)
    'sync_minutes'  => (int) env('FUNNYPOT_MIRROR_SYNC_MINUTES', 60), // ~24 pulls/day; CDN + 304-friendly
],

// Decision N shared fail-open breaker + drain budget (F owns the mechanism; these are E's knobs / the
// canonical N numbers, deviations noted). One shared marker lives in the persistent state cache below.
'breaker' => [
    'threshold_transport' => (int) env('FUNNYPOT_BREAKER_THRESHOLD', 5),    // consecutive transport faults => OPEN (N2)
    'cooldown_secs'       => (int) env('FUNNYPOT_BREAKER_COOLDOWN', 60),    // transport cooldown, +-20% jitter (N2; supersedes F's 30)
    'quota_park_cap_secs' => (int) env('FUNNYPOT_BREAKER_QUOTA_CAP', 21600),// quota park = server reset, capped 6h (N2)
    'drain_budget_secs'   => (int) env('FUNNYPOT_DRAIN_BUDGET', 10),        // per-tick wall-clock budget (N6)
    'drain_max_fails'     => (int) env('FUNNYPOT_DRAIN_MAX_FAILS', 3),      // abort a tick after N consecutive transport fails (N6)
    'drain_limit'         => (int) env('FUNNYPOT_DRAIN_LIMIT', 200),        // rows per tick (N6 canonical)
],
```

New `reporting` block (delivery + self-guard; the *suppression* lives in the policy `suppression`
block, backed by `LaravelStateStore`):
```php
'reporting' => [
    'enabled'    => (bool) env('FUNNYPOT_REPORT_ENABLED', true),  // mainnet by default; inert without mainnet.key
    'self_ips'   => array_filter(explode(',', env('FUNNYPOT_SELF_IPS', ''))), // never self-score/self-report
    'queue'      => env('FUNNYPOT_REPORT_QUEUE', null),                       // null = default queue
    // SF-5 driver guard: if the resolved queue connection is `sync`, delivery falls back to a local
    // scheduler-drained queue (funnypot:report-drain), never inline / never dispatchAfterResponse().
    'categories' => [21], // fallback category ids when the verdict has no finer map
],
'suppression' => [  // → policy §9 (the iCabbiTools 4-layer prior-art model); backed by LaravelStateStore
    'verdict_dedup_hours' => (int) env('FUNNYPOT_SUP_DEDUP_HOURS', 24),
    'per_ip_alert_cap'    => (int) env('FUNNYPOT_SUP_IP_CAP', 100),
    'per_ip_cap_window_s' => (int) env('FUNNYPOT_SUP_IP_CAP_WINDOW', 600),
    'buffer_ttl_s'        => (int) env('FUNNYPOT_SUP_BUFFER_TTL', 900),
    'score_gate'          => (int) env('FUNNYPOT_SUP_SCORE_GATE', 200),
    'aggregate'           => ['min_sources' => 2, 'min_total_score' => 200, 'window_days' => 90],
    'decay'               => ['base_ttl_s' => 600, 'cap_ttl_s' => 86400,
                              'inc_soft' => 1, 'inc_medium' => 10, 'inc_hard' => 100],
],
'allowlist' => [  // → policy allowlist (hard override, re-checked at every mutating point — policy §9)
    'ips'        => array_filter(explode(',', env('FUNNYPOT_ALLOW_IPS', ''))),
    'cidrs'      => array_filter(explode(',', env('FUNNYPOT_ALLOW_CIDRS', ''))),
    'safe_paths' => [],  // isIgnoredUri set — health checks, the app's own asset paths
],
// R country policy: an optional cheap-static country gate in the policy ladder (after allowlist/pin,
// before reputation/content — R1/M5). Off by default; blunt (VPN/CGNAT/roaming/cloud egress — R4), so an
// eyes-open opt-in. The country is resolved LOCALLY (no network call — R2) via the geoip block below.
'country' => [
    'enabled'  => (bool) env('FUNNYPOT_COUNTRY_ENABLED', false),
    'posture'  => env('FUNNYPOT_COUNTRY_POSTURE', 'denylist'),   // denylist | allowlist (stricter, higher-FP — R1/R4)
    'action'   => env('FUNNYPOT_COUNTRY_ACTION', 'modifier'),    // modifier (default) | deceive | block (R3)
    'modifier' => (int) env('FUNNYPOT_COUNTRY_MODIFIER', 25),    // suspicion added when action=modifier (feeds the policy score, never deceives alone — R3/M6)
    'list'     => array_values(array_filter(array_map('strtoupper', array_map('trim',
                      explode(',', env('FUNNYPOT_COUNTRY_LIST', '')))))),  // ISO-3166 alpha-2 codes: deny-list members OR the allow-list
],
// R2 LOCAL GeoIP DB: reuse DB-IP Lite (the dataset the dashboard + A1 enrichment already use). The country
// is resolved from this LOCAL DB — NEVER a network call on the request path (M5). funnypot:geoip-refresh
// refreshes it on the feed/freshness distribution seam. Required by country.enabled.
'geoip' => [
    'enabled'  => (bool) env('FUNNYPOT_GEOIP_ENABLED', false),
    'database' => env('FUNNYPOT_GEOIP_DB', null),   // path to the local DB-IP Lite / GeoLite2 mmdb; null = the shared/packaged default
    'refresh'  => [
        'enabled' => (bool) env('FUNNYPOT_GEOIP_REFRESH_ENABLED', true), // scheduled refresh rides the feed distribution (R2)
        'days'    => (int) env('FUNNYPOT_GEOIP_REFRESH_DAYS', 30),        // DB-IP Lite is monthly
    ],
],
// RS-10 selectable local-state backend. `cache_store` names any configured Laravel cache store
// (redis/database/memcached/file/array), so the operator picks object-cache/DB-row vs file per host;
// null = the app default. It backs the StateStore, the reputation cache, the O1 mirror, the sync-driver
// report queue, and the breaker marker. The chosen default MUST be one that works where the package
// directory is read-only / multi-node (a DB or shared object cache, not a per-node file) — E does not
// write local state into its own package dir; it never assumes a writable filesystem.
'state' => [ 'cache_store' => env('FUNNYPOT_STATE_CACHE_STORE', null) ],
```
- The reputation gate, the reporter, and the pin all read the SAME source IP — `$request->ip()`
  honoring `TrustProxies` (D7) — never a hand-parsed `X-Forwarded-For`. There is no `ip_header` knob.
- **No `endpoint`, `mainnet_host`, or `ip_header` keys** (removed — D1/D7). **No `block_threshold`
  key** (F is verdict-first — replaced by `block_verdicts` + `min_block_score`, decision H).
- `config('funnypot.mainnet.base_url')` is the Laravel-local wrapper over `MAINNET_BASE_URL`; the
  underlying env name and the "base URL only" convention are fixed by D1.
- **`sensor_id` persistence (D3):** the `SensorId` helper reads/creates a stable install UUID via the
  app's persistent cache store (key `funnypot:sensor_id`) on first use; the value survives restarts. If
  the cache is non-persistent, the operator may pin it — it is a label only, so regeneration is harmless.
- **Breaker needs a persistent, cross-request `state.cache_store` (N1):** the single shared
  `mnc:breaker` marker (and the O1 mirror + the SF-5 sync-fallback report queue) live in the configured
  store; the `array` driver is per-process, so with it the breaker is inert (documented for the
  single-process test only). When no shared cache is available F falls back to a `filemtime` marker in
  the system temp dir (N1) so even cache-less installs share outage state; an absent/evicted marker is
  treated as CLOSED (never blocks). Reads are **mirror-first, then cache**; the per-IP `check` is
  escalation-only (O1) and out-of-band (M5).
- **Country gate reads the same source IP; GeoIP is local (R1/R2).** The `country` gate resolves the D7
  server-observed `REMOTE_ADDR` against the **local** `geoip.database` (no network call — M5); the
  default action is a suspicion **modifier**, never a lone-signal deceive (R3/M6), with hard block / the
  allow-list posture as explicit opt-ins (R4). The deny/allow decision is the policy's; E supplies the
  resolved country + the config.
- **Mirror/verdict rows may be ranges (P2/Q2).** A mirror row's key may be a CIDR (IPv4 /24, IPv6 /64 or
  coarser) or an ASN (Q1); the reputation lookup matches the visitor by **containment / ASN-lookup, not
  exact match**, via funnypot-policy's matcher — E normalises an IPv6 to its /64 `score_key` before the
  mirror lookup and before reporting (P2) and re-implements no CIDR/ASN math.

### 5.1 Consumer decision overlay → folds into the policy allowlist/blocklist (decision L6)
The earlier E-local `overlay` block (allow/deny lists, `log_only`, reserved `verdict_floor`/
`exceptions`/`verified_good_bots`) is **subsumed by the policy** under M: `allow` is the policy
`allowlist` (hard override, beats reputation — policy §4 step 1); `deny` is the policy local blocklist
(`StateStore.isBlocked` / a `blocklist` config list); `log_only` is the policy's SHADOW posture (action
forced to `log`, M7) or the `kill_switch`. E surfaces these as the `allowlist` / `learn.kill_switch`
config above rather than a separate overlay the middleware re-implements — the precedence (allow >
deny > mainnet verdict) is the policy's §4 ladder, owned once. `verdict_floor`/`exceptions`/
`verified_good_bots` remain **reserved** shapes in the policy config (extensible `context`), not
E-local logic.

No database migrations. Pins, learn-then-enforce state, the suppression ledger, dedup/cap counters, the
reputation cache, and `sensor_id` all live in the app's cache (behind the `StateStoreInterface` /
`Psr16Cache`); report delivery uses the app's queue. No new tables owned by this package.

## 6. Security & invariants touched

- **Fingerprint-safety (core CI gates):** this package emits nothing new onto the wire beyond what
  core's `synthesize()` `FakeResponse` already produced — mapping only. It does NOT compile templates
  or touch persona corpora, so core's compile/publish-time fingerprint gates are unaffected. The
  reporter's `comment` field is a generic string and MUST NOT embed nuclei matcher words / CRS rule
  ids / ModSecurity markers; the policy's `Verdict.matched` handle and `Decision.reason` are opaque
  labels, never signature strings (policy §10) — E logs/reports only those.
- **The engine only *upgrades* a 404 / never a 500 (invariant 2):** on `allow`/`log` the middleware
  returns `$next` and the fallback returns the app's own 404; `deceive` maps a fake, `block` an honest
  403. Any mapper/port/evaluate fault is caught and degrades to pass-through (`$next` / plain 404),
  never a 500 — a 500 is itself a tell. The policy engine is also fail-safe (any evaluator/synthesize
  fault → `Decision::allow`, policy §10); E's try/catch is the belt-and-suspenders on top.
- **Reputation fails OPEN, never 500s or self-blocks, never a sync call in-path (F + M5):** the
  reputation port is inert unless `check.enabled` AND `mainnet.key`; `lookup` is mirror-first then
  cache-first and returns `unknown` on a miss (no network call on the request path). A `block` is a
  deliberate app-chosen status chosen by the *policy* (verdict-keyed, opt-in), never by the model — no
  model-driven status. A visitor IP is transmitted to a third party ONLY when the operator opts in +
  supplies a key, and only by the **out-of-band warmer / mirror-sync**, never inline (GDPR: documented
  in the published config comments + README).
- **Country gate is local + fail-safe (R2/R4):** the `LaravelGeoIp` port reads a **LOCAL** GeoIP DB (no
  network call on the request path — M5); an unknown / unreadable lookup returns `null`, so the policy's
  country gate is **skipped**, never a block or a 500. Country-blocking is blunt (VPN/CGNAT/roaming/cloud
  egress — R4), so it is **off by default** and the default action is a suspicion **modifier**, never a
  lone-signal deceive (R3/M6: a wrongly-deceived legit user is silent corruption). Hard block and the
  allow-list posture are explicit, documented opt-ins.
- **An outage never slows or takes down the protected app (N + SF-5):** every request-path reputation
  read is local (mirror + cache) and socket-free; a fresh `check` and the mirror pull are out-of-band
  and skipped while decision N's shared breaker is OPEN. On `QUEUE_CONNECTION=sync`, report delivery and
  the warmer fall back to a scheduler-drained local queue rather than executing inline (SF-5), and the
  drain has a wall-clock budget + early-abort + queue caps (N6) — so a mainnet outage bounds work and
  storage without holding an FPM worker. `fail_mode=closed` governs only genuine could-not-check states,
  **never** the inert/no-key/422 states (those always allow — SF-3).
- **Rules-update is RCE-adjacent:** `funnypot:rules-update` calls `RulesUpdater` unchanged; the
  ed25519 + per-file sha256 + array-literal validation all live in core and are not weakened. The
  `data_dir` guidance (dedicated non-web user, 0755/0644, outside web root) carries into the published
  config comments verbatim.
- **Content-Type matches request; status app-chosen:** enforced by `LaravelResponseMapper` copying the
  `FakeResponse` headers/status verbatim (§4.3); block status is app-chosen. No model-driven 3xx →
  no open-redirect.
- **AbuseIPDB / mainnet self-guard:** `self_ips` is the Laravel analogue of `FUNNYPOT_SELF_IPS`, fed
  into the policy's allowlist-everywhere backstop; documented that the operator's own egress/test IP
  must be listed before enabling reporting from a box that also runs scans.
- **Deception consistency (M5):** a deceived actor is pinned (`LaravelStateStore.setPin` with the seed +
  `pin.ttl_seconds`) so later requests replay the same action + seed — the anti-unmask property, owned
  by the policy, backed by E's store.
- **Header-injection guard:** the CRLF/NUL filter in `LaravelResponseMapper` is preserved.

## 7. Testing strategy

`orchestra/testbench` (Laravel's package test harness). E is a Laravel package, so testbench is the
right tool; the security-critical decision logic is tested **in the policy package** (its own pure
suite) — E's tests prove the **adapter** correctly normalizes, executes each `Decision`, and wires the
ports.
- **Provider/DI:** boot testbench with the provider auto-discovered; assert `app(PolicyEngine::class)`
  resolves and is wired with E's port adapters; assert `vendor:publish --tag=funnypot-config` writes
  `config/funnypot.php`; assert commands + the `funnypot` middleware alias are registered.
- **`Decision` execution (BEFORE middleware), with a fake `PolicyEngine` scripting each action:**
  - `allow`/`log` ⇒ response is `$next`'s, the `funnypot.decision` attribute is populated, status/body
    untouched (a `log` also records to the `funnypot` channel).
  - `block` ⇒ short-circuit with the `Decision`'s status (default 403), engine `evaluate` consulted
    once, `$next` never called.
  - `deceive` ⇒ short-circuit; the Illuminate `Response` carries the `FakeResponse`'s exact status,
    body, and a Content-Type distinct from the app default (proves the Content-Type invariant end-to-end).
  - `report` non-null ⇒ exactly one `SendMainnetReport` dispatched (`Queue::fake()`).
- **Fault degradation:** a `PolicyEngine` whose `evaluate()` throws ⇒ the middleware returns
  pass-through (`$next`), status 200-class, **never a 500**; the fallback responder returns the app 404.
- **FALLBACK position:** the `FallbackResponder` on a `deceive` `Decision` emits the fake; on `allow`
  returns the app's own 404 (never a 500).
- **Request normalization (D7 + SiteProfile + seed):** `LaravelRequestMapper::map()` yields a
  `RequestEvidence` with the right method/path/query/folded-headers/body-shape; the threaded actor IP is
  the TrustProxies-resolved `REMOTE_ADDR` (an **untrusted** `X-Forwarded-For` is ignored; with
  `TrustProxies` trusting the peer the XFF is honored); the `SiteProfile.routeExists()` returns true for
  a real registered route and false for an unrouted probe path; the seed is stable for a given IP+salt.
- **Response mapping:** a `FakeResponse` with a Content-Type unlike Laravel's default survives verbatim;
  a header name/value containing `\r`/`\n`/`\x00` is dropped.
- **Port adapters:**
  - `LaravelStateStore` — round-trip a pin (get/set with TTL), `isBlocked`, a rule-state transition,
    and the suppression-ledger counters (`seenVerdict` dedup, `incrAlertCount`, `bufferReport`/`take`)
    against the array cache; assert TTLs expire via `Carbon::setTestNow` + `LaravelClock`.
  - `MainnetReputation` — **mirror-first, then cache-first (O1):** an IP present in the O1 local
    mirror is returned by `lookup()` with `source='mirror'` and **zero** outbound calls; on a mirror
    miss a primed F cache entry is returned with `source='cache'` and zero outbound calls (assert with
    F's fake transport); on both misses `lookup()` returns `unknown` (`source='fail-open'`) and makes
    **no network call** on the request path; inert (always `unknown`) when `check.enabled=false` or
    `mainnet.key` empty. The out-of-band warmer (queued/scheduled) is the only path that calls
    `Client::check`, and only for an escalation (mirror + cache miss).
  - **`MirrorSync` / `funnypot:mirror-sync` (O1):** a first sync writes the thin-artifact rows into
    the store (a later `lookup` serves from the mirror with no call); a second sync sends the stored
    `ETag` as `If-None-Match` and a 304 leaves the mirror intact; inert when `check.enabled=false` or
    `mainnet.key` empty; skipped while the decision-N breaker is OPEN. **Range rows (P2/Q2):** a synced
    **CIDR** row (e.g. a /24 or an IPv6 /64) is matched for a *contained* visitor IP by `lookup` (via the
    policy matcher), not just an exact IP; an IPv6 visitor is normalised to its /64 `score_key` before
    the lookup.
  - **`LaravelGeoIp` (decision R):** with a fixture local GeoIP DB, `country($ip)` returns the expected
    ISO code for a known IP (IPv4 and IPv6 — R2), `null` for an IP absent from the DB, and `null` (with
    no throw) when `geoip.enabled=false` or the DB path is missing/unreadable — proving the country gate
    fails **open/skip**, never blocks or 500s, and makes **no network call**. Policy integration: with
    the real `PolicyEngine`, a `country.enabled` deny-list match with `action=modifier` raises the
    suspicion score (never deceives alone — R3/M6), and an `action=block` opt-in yields `Decision::block`
    (the decision is the policy's, not E's).
  - **`GeoIpRefresh` / `funnypot:geoip-refresh` (R2):** a faked dataset source refreshes the local DB
    file; a failed refresh leaves the prior DB in place; inert when `geoip.enabled=false`; scheduled +
    queue-driver-safe like `funnypot:mirror-sync`.
  - **Decision-N breaker (N):** two `MainnetReputation`/reporter instances over one shared
    `state.cache_store` share the `mnc:breaker` marker; consecutive transport faults reach the
    threshold → OPEN → a subsequent warm/mirror/drain fast-skips with no socket work; a `429`
    `code=quota_exhausted` parks until the reset (not the transport cooldown); a `429`
    `code=duplicate_report` neither trips the breaker nor loops the re-queue; an absent/fresh store is
    breaker-inert and never blocks (documented). `fail_mode=closed` + an inert reputation port ⇒
    **allow** (SF-3).
- **Reporting delivery:** with `Http::fake()` + `Queue::fake()`, a `Decision` carrying a `ReportIntent`
  ⇒ one `SendMainnetReport` job POSTs to `${mainnet.base_url}/v1/report` with the `Key` header and an
  `ip,categories,comment,timestamp,sensor_id` body (the `ip` being the TrustProxies-resolved
  `REMOTE_ADDR`, the `sensor_id` the persisted install UUID); assert the base-URL default is the mainnet
  placeholder (not AbuseIPDB); an empty `mainnet.key` dispatches nothing; a 5xx retries while a 4xx
  drops; the POSTed `comment` matches a generic allowlist and contains none of a small denylist of
  signature-shaped tokens.
- **Sync-driver guard (SF-5):** with `QUEUE_CONNECTION=sync`, a `Decision` carrying a `ReportIntent`
  makes **zero transport calls in-request** — the intent lands on the local scheduler-drained queue
  (assert `Http` recorded nothing during the request; the row is enqueued) and a one-time `warning`
  is logged to the `funnypot` channel; a subsequent `funnypot:report-drain` tick delivers it. The same
  guard covers the reputation warmer (a `sync` warmer makes no in-request `check`).
- **Drain budget (N6):** under a total mainnet outage, a `funnypot:report-drain` tick completes within
  the wall-clock budget and aborts after the configured consecutive-transport-fail count (writing the
  shared breaker marker); re-queued rows honor the attempts/age caps and the hard queue-size cap.
- **Config → policy array:** assert `PolicyConfig::fromArray()` receives the mapped array with
  `posture`/`position`/`actions`/`reputation`(`block_verdicts` default `['malicious','critical']`,
  `min_block_score` null, **no `block_threshold`**)/`learn`/`pin`/`suppression`/`allowlist`/`self_ips`;
  the `country` block (`enabled` false, `posture` `denylist`, `action` `modifier`, `modifier` 25 — R) and
  the `geoip` block (`enabled` false, `database` null, `refresh.days` 30 — R2) are present;
  the `mirror` block (`enabled` true, `variant` `thin`, `sync_minutes` 60 — O1) and the `breaker` block
  (N canonical numbers: `threshold_transport` 5, `cooldown_secs` 60, `drain_budget_secs` 10,
  `drain_max_fails` 3, `drain_limit` 200) are present; assert the removed
  `mainnet_host`/`endpoint`/`ip_header`/`block_threshold` keys are absent.
- **Command smoke:** `funnypot:rules-update --status` with no `data_dir` errors cleanly (exit 1);
  `--status` with a temp data dir prints JSON; `funnypot:update` resolves core's `bin/funnypot` via
  `CorePaths`, not E's root.
- **Matrix:** run against the Laravel versions in the supported range (8–11) via testbench's version
  matrix in CI.

## 8. Key decisions I made (confirm at review)

1. **E is a THIN adapter over `funnypot-policy`; it owns no decision logic (decision M).** Normalize →
   `evaluate` → execute is the whole contract. The cheapest-first precedence, the two-axis combination,
   learn-then-enforce, pin/TTL, and the 4-layer suppression live once, in the policy package — an
   adapter that re-implements any of them is a bug. Supersedes the earlier "middleware calls core's
   `detect`/`respond` directly" model.
2. **E is the new home of core's Laravel bridge; core is trimmed (follow-up).** E moves
   `src/Laravel/*`, `config/funnypot.php`, and `extra.laravel.providers` out of core verbatim (FQCNs
   preserved) and re-points them at the policy engine; a coordinated follow-up removes them from core so
   core carries zero Illuminate references. Core and E cannot both declare `Funnypot\Laravel\*` at once
   (double class declaration), so the core-trim MUST land in the same release as E v1.
3. **E requires `funnypot-policy` (primary); core + mainnet-client arrive transitively.** E adds a
   direct `require` on core only for the artisan rules-update/compile commands. No dependency on the
   funnypot app.
4. **Two positions are both shipped; posture/position are config, not code (M4/M8).** A BEFORE
   terminating middleware and a FALLBACK 404 hook, both calling the same `PolicyEngine::evaluate()`.
   Default install: fallback deceives everything (FP-free), before-position runs only the
   FP-free-by-construction gates + SHADOW (M8). Choosing honeypot vs WAF vs both is a config array value.
5. **Reputation is a policy action/config, cache-first (F reconciled with M5).** E supplies a
   `ReputationInterface` adapter that reads F's **cache** on the request path (never a sync network
   call — M5); a fresh `Client::check` runs only in an out-of-band warmer. The block-on-reputation
   feature is the policy's opt-in modifier (verdict-keyed, BEFORE position, never primary), executed by
   E as a `Decision::BLOCK`. E owns no scoring threshold.
6. **Reporting is delivery-only; suppression lives in the policy.** E enqueues a `Decision`'s
   `ReportIntent`; the 4-layer suppression + quorum + allowlist/self_ips/SAFE_PATHS/OAST backstops are
   the policy's, backed by E's `LaravelStateStore`. The old core `Observer` seam is gone from E's path.
7. **`MAINNET_BASE_URL` is host-only and defaults to the mainnet placeholder, key-gated (D1/D2).** The
   canonical env pair is `MAINNET_BASE_URL` (scheme + host, no path) + `MAINNET_KEY`. Never the real
   AbuseIPDB host. With no key the reporter and the reputation check are both inert.
8. **Source IP is the server-observed `REMOTE_ADDR` via `$request->ip()`/`TrustProxies` (D7).** No
   `ip_header` knob; untrusted XFF is never parsed. The reputation port, the pin, and the reporter all
   read this one value.
9. **`SiteProfile` real-route oracle is backed by the Laravel router.** `routeExists()` probes the
   compiled routes without dispatching — the FP-safety input that keeps deception off real routes. Only
   the host can supply it (only Laravel knows its own routes).
10. **PHP floor tracks Laravel (8.0+), not the 7.3 floor of core/policy.** E is independent of piece C
    at runtime; core/policy stay 7.3-capable for the WordPress consumer.
11. **`funnypot:update` core-path resolution is fixed on the move** (via core's `CorePaths` helper), and
    the reporter `comment`/`categories` are fingerprint-safe (generic comment; coarse category map,
    fallback `[21]`).
12. **Local-mirror-lite is the PRIMARY reputation fresh-read (O1).** A scheduled `funnypot:mirror-sync`
    pulls the thin blacklist artifact (conditional GET, ETag/304) into `LaravelStateStore`; the port
    reads mirror-first, then F cache, and only escalates an uncertain IP to an out-of-band per-IP
    `check`. Fleet reads scale as CDN egress, not origin QPS.
13. **E holds a `sensor`-tier `MAINNET_KEY` (O2).** Report rights + an escalation-check quota sized for
    per-visitor traffic, metered per-install (`sensor_id`/`source_ip`), not per shared key. Documented
    on the `mainnet.key` config and the A1 dependency (§9); A1 issues the sensor key out of band.
14. **Resilience per decision N + SF-5.** F owns the single shared fail-open breaker (`mnc:breaker` in
    the persistent `state.cache_store`; canonical numbers threshold 5 / cooldown 60 s / drain 10 s·3);
    E consumes it on the warmer, mirror-sync, and report drain. On `QUEUE_CONNECTION=sync`, report
    delivery + the warmer fall back to a scheduler-drained local queue (never inline, never
    `dispatchAfterResponse()`); the drain has a wall-clock budget + early-abort + queue caps (N6).
    `fail_mode=closed` never applies to inert/no-key/422 states (SF-3).
15. **Country policy is a LOCAL-GeoIP cheap-static gate; E supplies the country, the policy decides
    (decision R).** A `country` config — deny-list OR allow-list posture; action block/deceive/**modifier**,
    default modifier (R1/R3) — drives the policy's R1 gate. E's `LaravelGeoIp` port resolves the country
    from a **LOCAL** DB-IP Lite DB (no network call — R2/M5), and `funnypot:geoip-refresh` refreshes that
    DB on the feed/freshness distribution seam. Off by default and blunt (R4). Separately, mirror/verdict
    rows may be **CIDR/ASN** ranges matched by **containment** via funnypot-policy (P2/Q2); E normalises
    IPv6 to its /64 `score_key` before lookup/report (P2) and re-implements no CIDR/ASN math.

## 9. Dependencies on other pieces

- **funnypot-policy (piece M) — hard, primary:** `require: metrictower/funnypot-policy`. E consumes
  `PolicyEngine`, `Decision`, `RequestEvidence`, `SiteProfile`, `Verdict`/`FakeResponse` (opaque via
  the `fakeHandle`), `ReportIntent`, `PolicyConfig::fromArray()`, and the port interfaces
  (`Funnypot\Policy\Port\{EvaluatorInterface, ReputationInterface, StateStoreInterface, GeoIpInterface,
  Clock, Logger}`). E implements the Laravel adapters for `StateStoreInterface`/`ReputationInterface`/
  `GeoIpInterface` (decision R)/`Clock`/`Logger` and injects the core-backed evaluator. E also relies on
  the policy's **CIDR/ASN containment matcher** for range/ASN mirror + verdict rows (P2/Q2) — E supplies
  the visitor IP, the policy owns the matching. Policy is framework-free PHP >= 7.3 with its own CI.
- **funnypot-core (via policy; direct require for the commands):** core provides the two-phase
  `classify()`+`synthesize()` engine (M2, behind the policy's `EvaluatorInterface`) plus, for the
  artisan commands, `Rules\RulesLocator`/`RulesUpdater`, `Http\Responder`, `Support\CorePaths`, and
  `bin/funnypot`. **Coordinated follow-up in core (blocks E v1 release):** remove `src/Laravel/*`,
  `config/funnypot.php`, `extra.laravel.providers`; keep the PSR-15 `Http\HoneypotMiddleware` +
  `Http\Responder` + `CorePaths`; trim README/INTEGRATION Laravel sections to point at this package.
- **mainnet-client (piece F): transitive, via policy/core.** E consumes F's `ReputationGate`/`Client`/
  `Cache`(`Psr16Cache`)/circuit-breaker inside the `MainnetReputation` adapter (mirror-first, then
  cache-first) and F's relocated `Funnypot\Mainnet\Reporter` inside `SendMainnetReport`. E adds no
  direct `require`. F owns the decision-N shared fail-open breaker (mechanism) + the code-aware 429
  branching; E consumes it on the warmer, `funnypot:mirror-sync`, and the report drain. F carries its
  own 7.3 CI lane.
- **Piece C (funnypot-core → PHP 7.3): sequencing only, no runtime dependency.** E tracks Laravel's PHP
  floor (8.0+). **But E's core-trim deletes `src/Laravel/*` from core**, so C must **exclude those files
  from its 7.3 conversion scope** — the bridge should be converted or deleted once, not both. Coordinate
  ordering with the C builder.
- **Piece A1 (mainnet-api): consumer relationship.** E's reporter, warmer, and `funnypot:mirror-sync`
  are clients of A1's `POST /v1/report` + `GET /v1/check` + `GET /v1/blacklist?variant=thin` (the O1
  mirror artifact). A1 issues E a **`sensor`-tier** `MAINNET_KEY` (O2 — report rights + an
  escalation-check quota; no public signup in v1). E surfaces `MAINNET_BASE_URL` + `MAINNET_KEY`; A1
  issues the key out of band and supplies the code-aware Error envelope + `Retry-After` the breaker
  reads (N2). The blacklist/thin-feed rows A1 serves may carry **CIDR/ASN `score_key`s** (P2/Q2/Q1),
  which E's mirror stores verbatim and matches by containment (via funnypot-policy).
- **Local GeoIP dataset (DB-IP Lite): a data-distribution dependency (decision R2).** E's country gate
  reads a **local** DB-IP Lite `mmdb`; `funnypot:geoip-refresh` refreshes it on the **same feed/freshness
  distribution seam** the O1 mirror rides (the dataset the dashboard + A1 enrichment already use). No
  per-request network call; the refresh is scheduled + queue-driver-safe like `funnypot:mirror-sync`.
- **Piece D (honeypot-wordpress): none direct.** Sibling adapter over the same `funnypot-policy` engine;
  shares the thin-adapter pattern but no code dependency.

## Review resolutions applied (2026-08-19)

- **D1** — Replaced the `MAINNET_HOST`/`endpoint` split with the canonical host-only `MAINNET_BASE_URL`
  (+ `MAINNET_KEY`) env pair; E appends `/v1/report` itself.
- **D2** — Base URL defaults to the mainnet placeholder host, never `https://api.abuseipdb.com`; the
  reporter is key-gated and inert without `MAINNET_KEY`, with mainnet as the shipped default.
- **D3** — `sensor_id`: a generated + persisted install UUID (via a `SensorId` cache helper, key
  `funnypot:sensor_id`, never a hardware id) sent on every report.
- **D7** — Server-observed `REMOTE_ADDR` via `$request->ip()`/`TrustProxies` is the v1 source IP;
  `X-Forwarded-For` only behind operator-configured trusted proxies; no `ip_header` knob.
- **M14** — Package PSR-4 root `Funnypot\ → src/` (mirroring core), tests under a separate
  `autoload-dev` root; the old `Funnypot\Laravel\ → src/` mapping breaks the testbench boot and is fixed
  before any file moves.
- **C coordination** — E's core-trim deletes core's `src/Laravel/*`, so piece C excludes those files
  from its 7.3 conversion scope; extraction-vs-conversion ordering called out so the bridge is handled
  once.
- **F (mainnet-client + reputation check)** — E depended on `metrictower/mainnet-client` transitively
  via core and added an OPTIONAL, off-by-default reputation check + block feature (verdict-first
  `block_verdicts` + optional `min_block_score`, replacing the deleted score `block_threshold`;
  `challenge_verdicts` defaults `[]`). **Superseded by decision M (see below):** the reputation-block
  feature is now a policy action/config, and the request-path reputation lookup is cache-first (M5).
- **K / L6** — the K bucketed-scoring reservations are the mainnet/policy's; L6's consumer overlay folds
  into the policy allowlist/blocklist (§5.1) rather than an E-local overlay.
- Ambiguity note (retained): D2's "mainnet by default, key-gated" vs. a `reporting.enabled=false`
  default — resolved by defaulting `reporting.enabled` on and treating an empty `MAINNET_KEY` as the
  true inert gate (matching `AbuseIpdb`'s skip-when-empty shape).

### M — position-blind engine + funnypot-policy (2026-08-19)

Re-pointed E from "a Laravel wrapper that calls core's `Engine::detect`/`respond` + wires F's
`ReputationGate` inline" to **"a thin Laravel adapter over `metrictower/funnypot-policy`"** (decision M;
canonical `funnypot-mainnet/docs/2026-08-19-program-decisions.md` §M, which wins). Substantive changes:

- **Primary dependency flip.** E now `require`s `funnypot-policy` (§2, §9); core + mainnet-client are
  transitive via policy (core stays a direct require only for the rules-update/compile commands).
- **Consume the `Decision`, don't compute it.** The header, §1, §2, §3 (diagram), and §4 are rewritten
  around `PolicyEngine::evaluate() → Decision{action,status,fakeHandle,pinTtl,report,reason}` executed by
  the adapter. The old `map → detect (attribute) → respond` middleware body becomes `normalize → evaluate
  → execute` (§4.2). `funnypot.detection` attribute → `funnypot.decision`.
- **Two positions (M4/M8).** Added the FALLBACK-position `FallbackResponder` (§4.2b) alongside the
  BEFORE-position middleware; posture (`honeypot|WAF|both`) + `position` are config knobs (§5, §8 #4).
- **`SiteProfile` + deterministic seed (M2).** Request normalization now builds a `SiteProfile` with a
  Laravel-router real-route oracle and derives the actor seed (§4.3, §8 #9) — the FP-safety and
  multi-step-coherence inputs the two-phase engine needs.
- **Port adapters are E's core new work (§4.7).** `LaravelStateStore`, `MainnetReputation` (cache-first),
  `LaravelClock`, `LaravelLogger` — E implements the policy's injected ports over Laravel cache / F /
  Laravel clock / the `funnypot` log channel.
- **Reputation reconciled with M5.** The old inline `ReputationGate::decide()` front-gate (F) is
  replaced on the request path by a **cache-first** `lookup` (never a synchronous network call); a fresh
  `Client::check` runs only in an out-of-band warmer. Reputation-block becomes the policy's opt-in
  verdict-keyed modifier, executed as a `Decision::BLOCK` (§4.7, §5 `check` block, §8 #5).
- **Reporting is delivery-only (§4.5).** The core `Observer` seam is gone from E's path; the policy emits
  a suppressed `ReportIntent` on the `Decision`, and E's queued `SendMainnetReport` just delivers it. The
  4-layer suppression / quorum / self_ips / SAFE_PATHS / OAST backstops move into the policy, backed by
  `LaravelStateStore`; E surfaces the numbers as `suppression`/`allowlist`/`self_ips` config (§5).
- **Config produces the policy array (§5).** `config/funnypot.php` is a Laravel front-end for
  `PolicyConfig::fromArray()`: `posture`/`position`/`actions`/`learn`/`pin`/`reputation`(check)/
  `suppression`/`allowlist`/`self_ips`/`state.cache_store`, plus the retained STYLE + `mainnet` +
  `rules` keys. The L6 overlay folds into the policy allowlist/blocklist (§5.1).
- **Retained unchanged:** the M14 PSR-4 root; the D1/D2/D3/D7 mechanics; the coordinated core-trim +
  namespace constraint (§8 #2); the rules-update RCE invariant; the Content-Type/status + no-500 +
  fingerprint-safety invariants (now largely delegated to the policy engine, §6); the fault-degradation
  belt-and-suspenders in the adapter.

### N / O + future-proofing review (2026-08-19)

Applied decisions **N** (global fail-open cooldown) + **O** (fleet-read model) and the future-proofing
review items **SF-5**, **O1**, **O2**, **N**, **RS-10** (canonical:
`funnypot-mainnet/docs/2026-08-19-program-decisions.md` §N/§O + `2026-08-19-futureproofing-review.md`).
Substantive changes:

- **SF-5 — sync-driver guard.** §2 + §4.5: on `QUEUE_CONNECTION=sync`, `SendMainnetReport` and the
  reputation warmer fall back to a local scheduler-drained queue (`funnypot:report-drain`) instead of
  running inline — never `dispatchAfterResponse()` (still pins the FPM worker) — with a one-time
  `funnypot`-channel warning. New security bullet (§6) + tests (§7): sync driver ⇒ **zero transport
  calls in-request**.
- **O1 — local-mirror-lite is the PRIMARY fresh-read.** §2 + §4.7: a scheduled `funnypot:mirror-sync`
  pulls the thin blacklist artifact (`GET /v1/blacklist?variant=thin`, ETag/304) into
  `LaravelStateStore`; `MainnetReputation.lookup` is now **mirror-first, then cache-first**, and only an
  uncertain IP escalates to an out-of-band per-IP `check`. New `mirror` config block (§5) + tests (§7).
- **O2 — sensor-tier key.** E's `MAINNET_KEY` is documented as a `sensor`-tier key (report rights +
  escalation-check quota, metered per-install) on the `mainnet.key` config (§5), the A1 dependency (§9),
  and §8 #13.
- **N — global fail-open cooldown/breaker.** §4.5/§4.7/§6: F owns the single shared `mnc:breaker` marker
  in the persistent `state.cache_store` (N1); two fault classes/clocks with code-aware 429 branching
  (N2 — `quota_exhausted` parks, `duplicate_report` drops, never loops); OPEN ⇒ warm/mirror/drain
  fast-skip (N3); drain-side budget + early-abort + queue caps (N6). New `breaker` config block with the
  canonical numbers (threshold 5 / cooldown 60 s / drain 10 s·3 / limit 200). `fail_mode=closed` never
  applies to inert/no-key/422 (SF-3). §8 #14 + breaker/drain tests (§7).
- **RS-10 — selectable local-state backend.** §5 `state.cache_store` note: the backend is operator-
  selectable (redis/database/memcached/file/array) and the default MUST work where the package directory
  is read-only / multi-node — E never assumes a writable filesystem or a per-node file.

### P/Q/R — entity + geo (2026-08-19)

Applied decisions **P** (IPv6 hardening), **Q** (range/CIDR/ASN reputation), **R** (country policy via
LOCAL GeoIP) from the canonical `funnypot-mainnet/docs/2026-08-19-program-decisions.md` §P/§Q/§R.
Substantive changes:

- **R — country policy (E-owned config + a GeoIp port + a local-DB refresh).** §5: a new `country` block
  (deny-list OR allow-list posture; action block/deceive/**modifier**, default modifier; ISO-3166 list —
  R1/R3) and a `geoip` block (local DB-IP Lite path + a scheduled refresh — R2). §4.7: a new
  `LaravelGeoIp implements Funnypot\Policy\Port\GeoIpInterface` adapter that resolves the country from a
  **LOCAL** GeoIP DB (socket-free, IPv6-capable, fail-open on a miss — R2/M5), plus a
  `Funnypot\Laravel\Geo\GeoIpRefresh` + `funnypot:geoip-refresh` command carrying the local-GeoIP-DB
  distribution + refresh (rides the feed/freshness seam — R2). §6 security bullet (local + fail-safe +
  blunt-so-off-by-default — R3/R4). §8 #15. §9 gains the DB-IP Lite data-distribution dependency. E
  supplies the country only; the deny/allow decision + the block/deceive/modifier action live in the
  policy (R1/M5), keyed from E's config.
- **P2/Q2 — range/ASN mirror rows matched by containment.** §4.7: `MainnetReputation.lookup` matches the
  visitor by **CIDR-containment / ASN-lookup, never exact-match**, delegating to funnypot-policy's
  matcher; `MirrorSync`'s thin rows may carry a **CIDR or ASN `score_key`** (P2/Q2/Q1). E normalises an
  IPv6 to its /64 `score_key` before the mirror lookup and before reporting (P2). §5 bullet, §8 #15, §9
  (policy containment matcher + A1 range/ASN feed rows). E re-implements no CIDR/ASN math — the matcher
  is the policy's.
- **P (v6) / Q (auto-rollup, range allowlist) server-side items are A1/policy's**, not E's; E's only v6
  obligation is the /64 normalisation-before-lookup/report + containment matching noted above.
