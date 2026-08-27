<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Core\Config as CoreConfig;
use Funnypot\Core\Honeypot;
use Funnypot\Laravel\Console\MirrorSyncCommand;
use Funnypot\Laravel\Console\ReportDrainCommand;
use Funnypot\Laravel\Console\RulesUpdateCommand;
use Funnypot\Laravel\Console\UpdateTemplatesCommand;
use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Ports\CoreEvaluator;
use Funnypot\Laravel\Ports\LaravelClock;
use Funnypot\Laravel\Ports\LaravelGeoIp;
use Funnypot\Laravel\Ports\LaravelLogger;
use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Ports\MainnetReputation;
use Funnypot\Laravel\Reputation\ClientFactory;
use Funnypot\Laravel\Reporting\LocalReportQueue;
use Funnypot\Laravel\Reporting\ReportDispatcher;
use Funnypot\Policy\Port\EvaluatorInterface;
use Funnypot\Policy\Port\GeoIpInterface;
use Funnypot\Policy\Port\ReputationInterface;
use Funnypot\Policy\Port\StateStoreInterface;
use Funnypot\Policy\PolicyConfig;
use Funnypot\Policy\PolicyEngine;
use Funnypot\Core\Rules\RulesLocator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * The Laravel bridge (design §4.1). Merges + publishes config, builds the policy config array, wires
 * the PolicyEngine singleton to E's Laravel port adapters + the core-backed evaluator, and registers
 * the commands + the `funnypot` middleware alias. Auto-discovered via extra.laravel.providers.
 *
 * The package does NOT force itself into the kernel: the app opts in by using the `funnypot` middleware
 * alias and/or wiring FallbackResponder as its Route::fallback().
 */
final class FunnypotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/funnypot.php', 'funnypot');

        $dataDir = $this->app['config']->get('funnypot.rules.data_dir');
        if (is_string($dataDir) && $dataDir !== '') {
            RulesLocator::useDataDir($dataDir);
        }

        // The single state cache store backs the StateStore, the reputation cache, the O1 mirror, the
        // sync-driver report queue, and the breaker marker (RS-10).
        $this->app->singleton('funnypot.cache', static function ($app): Repository {
            $store = $app['config']->get('funnypot.state.cache_store');
            return $store ? Cache::store($store) : Cache::store();
        });

        // --- policy ports ---
        $this->app->singleton(LaravelClock::class);
        $this->app->singleton(LaravelLogger::class);

        $this->app->singleton(StateStoreInterface::class, static fn ($app) => new LaravelStateStore(
            $app->make('funnypot.cache'),
            $app->make(LaravelClock::class)
        ));

        $this->app->singleton(GeoIpInterface::class, static fn ($app) => new LaravelGeoIp(
            (array) $app['config']->get('funnypot', [])
        ));

        $this->app->singleton(ReputationInterface::class, static fn ($app) => new MainnetReputation(
            ClientFactory::build((array) $app['config']->get('funnypot', []), $app->make('funnypot.cache'))
        ));

        $this->app->singleton(EvaluatorInterface::class, static fn ($app) => new CoreEvaluator(
            Honeypot::default(self::coreConfig((array) $app['config']->get('funnypot', []), $app))
        ));

        // --- the policy engine + the E-side executor seam ---
        $this->app->singleton(PolicyEngine::class, static function ($app): PolicyEngine {
            $config = (array) $app['config']->get('funnypot', []);
            $policyConfig = PolicyConfig::fromArray(Support\PolicyConfigFactory::build($config));

            return new PolicyEngine(
                $app->make(EvaluatorInterface::class),
                $app->make(ReputationInterface::class),
                $app->make(StateStoreInterface::class),
                $app->make(GeoIpInterface::class),
                $app->make(LaravelClock::class),
                $app->make(LaravelLogger::class),
                $policyConfig,
                self::seedSalt($config, $app),
                'honeypot'
            );
        });
        $this->app->alias(PolicyEngine::class, 'funnypot.policy');

        $this->app->singleton(Engine::class, static fn ($app) => new PolicyEngineAdapter(
            $app->make(PolicyEngine::class)
        ));

        // --- request/response mapping + reporting ---
        $this->app->singleton(LaravelRequestMapper::class, static fn ($app) => new LaravelRequestMapper(
            $app['router'],
            (array) $app['config']->get('funnypot', [])
        ));
        $this->app->singleton(LaravelResponseMapper::class);
        $this->app->singleton(SensorId::class, static fn ($app) => new SensorId($app->make('funnypot.cache')));
        $this->app->singleton(LocalReportQueue::class, static fn ($app) => new LocalReportQueue($app->make('funnypot.cache')));
        $this->app->singleton(ReportDispatcher::class, static fn ($app) => new ReportDispatcher(
            $app->make('funnypot.cache'),
            $app->make(LocalReportQueue::class),
            $app->make(SensorId::class),
            $app->make(\Illuminate\Contracts\Bus\Dispatcher::class)
        ));

        // --- FP-0118: the shared detection head + the detection facade for response-owning apps ---
        $this->app->singleton(Inspector::class, static fn ($app) => new Inspector(
            $app->make(LaravelRequestMapper::class),
            $app->make(Engine::class),
            $app->make(ReportDispatcher::class)
        ));
        $this->app->singleton(Funnypot::class, static fn ($app) => new Funnypot(
            $app->make(Inspector::class),
            $app->make(LaravelResponseMapper::class)
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/funnypot.php' => $this->publishedConfigPath(),
        ], 'funnypot-config');

        // The app opts in with the `funnypot` alias on a route/group or the global/web stack.
        $this->app['router']->aliasMiddleware('funnypot', HoneypotMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateTemplatesCommand::class,
                RulesUpdateCommand::class,
                MirrorSyncCommand::class,
                ReportDrainCommand::class,
            ]);
        }
    }

    /** @return array<int,string> */
    public function provides(): array
    {
        return [PolicyEngine::class, Engine::class, StateStoreInterface::class, ReputationInterface::class];
    }

    private function publishedConfigPath(): string
    {
        return function_exists('config_path')
            ? config_path('funnypot.php')
            : $this->app->basePath('config/funnypot.php');
    }

    /**
     * Build core's synthesis Config from the STYLE keys (passed straight into core's synthesize()).
     * respond mode is on so the evaluator can render a fake when the policy chooses deceive.
     *
     * @param array<string,mixed> $c
     * @param mixed               $app
     */
    private static function coreConfig(array $c, $app): CoreConfig
    {
        return new CoreConfig(
            mode: 'respond',
            responseStyle: (string) ($c['response_style'] ?? 'realistic'),
            severityCeiling: (string) ($c['severity_ceiling'] ?? 'high'),
            maxBodyBytes: (int) ($c['max_body_bytes'] ?? 65536),
            latencyMs: (int) ($c['latency_ms'] ?? 0),
            seedSalt: self::seedSalt($c, $app),
        );
    }

    /**
     * @param array<string,mixed> $c
     * @param mixed               $app
     */
    private static function seedSalt(array $c, $app): string
    {
        $salt = $c['seed_salt'] ?? null;
        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        return (string) $app['config']->get('app.key', '');
    }
}
