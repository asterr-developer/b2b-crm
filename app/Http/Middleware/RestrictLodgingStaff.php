<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictLodgingStaff
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->worksInLodging()) {
            if ($request->routeIs('dashboard')) {
                return to_route('hospitality.index');
            }
            abort_unless($request->routeIs('hospitality.index', 'hospitality.handoff', 'hospitality.guests', 'hospitality.guests.*'), 403);
        }

        return $next($request);
    }
}
