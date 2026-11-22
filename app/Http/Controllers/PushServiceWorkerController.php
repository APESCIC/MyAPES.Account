<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the push service worker with no-cache headers (#793).
 */
class PushServiceWorkerController extends Controller
{
    public function __invoke(): Response
    {
        $path = resource_path('push/service-worker.js');

        abort_unless(is_readable($path), 404);

        $body = file_get_contents($path);
        abort_if($body === false, 500);

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}