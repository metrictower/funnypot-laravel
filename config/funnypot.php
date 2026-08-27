<?php

/**
 * funnypot-laravel — published config (design §5).
 *
 * This file is a Laravel front-end that PRODUCES the policy config array (owned by funnypot-policy).
 * The provider maps these keys onto Funnypot\Policy\PolicyConfig::fromArray(); E owns no decision logic.
 *
 * Defaults are INERT / FP-free by construction: the fallback (404) position deceives, the before
 * position is off, reputation + reporting + country are off until the operator opts in AND sets a key.
 */
return [

    // --------------------------------------------------------------------------------------------
    // STYLE — how a fake LOOKS (synthesis config passed straight into core via the evaluator).
    // --------------------------------------------------------------------------------------------
    'response_style'  => env('FUNNYPOT_STYLE', 'minimal'), // minimal | realistic | taunt
    'persona_seed'    => env('FUNNYPOT_PERSONA_SEED', null),
    'persona_breadth' => env('FUNNYPOT_PERSONA_BREADTH', 'coherent'),
    'severity_ceiling' => env('FUNNYPOT_SEVERITY_CEILING', 'high'),
    'max_body_bytes'  => (int) env('FUNNYPOT_MAX_BODY_BYTES', 65536),
    'latency_ms'      => (int) env('FUNNYPOT_LATENCY_MS', 0),
    // The deterministic actor seed salt. Defaults to APP_KEY so a coherent multi-step fake is stable
    // per install without leaking a shared constant across installs.
    'seed_salt'       => env('FUNNYPOT_SEED_SALT', null),

    // Signed rules-update (orthogonal to the policy engine; feeds core's classify()/synthesize()).
    'rules' => [
        // Point rule resolution at a RulesUpdater-managed release. Guidance: a dedicated non-web user,
        // 0755 dirs / 0644 files, OUTSIDE the web root. Unset = the bundled floor.
        'data_dir'              => env('FUNNYPOT_RULES_DATA_DIR', null),
        'channel'               => env('FUNNYPOT_RULES_CHANNEL', 'stable'),
        'pinned_version'        => env('FUNNYPOT_RULES_PINNED', null),
        'repo'                  => env('FUNNYPOT_RULES_REPO', 'https://github.com/metrictower/funnypot-rules'),
        'staleness_alarm_hours' => (int) env('FUNNYPOT_RULES_STALENESS_HOURS', 0),
    ],

    // --------------------------------------------------------------------------------------------
    // POSTURE / POSITION / DECISION MATRIX  (→ the policy config array).
    // --------------------------------------------------------------------------------------------
    'posture'  => env('FUNNYPOT_POSTURE', 'honeypot'), // honeypot | WAF | both (preset selector)
    // Position knobs OVERRIDE the posture preset per field. null (unset) = inherit the preset, so
    // choosing a posture is enough (honeypot → not_found; WAF → before; both → both). `not_found` is the
    // 404 position (was `fallback`); it maps to the policy's fallback position at the adapter boundary.
    'position' => [
        'before'    => (($v = env('FUNNYPOT_POSITION_BEFORE')) === null || $v === '') ? null : (bool) $v,
        'not_found' => (($v = env('FUNNYPOT_POSITION_NOT_FOUND')) === null || $v === '') ? null : (bool) $v,
    ],

    // ENFORCEMENT — adapter-layer, per position: does the executor PERFORM the engine's decision or
    // merely OBSERVE it. Orthogonal to `position` (which decides whether the engine evaluates at all).
    //   off     — short-circuit; never evaluate (a per-position kill switch)
    //   observe — detect + report + log the withheld action, then pass through; the app owns the response
    //   enforce — serve the fake / the block
    // Safe-by-default: `before` OBSERVEs (watch real traffic, never block on install); `not_found`
    // ENFORCEs (deceiving a 404 has no real-user downside). See Funnypot\Laravel\Enforcement.
    'enforcement' => [
        'before'    => env('FUNNYPOT_ENFORCE_BEFORE', \Funnypot\Laravel\Enforcement::OBSERVE),
        'not_found' => env('FUNNYPOT_ENFORCE_NOT_FOUND', \Funnypot\Laravel\Enforcement::ENFORCE),
    ],
    // Log level for an OBSERVE-withheld block/deceive (the engine judged it malicious; we watched).
    'enforcement_log_level' => env('FUNNYPOT_ENFORCE_LOG_LEVEL', 'warning'),
    // Per-band action ceiling on REAL routes (policy §5 ladder). Sacrificial / 404-counterfactual paths
    // are governed by the day-1 carve-out and always may deceive.
    'actions' => [
        'clean'         => env('FUNNYPOT_ACTION_CLEAN', 'allow'),
        'suspicious'    => env('FUNNYPOT_ACTION_SUSPICIOUS', 'log'),
        'attack_class'  => env('FUNNYPOT_ACTION_ATTACK', 'block'),
        'scanner_probe' => env('FUNNYPOT_ACTION_PROBE', 'deceive'),
    ],
    'learn' => [
        'shadow_days'       => (int) env('FUNNYPOT_SHADOW_DAYS', 7),
        'shadow_min_reqs'   => (int) env('FUNNYPOT_SHADOW_MIN_REQS', 5000),
        'baseline_excluded' => [],
        'kill_switch'       => (bool) env('FUNNYPOT_KILL_SWITCH', false),
    ],
    'pin' => ['ttl_seconds' => (int) env('FUNNYPOT_PIN_TTL', 3600)],

    // The paths the operator declares provably-absent on this app (day-1 auto-enforced deception when
    // the router has no matching route). Exact paths (a WordPress fake on a Laravel app: /wp-login.php).
    'sacrificial_paths' => [],

    // --------------------------------------------------------------------------------------------
    // MAINNET — the canonical address + key (D1). base_url is HOST ONLY (the reporter appends
    // /v1/report). key gates the reporter (D2) AND the reputation check + mirror-sync (F).
    // --------------------------------------------------------------------------------------------
    'mainnet' => [
        // NEVER the real AbuseIPDB host. Empty key => reporter + check + mirror-sync all inert.
        'base_url' => env('MAINNET_BASE_URL', 'https://api.mainnet.example'),
        // Operator-issued SENSOR-tier key (O2): report rights + an escalation-check quota, metered
        // per-install (sensor_id / server-observed source_ip). sensor_id is NOT env-configured
        // (generated + persisted on first run).
        'key'      => env('MAINNET_KEY', ''),
    ],

    // --------------------------------------------------------------------------------------------
    // CHECK — the inbound reputation gate (decision F), now a policy `reputation` action/config.
    // Off by default; opt-in spends credits + sends the visitor IP to a third party (GDPR). The
    // request-path lookup is mirror-first, then F-cache-first — never a synchronous network call (M5).
    // --------------------------------------------------------------------------------------------
    'check' => [
        'enabled'         => (bool) env('FUNNYPOT_CHECK_ENABLED', false),
        'block_verdicts'  => array_values(array_filter(array_map('trim',
                                 explode(',', (string) env('FUNNYPOT_CHECK_BLOCK_VERDICTS', 'malicious,critical'))))),
        'min_block_score' => (($v = env('FUNNYPOT_CHECK_MIN_BLOCK_SCORE')) === null || $v === '')
                                 ? null : (int) $v,
        'cache_ttl_hours' => (int) env('FUNNYPOT_CHECK_CACHE_TTL_HOURS', 12),
        'fail_mode'       => env('FUNNYPOT_CHECK_FAIL_MODE', 'open'), // 'open' (default) | 'closed'
        'timeout_ms'      => (int) env('FUNNYPOT_CHECK_TIMEOUT_MS', 1500), // out-of-band warmer only (M5)
    ],

    // O1 local-mirror-lite: the PRIMARY fresh-read. funnypot:mirror-sync pulls the thin blacklist
    // artifact on cron (conditional GET, ETag/304) into the state store; per-IP check is escalation-only.
    'mirror' => [
        'enabled'      => (bool) env('FUNNYPOT_MIRROR_ENABLED', true),
        'variant'      => env('FUNNYPOT_MIRROR_VARIANT', 'thin'),        // thin {ip,verdict,expires_at} | full
        'sync_minutes' => (int) env('FUNNYPOT_MIRROR_SYNC_MINUTES', 60), // ~24 pulls/day; CDN + 304-friendly
    ],

    // Decision N shared fail-open breaker + drain budget (F owns the mechanism; these are E's knobs).
    'breaker' => [
        'threshold_transport' => (int) env('FUNNYPOT_BREAKER_THRESHOLD', 5),
        'cooldown_secs'       => (int) env('FUNNYPOT_BREAKER_COOLDOWN', 60),
        'quota_park_cap_secs' => (int) env('FUNNYPOT_BREAKER_QUOTA_CAP', 21600),
        'drain_budget_secs'   => (int) env('FUNNYPOT_DRAIN_BUDGET', 10),
        'drain_max_fails'     => (int) env('FUNNYPOT_DRAIN_MAX_FAILS', 3),
        'drain_limit'         => (int) env('FUNNYPOT_DRAIN_LIMIT', 200),
    ],

    // --------------------------------------------------------------------------------------------
    // REPORTING — delivery + self-guard (the SUPPRESSION lives in the policy, backed by the store).
    // --------------------------------------------------------------------------------------------
    'reporting' => [
        'enabled'    => (bool) env('FUNNYPOT_REPORT_ENABLED', true), // inert without mainnet.key
        // The operator's own egress/test IPs — never self-score / self-report. List your scan-box IP
        // BEFORE enabling reporting from a host that also runs scans.
        'self_ips'   => array_values(array_filter(array_map('trim', explode(',', (string) env('FUNNYPOT_SELF_IPS', ''))))),
        'queue'      => env('FUNNYPOT_REPORT_QUEUE', null),
        // SF-5: if the resolved queue connection is `sync`, delivery falls back to a local
        // scheduler-drained queue (funnypot:report-drain), never inline / never dispatchAfterResponse().
        'categories' => [21], // fallback category ids when the verdict has no finer map
    ],
    'suppression' => [ // → policy §9 (the 4-layer model); backed by the state store.
        'verdict_dedup_hours' => (int) env('FUNNYPOT_SUP_DEDUP_HOURS', 24),
        'per_ip_alert_cap'    => (int) env('FUNNYPOT_SUP_IP_CAP', 100),
        'per_ip_cap_window_s' => (int) env('FUNNYPOT_SUP_IP_CAP_WINDOW', 600),
        'buffer_ttl_s'        => (int) env('FUNNYPOT_SUP_BUFFER_TTL', 900),
        'score_gate'          => (int) env('FUNNYPOT_SUP_SCORE_GATE', 200),
        'aggregate'           => ['min_sources' => 2, 'min_total_score' => 200, 'window_days' => 90],
        'decay'               => ['base_ttl_s' => 600, 'cap_ttl_s' => 86400,
                                  'inc_soft' => 1, 'inc_medium' => 10, 'inc_hard' => 100],
    ],
    'allowlist' => [ // → policy allowlist (hard override, re-checked at every mutating point).
        'ips'        => array_values(array_filter(array_map('trim', explode(',', (string) env('FUNNYPOT_ALLOW_IPS', ''))))),
        'cidrs'      => array_values(array_filter(array_map('trim', explode(',', (string) env('FUNNYPOT_ALLOW_CIDRS', ''))))),
        'asns'       => array_values(array_filter(array_map('trim', explode(',', (string) env('FUNNYPOT_ALLOW_ASNS', ''))))),
        'safe_paths' => [], // isIgnoredUri set — health checks, the app's own asset paths
    ],

    // R country policy: an optional cheap-static country gate (after allowlist/pin, before
    // reputation/content). Off by default; blunt (VPN/CGNAT/roaming/cloud egress), so an eyes-open
    // opt-in. The country is resolved LOCALLY (no network call — R2) via the geoip block below.
    'country' => [
        'enabled'  => (bool) env('FUNNYPOT_COUNTRY_ENABLED', false),
        'posture'  => env('FUNNYPOT_COUNTRY_POSTURE', 'denylist'), // denylist | allowlist (stricter, higher-FP)
        'action'   => env('FUNNYPOT_COUNTRY_ACTION', 'modifier'),  // modifier (default) | deceive | block
        'modifier' => (int) env('FUNNYPOT_COUNTRY_MODIFIER', 25),
        'list'     => array_values(array_filter(array_map('strtoupper', array_map('trim',
                          explode(',', (string) env('FUNNYPOT_COUNTRY_LIST', '')))))),
    ],
    // R2 LOCAL GeoIP DB: reuse DB-IP Lite / GeoLite2 mmdb. The country is resolved from this LOCAL DB —
    // NEVER a network call on the request path (M5). funnypot:geoip-refresh refreshes it on the feed seam.
    'geoip' => [
        'enabled'  => (bool) env('FUNNYPOT_GEOIP_ENABLED', false),
        'database' => env('FUNNYPOT_GEOIP_DB', null), // path to the local mmdb; null = packaged default
        'refresh'  => [
            'enabled' => (bool) env('FUNNYPOT_GEOIP_REFRESH_ENABLED', true),
            'days'    => (int) env('FUNNYPOT_GEOIP_REFRESH_DAYS', 30),
        ],
    ],

    // RS-10 selectable local-state backend. Names any configured Laravel cache store
    // (redis/database/memcached/file/array); null = the app default. It backs the StateStore, the
    // reputation cache, the O1 mirror, the sync-driver report queue, and the breaker marker. The chosen
    // store MUST work where the package directory is read-only / multi-node (a DB or shared object
    // cache, not a per-node file) — E never writes local state into its own package dir.
    'state' => ['cache_store' => env('FUNNYPOT_STATE_CACHE_STORE', null)],

    // Bot-signal request-shape scrutiny (decision S/T). Opt-in telemetry is off by default.
    'bot_signals' => [
        'enabled'      => (bool) env('FUNNYPOT_BOT_SIGNALS', true),
        'exempt_uas'   => [],
        'exempt_paths' => [],
        'telemetry'    => (bool) env('FUNNYPOT_BOT_TELEMETRY', false),
    ],
];
