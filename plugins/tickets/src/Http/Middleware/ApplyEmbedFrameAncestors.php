<?php

namespace Plugins\Tickets\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Tickets\Models\TicketEmbedForm;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mark embed responses so SecurityHeaders uses per-form frame-ancestors (#685).
 */
final class ApplyEmbedFrameAncestors
{
    public const REQUEST_KEY = 'tickets.embed.frame_ancestors';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = $request->route('publicKey');
        if (is_string($publicKey) && $publicKey !== '') {
            $form = TicketEmbedForm::query()->where('public_key', $publicKey)->first();
            if ($form !== null) {
                $sources = $form->frameAncestorsSources();
                $request->attributes->set(self::REQUEST_KEY, $sources === [] ? ["'none'"] : $sources);
            }
        }

        return $next($request);
    }
}
