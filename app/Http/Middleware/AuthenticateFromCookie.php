<?php
namespace App\Http\Middleware;

use App\Support\AuthCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meneruskan token dari cookie HttpOnly sebagai header Bearer agar auth:sanctum
 * tetap menjadi satu-satunya mekanisme autentikasi.
 */
class AuthenticateFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(AuthCookie::name());

        // Header Authorization eksplisit (Postman, BFF, mobile) selalu diutamakan.
        if ($request->bearerToken() || !is_string($token) || $token === '') {
            return $next($request);
        }

        // Browser mengirim cookie otomatis, jadi perubahan data wajib berasal dari origin frontend (CSRF).
        abort_unless($request->isMethodSafe() || $this->fromTrustedOrigin($request), 403, 'Origin tidak diizinkan untuk autentikasi cookie.');

        $request->headers->set('Authorization', 'Bearer '.$token);

        return $next($request);
    }

    private function fromTrustedOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '') {
            $allowed = array_map(fn ($o) => rtrim(trim($o), '/'), config('cors.allowed_origins', []));
            $allowed[] = rtrim($request->getSchemeAndHttpHost(), '/');

            return in_array(rtrim($origin, '/'), $allowed, true);
        }

        // Browser modern mengirim Sec-Fetch-Site walau Origin dihapus; klien non-browser (Postman/CLI) tidak mengirim keduanya.
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (is_string($fetchSite) && $fetchSite !== '') {
            return in_array($fetchSite, ['same-origin', 'none'], true);
        }

        return true;
    }
}
