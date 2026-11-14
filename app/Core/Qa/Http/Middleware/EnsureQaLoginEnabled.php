<?php

namespace App\Core\Qa\Http\Middleware;

use App\Core\Qa\QaLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQaLoginEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(QaLogin::enabled(), 404);

        return $next($request);
    }
}
