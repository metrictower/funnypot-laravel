# metrictower/funnypot-laravel

> **Not sure you're in the right place?**
> - Want a ready-to-run **honeypot box** to deploy → [funnypot-app](https://github.com/metrictower/funnypot-app)
> - Protecting a **Laravel** app → funnypot-laravel **← you are here**
> - Protecting a **WordPress** site → [funnypot-wordpress](https://github.com/metrictower/funnypot-wordpress)
> - Detection **and** IP reporting in any PHP app, batteries included → [funnypot](https://github.com/metrictower/funnypot)
> - Embedding the deception/detection **engine** in your own PHP / PSR-15 app → [funnypot-core](https://github.com/metrictower/funnypot-core)
> - Querying / reporting to the **IP-reputation service** from code (the SDK) → [funnypot-mainnet-client](https://github.com/metrictower/funnypot-mainnet-client)
> - Building on the low-level **decision/policy engine** → [funnypot-policy](https://github.com/metrictower/funnypot-policy)

A first-class Laravel package (piece **E**) that drops the funnypot deception stack into any Laravel app
as a **thin adapter over [`metrictower/funnypot-policy`](../funnypot-policy)** — the position-blind
decision engine. This package owns **no** decision logic: it normalises the request, asks the
`PolicyEngine` for a `Decision`, and executes it (allow / log / block / deceive). The exact same
decision matrix, learn-then-enforce machine, pin/TTL, and report suppression run identically here, in
the WordPress adapter, and in the standalone app — because they all live in the policy engine.

```
Request ─► HoneypotMiddleware (BEFORE)  ─┐
                                         ├─► LaravelRequestMapper → PolicyEngine::evaluate() → Decision
       404 ─► FallbackResponder (FALLBACK)┘        │
                                                    ▼  execute: allow → $next · log → $next(+record)
                                                       block → honest 403 · deceive → core's byte-exact fake
```

## What it wires

E supplies the Laravel implementations of the policy's injected **ports** and bridges the real runtime
pieces:

| Policy port | E adapter | Bridges to |
|---|---|---|
| `EvaluatorInterface` | `Ports\CoreEvaluator` | funnypot-core's two-phase `classify()` + `synthesize()` |
| `ReputationInterface` | `Ports\MainnetReputation` | mainnet-client `Client::cachedVerdict()` (cache-first, no socket) |
| `StateStoreInterface` | `Ports\LaravelStateStore` | a Laravel cache store (pins, blocklist, O1 mirror, suppression ledger) |
| `GeoIpInterface` | `Ports\LaravelGeoIp` | a **local** GeoIP DB (mmdb / JSON map), never a network call |
| `Clock` / `Logger` | `Ports\LaravelClock` / `Ports\LaravelLogger` | Laravel clock / the `funnypot` log channel |

## Install

```bash
composer require metrictower/funnypot-laravel
```

This pulls the three sibling packages E adapts — `funnypot-core`, `funnypot-policy`, and
`funnypot-mainnet-client`. The service provider is auto-discovered (`extra.laravel.providers`).
Publish the config:

```bash
php artisan vendor:publish --tag=funnypot-config
```

Add a `funnypot` log channel to `config/logging.php`:

```php
'funnypot' => ['driver' => 'single', 'path' => storage_path('logs/funnypot.log'), 'level' => 'debug'],
```

## Registering the two positions

The package does **not** force itself into the kernel — the app opts in.

**NOT_FOUND position (the default honeypot 404 hook).** Wire the responder as your fallback route (add
it last, after your real routes), in `routes/web.php`:

```php
use Funnypot\Laravel\FallbackResponder;

Route::fallback([FallbackResponder::class, 'handle']);
```

Every unmatched path (a scanner probing `/wp-login.php`, `/.env`, …) is now upgraded from a 404 to a
byte-exact fake — FP-free by construction, because the counterfactual was already a 404.

**BEFORE position (WAF / observe).** Push the `funnypot` middleware alias onto a group or the global
stack. In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', \Funnypot\Laravel\HoneypotMiddleware::class);
    // or: $middleware->alias(['funnypot' => \Funnypot\Laravel\HoneypotMiddleware::class]);
})
```

Which positions the *engine evaluates* is a **config choice** (`posture`), never a code change.

## Enforcement modes (enforce / observe / off)

Independently of whether a position is active, `enforcement` decides — per position — whether the
adapter **performs** funnypot's decision or merely **watches** it. Values (`Funnypot\Laravel\Enforcement`):

| mode | behaviour when the executor runs |
|---|---|
| `enforce` | serve the fake / the block (the response-owning behaviour) |
| `observe` | detect **+ report** + log the withheld action, then pass through — *your app* owns the response |
| `off` | short-circuit; never evaluate (a per-position kill switch) |

```php
'enforcement' => [
    'before'    => Funnypot\Laravel\Enforcement::OBSERVE,  // default: watch real traffic, never block on install
    'not_found' => Funnypot\Laravel\Enforcement::ENFORCE,  // default: deceive 404s (no real-user downside)
],
```

Defaults are **safe-by-default**: a fresh install watches + logs on the before position and never
silently starts blocking real traffic (ModSecurity `DetectionOnly` / AWS WAF "Count first"). Reporting
fires in **every** mode when the engine judged the request malicious — `observe` withholds only the
*response*, never the report. A withheld block/deceive is logged at `enforcement_log_level` (default
`warning`).

## Detection only — for apps that own their response

An app that already owns its response (its own 404 handler, honeypot, or WAF) calls detection directly.
`use Funnypot\Laravel\Facades\Funnypot;` and pick a tier.

> **This facade is caller-decides — it is NOT governed by the `enforcement` config.** `enforcement.before`
> / `enforcement.not_found` (enforce / observe / off) gate the *installed* `HoneypotMiddleware` and
> `FallbackResponder` — the positions funnypot owns. When YOU call the facade from your own handler, YOU
> pick the mode: `handleRequest()`/`toResponse()` are the **enforce** action (funnypot serves the fake/block);
> for **observe** (detect + report, but serve your own response) use `inspectRequest()` and act on
> `isSuspicious()` yourself without calling `toResponse()`. `handleRequest()` will serve regardless of any
> `enforcement.*` value — so do not wire it into a position you are shadow-testing in `observe`.

**One line — detect and respond (enforce):**
```php
return Funnypot::handleRequest($request) ?? $myOwn404;
```
Returns funnypot's byte-exact fake (deceive) or an honest block for a probe, and `null` when the request
is clean (you serve your own). In Laravel you **RETURN** it — never `die()`. (`handleRequest($request, $die = true)`
echoes+exits, for raw-PHP entry points with no framework to return into.)

**Or take control with the result:**
```php
$result = Funnypot::inspectRequest($request);
if ($result->isSuspicious()) {
    return $result->toResponse();   // funnypot's fake / block, or null if nothing to serve
}
// $result->action(), $result->reason(), $result->decision() are there when you need them
```

`inspectRequest()` never serves a response and never throws onto the request path. A clean/allow verdict
or a detection fault is `isClean()` with a `null` `toResponse()` — treat "clean" as "no opinion", never a
guarantee the request is safe. (The raw `Funnypot::inspect($request): ?Decision` is still available for
callers wired to the policy object directly.)

## Configuration (highlights)

`config/funnypot.php` is a Laravel front-end that produces the policy config array. The full file is
commented; the load-bearing knobs:

- **`posture`** — `honeypot` (not_found deceive, default) · `WAF` (before-position block) · `both`. The
  posture selects which `position`s the engine evaluates; a `FUNNYPOT_POSITION_*` env overrides per
  field (`FUNNYPOT_POSITION_BEFORE`, `FUNNYPOT_POSITION_NOT_FOUND`).
- **`enforcement`** — per position (`before`, `not_found`): `enforce` · `observe` · `off`. Whether the
  adapter performs the decision or only watches + reports it (see *Enforcement modes* above). Defaults
  `before=observe`, `not_found=enforce`. Env: `FUNNYPOT_ENFORCE_BEFORE`, `FUNNYPOT_ENFORCE_NOT_FOUND`,
  `FUNNYPOT_ENFORCE_LOG_LEVEL`.
- **`response_style`** — `realistic` (default) `| minimal | taunt` (how a fake *looks*; passed into core).
  `realistic` renders a believable, template-matching fake (e.g. a phpMyAdmin skin); `minimal` is a bland
  generic body.
- **`mainnet.base_url` / `MAINNET_KEY`** — the reputation/report service. `base_url` is **host only**
  (the reporter appends `/v1/report`). An empty key makes the reporter, the reputation check, and
  mirror-sync all **inert**. Defaults to the mainnet placeholder host, never AbuseIPDB.
- **`check.enabled`** (default **off**) — the opt-in reputation gate. Spends credits and sends the
  visitor IP to a third party (GDPR). The request path is mirror-first, then F-cache-first — **never a
  synchronous network call** (M5); a fresh check runs only out-of-band.
- **`mirror`** (O1) — `funnypot:mirror-sync` pulls the thin blacklist artifact on cron into the local
  mirror; fleet reads scale as CDN egress, not origin QPS.
- **`country` / `geoip`** (default **off**) — an optional cheap-static country gate over a **local**
  GeoIP DB. Blunt (VPN/CGNAT), so the default action is a suspicion *modifier*, never a lone-signal
  deceive. E supplies the country; the policy decides.
- **`reporting.self_ips`** — your own egress/test IPs, never self-scored/reported. **List your scan-box
  IP before enabling reporting from a host that also runs scans.**
- **`state.cache_store`** — the Laravel cache store backing all local state (pins, mirror, suppression,
  breaker). Use a persistent, multi-node-safe store (redis / database / memcached), not a per-node file.

## Artisan commands

| Command | Purpose |
|---|---|
| `funnypot:rules-update` | Fetch + verify + hot-swap a signed rules release (RCE-safe: ed25519 + sha256 + array-literal validation, all in core). |
| `funnypot:update <templates>` | Recompile the template index via core's `bin/funnypot compile` (subprocess). |
| `funnypot:mirror-sync` | Pull the thin blacklist artifact into the local reputation mirror (O1). |
| `funnypot:report-drain` | Deliver report rows parked by the sync-driver guard (SF-5), with a wall-clock budget. |

Schedule (in `routes/console.php` or the console kernel):

```php
Schedule::command('funnypot:mirror-sync')->hourly();
Schedule::command('funnypot:report-drain')->everyFiveMinutes();
Schedule::command('funnypot:rules-update')->daily();
```

## Safety invariants

- **The engine only ever *upgrades* a 404, never a 500.** Every mapper/port/evaluate fault degrades to
  pass-through (or the app's own 404). A 500 is itself a tell.
- **Content-Type matches the request; status is app-chosen.** The response mapper copies the fake's
  status + Content-Type + headers verbatim (with a CRLF/NUL header-injection guard). No model-driven 3xx.
- **Reputation fails open**, never self-blocks, never a synchronous network call on the request path.
- **Reporting is key-gated and fingerprint-safe.** The `comment` is a fixed generic string; categories
  are coarse numeric tokens — never a nuclei matcher word / CRS rule id / ModSecurity marker.
- **SF-5 sync-driver guard.** On `QUEUE_CONNECTION=sync`, report delivery is parked for
  `funnypot:report-drain` instead of running inline — a mainnet outage never pins an FPM worker.

## Tests

```bash
php vendor/bin/phpunit
```

Runs on `orchestra/testbench`. E's tests prove the **adapter**: it normalises the request, executes each
`Decision`, and wires core + policy + mainnet-client through the ports (an end-to-end scanner-probe →
core-built fake; a cache-primed reputation verdict returned with zero network calls; the sync-driver
guard keeping the POST off the request path).

## v1 scope notes

- The out-of-band reputation **warmer** and the **GeoIP DB refresh** command (`funnypot:geoip-refresh`)
  are documented in the design but not shipped in v1; the request path never needs them (it reads the
  mirror + F cache + the local GeoIP DB). The `funnypot:mirror-sync` shape is the template for both.
- `Ports\LaravelGeoIp` reads a MaxMind/DB-IP `.mmdb` when the `MaxMind\Db\Reader` library is installed,
  and a `.json` `{ip-or-CIDR: "CC"}` map otherwise (simple installs + tests).
- The mainnet **breaker** (decision N) is F's mechanism, shared via the state cache; `funnypot:report-drain`
  carries the drain-side budget. Full breaker gating on `mirror-sync` is a fast-follow.
