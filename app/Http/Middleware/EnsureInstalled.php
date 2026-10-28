<?php

namespace App\Http\Middleware;

use App\Installer\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function __construct(protected Installer $installer) {}

    /**
     * Send every request to /install until setup is done, then lock the installer.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isInstallRoute = $request->is('install', 'install/*');

        if ($this->installer->isInstalled()) {
            abort_if($isInstallRoute, 404);

            return $next($request);
        }

        if (! $isInstallRoute) {
            return redirect('/install');
        }

        return $next($request);
    }
}
