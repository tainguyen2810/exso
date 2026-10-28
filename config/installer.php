<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installed Flag
    |--------------------------------------------------------------------------
    |
    | Normally the app counts as installed once the web installer has written
    | storage/app/installed.json. Set INSTALLER_INSTALLED=true to skip the
    | installer (tests, Docker, installs done with artisan).
    |
    */

    'installed' => env('INSTALLER_INSTALLED'),

    'marker' => storage_path('app/installed.json'),

    'php_version' => '8.3.0',

    'extensions' => [
        'ctype', 'curl', 'fileinfo', 'gd', 'intl', 'mbstring',
        'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'xmlreader', 'zip',
    ],

    'writable' => [
        'storage',
        'bootstrap/cache',
    ],

];
