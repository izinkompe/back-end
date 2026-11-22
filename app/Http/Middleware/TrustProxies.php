<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class TrustProxies extends \Illuminate\Http\Middleware\TrustProxies
{
    public function handle(Request $request, Closure $next)
    {
        $this->proxies = config('pangantani.trusted_proxies', []);
        return parent::handle($request, $next);
    }
}
