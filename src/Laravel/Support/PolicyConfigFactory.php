<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Support;

/**
 * Translate the Laravel `funnypot.*` config into the policy config array
 * (Funnypot\Policy\PolicyConfig::fromArray). This is pure data reshaping — no decision logic. E's
 * config vocabulary (country posture `denylist|allowlist`, the `check` block) is mapped onto the
 * policy's vocabulary (`country.mode deny|allow`, the `reputation` block) so the SAME structure the
 * WordPress adapter emits is produced here.
 */
final class PolicyConfigFactory
{
    /**
     * @param array<string,mixed> $c the `funnypot` config array
     * @return array<string,mixed>   the policy config array
     */
    public static function build(array $c): array
    {
        return [
            'posture'  => $c['posture'] ?? 'honeypot',
            'position' => self::position($c),
            'actions' => [
                'clean'         => $c['actions']['clean'] ?? 'allow',
                'suspicious'    => $c['actions']['suspicious'] ?? 'log',
                'attack_class'  => $c['actions']['attack_class'] ?? 'block',
                'scanner_probe' => $c['actions']['scanner_probe'] ?? 'deceive',
            ],
            // `check` (E) → `reputation` (policy). A truthy `as_primary` is ignored by the policy.
            'reputation' => [
                'enabled'         => (bool) ($c['check']['enabled'] ?? false),
                'block_verdicts'  => array_values((array) ($c['check']['block_verdicts'] ?? ['malicious', 'critical'])),
                'min_block_score' => $c['check']['min_block_score'] ?? null,
            ],
            'learn' => [
                'shadow_days'       => (int) ($c['learn']['shadow_days'] ?? 7),
                'shadow_min_reqs'   => (int) ($c['learn']['shadow_min_reqs'] ?? 5000),
                'baseline_excluded' => array_values((array) ($c['learn']['baseline_excluded'] ?? [])),
                'kill_switch'       => (bool) ($c['learn']['kill_switch'] ?? false),
            ],
            'country' => self::country($c['country'] ?? []),
            'bot_signals' => [
                'enabled'      => (bool) ($c['bot_signals']['enabled'] ?? true),
                'exempt_uas'   => array_values((array) ($c['bot_signals']['exempt_uas'] ?? [])),
                'exempt_paths' => array_values((array) ($c['bot_signals']['exempt_paths'] ?? [])),
                'telemetry'    => (bool) ($c['bot_signals']['telemetry'] ?? false),
            ],
            'pin' => ['ttl_seconds' => (int) ($c['pin']['ttl_seconds'] ?? 3600)],
            'suppression' => (array) ($c['suppression'] ?? []),
            'allowlist' => [
                'ips'        => array_values((array) ($c['allowlist']['ips'] ?? [])),
                'cidrs'      => array_values((array) ($c['allowlist']['cidrs'] ?? [])),
                'asns'       => array_values((array) ($c['allowlist']['asns'] ?? [])),
                'safe_paths' => array_values((array) ($c['allowlist']['safe_paths'] ?? [])),
            ],
            // The operator's own egress IPs are surfaced under `reporting.self_ips` in E's config.
            'self_ips' => array_values((array) ($c['reporting']['self_ips'] ?? [])),
        ];
    }

    /**
     * Resolve the active positions: the posture preset seeds them (honeypot → fallback; WAF → before;
     * both → before+fallback), and an EXPLICIT operator override (a non-null position knob) wins per
     * field. A null knob means "inherit the posture preset" — so choosing a posture is enough.
     *
     * @param array<string,mixed> $c
     * @return array{before:bool,fallback:bool}
     */
    private static function position(array $c): array
    {
        $preset = match ($c['posture'] ?? 'honeypot') {
            'WAF'  => ['before' => true, 'fallback' => false],
            'both' => ['before' => true, 'fallback' => true],
            default => ['before' => false, 'fallback' => true],
        };

        $pos = (array) ($c['position'] ?? []);
        if (array_key_exists('before', $pos) && $pos['before'] !== null) {
            $preset['before'] = (bool) $pos['before'];
        }
        // Adapter boundary: E's config names the 404 position `not_found`; the policy position is `fallback`.
        if (array_key_exists('not_found', $pos) && $pos['not_found'] !== null) {
            $preset['fallback'] = (bool) $pos['not_found'];
        }

        return $preset;
    }

    /**
     * Map E's country block onto the policy country block. `posture` denylist|allowlist becomes `mode`
     * deny|allow; `list` becomes `countries`; `action` + `modifier` pass through.
     *
     * @param array<string,mixed> $co
     * @return array<string,mixed>
     */
    private static function country(array $co): array
    {
        $posture = strtolower((string) ($co['posture'] ?? 'denylist'));
        $mode = $posture === 'allowlist' ? 'allow' : 'deny';

        return [
            'enabled'   => (bool) ($co['enabled'] ?? false),
            'mode'      => $mode,
            'countries' => array_values(array_map('strtoupper', array_map('strval', (array) ($co['list'] ?? [])))),
            'action'    => (string) ($co['action'] ?? 'modifier'),
            'modifier'  => (int) ($co['modifier'] ?? 25),
        ];
    }
}
