<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    |
    | "standalone": self-hosted install (WordPress style), always one tenant.
    | "saas": many tenants, identified by subdomain or custom domain.
    |
    */

    'mode' => env('APP_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Central Domain
    |--------------------------------------------------------------------------
    |
    | In SaaS mode a request to "shop.<central_domain>" resolves the tenant
    | whose slug is "shop". Any other host is looked up in tenants.domain.
    |
    */

    'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

];
