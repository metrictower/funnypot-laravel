<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Support;

use Funnypot\Core\Honeypot;

/**
 * Resolve funnypot-core's install path from E (the `bin/funnypot` compiler + `resources/` live in core,
 * not this package). Located by reflection on a core class so it works regardless of where composer
 * installed core (vendor path, path repo, symlink).
 *
 * The design places this helper in core; E can only touch its own tree, so E carries an equivalent
 * resolver until core ships one.
 */
final class CorePaths
{
    public static function root(): string
    {
        $file = (new \ReflectionClass(Honeypot::class))->getFileName();
        if ($file === false) {
            return '';
        }

        return dirname($file, 2); // .../funnypot-core/src/Honeypot.php → .../funnypot-core
    }

    public static function binary(): string
    {
        return self::root() . '/bin/funnypot';
    }
}
