<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken && $mode === 'optional') {
            return $next($request);
        }

        $token = $plainToken
            ? ApiToken::query()->with('user')->where('token_hash', hash('sha256', $plainToken))->first()
            : null;

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            $token?->delete();

            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
