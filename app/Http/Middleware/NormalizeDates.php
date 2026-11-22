<?php
namespace App\Http\Middleware;
use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class NormalizeDates
{
    public function handle(Request $request, Closure $next)
    {
        $fields = ['starts_at', 'ends_at', 'closes_at', 'needed_at', 'pickup_from', 'pickup_to', 'from', 'to', 'valid_from', 'valid_until'];
        $normalize = function (array $values) use (&$normalize, $fields): array {
            foreach ($values as $key => $value) {
                if (is_array($value)) { $values[$key] = $normalize($value); }
                elseif (in_array($key, $fields, true) && is_string($value) && $value !== '') {
                    try {
                        $parsed = date_parse($value);
                        if ($parsed['warning_count'] || $parsed['error_count']) { continue; }
                        $values[$key] = CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
                    }
                    catch (\Throwable) { /* Leave invalid dates for Laravel's validation response. */ }
                }
            }
            return $values;
        };
        $request->merge($normalize($request->all()));
        return $next($request);
    }
}
