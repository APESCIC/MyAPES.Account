<?php

namespace App\Http\Middleware;

use App\Core\Mail\Inbound\InboundMailSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The local test replies panel (#758, #817) exists only on a local install
 * reading Herd or Mailpit; anywhere else its routes are not found.
 */
class EnsureLocalReplyLoop
{
    public function __construct(private readonly InboundMailSettings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::available($this->settings), 404);

        return $next($request);
    }

    public static function available(InboundMailSettings $settings): bool
    {
        return app()->environment('local') && $settings->usesLocalCatcher();
    }
}
