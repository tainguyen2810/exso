<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function __construct(protected TenantContext $tenancy) {}

    /**
     * Resolve the tenant for this request before anything reads the database.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('install', 'install/*')) {
            return $next($request);
        }

        if (TenantContext::isStandalone()) {
            abort_unless($this->tenancy->check(), 503, 'Chưa có cửa hàng nào được cài đặt.');

            return $next($request);
        }

        $tenant = $this->resolveFromHost($request->getHost());

        abort_if($tenant === null, 404);
        abort_unless($tenant->isActive(), 403, 'Cửa hàng đang bị khóa.');

        $this->tenancy->set($tenant);

        return $next($request);
    }

    protected function resolveFromHost(string $host): ?Tenant
    {
        $central = (string) config('tenancy.central_domain');

        if (Str::endsWith($host, '.'.$central)) {
            return Tenant::query()->where('slug', Str::before($host, '.'.$central))->first();
        }

        return Tenant::query()->where('domain', $host)->first();
    }
}
