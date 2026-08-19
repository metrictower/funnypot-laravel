<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests;

use Funnypot\Laravel\FunnypotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        return [FunnypotServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('funnypot.state.cache_store', 'array');
        // The host app defines a `funnypot` log channel (documented in the README); here it discards.
        $app['config']->set('logging.channels.funnypot', [
            'driver'  => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
        // Deterministic: a persistent (async) queue by default; individual tests flip to sync for SF-5.
        $app['config']->set('queue.default', 'database');
    }
}
