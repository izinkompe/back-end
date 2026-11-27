<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'Email harus diverifikasi.');
        return $next($request);
    }
}
