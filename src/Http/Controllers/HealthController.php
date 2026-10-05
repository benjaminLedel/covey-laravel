<?php

namespace Covey\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// What covey's probe reads: that the token works, as what, and what this
// installation has switched on. "identity" is what the probe shows beside the
// green tick, so it names the application, not a user — there is none.
class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $ability = $request->attributes->get('covey.ability', 'read');

        return response()->json([
            'ok' => true,
            'identity' => config('app.name').' ('.$ability.')',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'ability' => $ability,
            'tinker_enabled' => (bool) config('covey.tinker.enabled'),
            'logs_enabled' => (bool) config('covey.logs.enabled', true),
            'laravel' => app()->version(),
        ]);
    }
}
