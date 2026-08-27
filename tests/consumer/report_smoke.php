<?php

declare(strict_types=1);

/**
 * FP-0062 consumer smoke — proves guzzlehttp/guzzle is a declared RUNTIME dependency.
 *
 * The package reaches Guzzle only through the Http facade inside method bodies, so a class-existence
 * sweep of the shipped FQCNs never autoloads it: drop guzzle from `require` and the sweep stays green
 * while `Class "GuzzleHttp\HandlerStack" not found` returns in production. This instead boots the real
 * report path (SendMainnetReport::handle) against Http::fake(), which still builds a Guzzle client +
 * handler stack, so a missing guzzle fails here at install time.
 *
 * Must run on the Laravel 8 floor. From Laravel ~10 illuminate/http declares guzzle itself, which would
 * mask an undeclared guzzle here; on the floor (and in the real consumer, a Laravel 8 app) illuminate/http
 * does not, so the package's own declaration is load-bearing.
 *
 * Runs inside a scratch app installed as a plain dependency, so it may use neither PHPUnit (dev-only) nor
 * the config()/app() global helpers (laravel/framework-only, absent from the split illuminate packages a
 * library consumer pulls). Hence the config() shim and the hand-rolled Http::recorded() check. The caller
 * must already have required the scratch app's vendor/autoload.php. Exits non-zero on any failure.
 */

use Funnypot\Laravel\Jobs\SendMainnetReport;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

// config() is a laravel/framework helper; a library consumer has only the split illuminate packages, so
// stand it in with the two keys SendMainnetReport reads. A real Laravel app supplies the real helper.
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        $map = [
            'funnypot.mainnet.key'      => 'consumer-smoke-key',
            'funnypot.mainnet.base_url' => 'https://mainnet.consumer-smoke.test',
        ];

        return $map[$key] ?? $default;
    }
}

$fail = static function (string $why): void {
    fwrite(STDERR, "consumer report smoke FAILED: {$why}\n");
    exit(1);
};

// The Http facade is all SendMainnetReport::handle() resolves from the container.
$app = new Container();
Container::setInstance($app);
$app->singleton(HttpFactory::class, static fn () => new HttpFactory());
Facade::setFacadeApplication($app);

// Default 200. The point is not the response — it is that faking still constructs GuzzleHttp\HandlerStack.
Http::fake();

try {
    (new SendMainnetReport('203.0.113.7', 'scanner-probe', 'consumer-smoke-uuid'))->handle();
} catch (\Throwable $e) {
    // A missing guzzle surfaces here as an Error ("Class ... not found"), which is exactly the failure
    // this lane exists to catch; report it plainly rather than letting a fatal escape unlabelled.
    $fail(get_class($e) . ': ' . $e->getMessage());
}

$sent = Http::recorded(static fn ($request) => str_contains($request->url(), '/v1/report'));
if ($sent->isEmpty()) {
    $fail('the report path did not send a request to /v1/report');
}

fwrite(STDOUT, "consumer report smoke OK — Guzzle-backed send exercised via the real report path\n");
