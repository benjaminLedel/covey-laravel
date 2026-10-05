<?php

namespace Covey\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Which token the request carries decides what it may do. The write token
// implies read: an agent trusted to change a thing may look at it first.
// Everything is compared as hashes in constant time; the plain token exists
// only in covey's secret store and in the request header.
class AuthenticateCoveyToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return $this->refuse('no bearer token');
        }
        $granted = self::abilityOf($token);
        if ($granted === null) {
            return $this->refuse('unknown token');
        }
        if ($ability === 'write' && $granted !== 'write') {
            return response()->json([
                'error' => 'this token may read, not write — the write endpoint needs the write token',
            ], 403);
        }
        $request->attributes->set('covey.ability', $granted);

        return $next($request);
    }

    // abilityOf names what a plain token is good for: "write", "read" or null.
    public static function abilityOf(string $token): ?string
    {
        $hash = hash('sha256', $token);
        foreach (['write', 'read'] as $ability) {
            $want = (string) config("covey.tokens.$ability");
            if ($want !== '' && hash_equals(strtolower($want), $hash)) {
                return $ability;
            }
        }

        return null;
    }

    private function refuse(string $why): Response
    {
        return response()->json(['error' => $why], 401);
    }
}
