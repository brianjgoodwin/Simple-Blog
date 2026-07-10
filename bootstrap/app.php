<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the shared Caddy proxy (Docker network 172.18.0.0/16), trust
        // X-Forwarded-* only from that network so Laravel generates correct
        // https:// URLs without trusting arbitrary client headers.
        $middleware->trustProxies(at: '172.18.0.0/16');

        // Reject requests with a spoofed Host / X-Forwarded-Host. The app trusts
        // the whole Docker subnet as a proxy, and any co-resident container can
        // reach it un-proxied — without this, a compromised container could forge
        // the Host to poison generated URLs (e.g. password-reset links). Only these
        // hosts are honored; anything else gets a 400 before URL generation.
        $middleware->trustHosts(at: ['simpleblog.brianjgoodwin.dev']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Framework default behavior (render JSON when the request expects
        // JSON) is intentionally kept: the composer's autosave sends
        // Accept: application/json and needs a 422 JSON response on
        // validation failure, not a redirect.
    })->create();
