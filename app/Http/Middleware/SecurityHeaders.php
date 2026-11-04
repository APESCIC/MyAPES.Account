<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Security Policy and security headers on every response (#485).
 */
class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $header = config('myapes.csp.enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $response->headers->set($header, $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        /** @var array<string, list<string>> $directives */
        $directives = config('myapes.csp.directives', []);
        $directives['script-src'][] = "'nonce-{$nonce}'";

        $devServer = $this->viteDevServer();
        if ($devServer !== null) {
            $websocket = preg_replace('#^http#', 'ws', $devServer);
            foreach (['script-src', 'style-src', 'connect-src', 'font-src', 'img-src'] as $directive) {
                $directives[$directive][] = $devServer;
            }
            $directives['connect-src'][] = $websocket;
        }

        if (config('myapes.csp.report')) {
            $directives['report-uri'] = [route('csp.report', absolute: false)];
        }

        return collect($directives)
            ->map(static fn (array $sources, string $name): string => trim($name.' '.implode(' ', array_unique($sources))))
            ->implode('; ');
    }

    private function viteDevServer(): ?string
    {
        if (! app()->isLocal() || ! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) File::get(Vite::hotFile()));
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
    }
}
