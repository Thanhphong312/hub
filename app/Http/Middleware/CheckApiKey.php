<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class CheckApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-KEY');
        if (!$apiKey) {
            return response()->json(['message' => 'API key required'], 401);
        }

        $user = User::where('api_key', $apiKey)->where('status', 1)->first();
        if (!$user) {
            return response()->json(['message' => 'Invalid API key'], 401);
        }

        $request->merge(['api_user' => $user]);
        return $next($request);
    }
}
