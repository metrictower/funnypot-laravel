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

**FALLBACK position (the default honeypot 404 hook).** Wire the responder as your fallback route (add it
last, after your real routes), in `routes/web.php`:

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

Which positions are active is a **config choice** (`posture`), never a code change.

## Configuration (highlights)

`config/funnypot.php` is a Laravel front-end that produces the policy config array. The full file is
commented; the load-bearing knobs:

- **`posture`** — `honeypot` (fallback deceive, default) · `WAF` (before-position block) · `both`. The
  posture selects the active `position`s; a `FUNNYPOT_POSITION_*` env overrides per field.
- **`response_style`** — `minimal | realistic | taunt` (how a fake *looks*; passed into core).
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
