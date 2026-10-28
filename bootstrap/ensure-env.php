<?php

/*
|--------------------------------------------------------------------------
| First Run Environment
|--------------------------------------------------------------------------
|
| A fresh upload (zip install, WAMP, shared hosting) has no .env yet. Create
| it from .env.example with a random APP_KEY before Laravel boots, so the
| web installer at /install can run without SSH or artisan.
|
*/

(function (string $basePath): void {
    $env = $basePath.'/.env';
    $example = $basePath.'/.env.example';

    if (file_exists($env) || ! file_exists($example) || ! is_writable($basePath)) {
        return;
    }

    $contents = (string) file_get_contents($example);
    $key = 'base64:'.base64_encode(random_bytes(32));
    $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1) ?? $contents;

    file_put_contents($env, $contents);
})(dirname(__DIR__));
