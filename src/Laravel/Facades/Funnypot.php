<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Facades;

use Funnypot\Laravel\InspectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the funnypot detection service, so a consumer writes:
 *
 *   use Funnypot\Laravel\Facades\Funnypot;
 *   return Funnypot::handleRequest($request) ?? $myOwn404;
 *
 * @method static Response|null       handleRequest(Request $request, bool $die = false)
 * @method static InspectionResult    inspectRequest(Request $request)
 * @method static \Funnypot\Policy\Decision|null inspect(Request $request)
 * @see \Funnypot\Laravel\Funnypot
 */
final class Funnypot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Funnypot\Laravel\Funnypot::class;
    }
}
