<?php

namespace BoringO11y\Httptheus\Http\Middleware;

use BoringO11y\Httptheus\Httptheus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class Authorize
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $this->allows($request) ? $next($request) : abort(403);
    }

    private function allows(Request $request): bool
    {
        // An explicit callback is the application's own decision and wins over
        // everything else, including the IP list.
        if (Httptheus::$authUsing !== null) {
            return Httptheus::check($request);
        }

        $allowed = (array) config('httptheus.route.allowed_ips', []);

        if ($allowed !== []) {
            return IpUtils::checkIp((string) $request->ip(), $allowed);
        }

        return Httptheus::check($request);
    }
}
