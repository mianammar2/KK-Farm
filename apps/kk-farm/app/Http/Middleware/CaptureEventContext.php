<?php

namespace App\Http\Middleware;

use App\Support\EventContext;
use Closure;
use Illuminate\Http\Request;

/** Records which device and channel a request came from, for the history envelope (D-038). */
class CaptureEventContext
{
    public function handle(Request $request, Closure $next)
    {
        EventContext::fromRequest($request);

        return $next($request);
    }

    public function terminate(): void
    {
        EventContext::reset();
    }
}
