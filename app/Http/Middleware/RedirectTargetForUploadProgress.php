<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the upload component (#547) follow a form redirect itself.
 *
 * An XMLHttpRequest follows redirects silently, which would use up the
 * flashed status and errors before the page loads. With the progress header,
 * a redirect is returned as JSON instead, so the browser makes the next
 * request and the flash data is still there.
 */
class RedirectTargetForUploadProgress
{
    public const HEADER = 'X-Upload-Progress';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header(self::HEADER) !== '1' || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $json = new JsonResponse(['redirect' => $response->getTargetUrl()]);
        foreach ($response->headers->getCookies() as $cookie) {
            $json->headers->setCookie($cookie);
        }

        return $json;
    }
}
