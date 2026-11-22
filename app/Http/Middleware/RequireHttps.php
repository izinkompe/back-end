<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class RequireHttps
{
    public function handle(Request $r, Closure $next)
    {
        abort_if(app()->environment('production') && !$r->isSecure(), 400, 'API produksi wajib menggunakan HTTPS.');
        return $next($r);
    }
}
