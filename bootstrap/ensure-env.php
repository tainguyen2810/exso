<?php

/*
|--------------------------------------------------------------------------
| First Run Environment
|--------------------------------------------------------------------------
|
| A fresh upload (zip install, WAMP, shared hosting) has no .env yet. Create
| it from .env.example with a random APP_KEY before Laravel boots, so the
| web installer at /install can run without SSH or artisan. A .env copied
| by hand with an empty APP_KEY gets one too.
|
*/

(function (string $basePath): void {
    $env = $basePath.'/.env';
    $example = $basePath.'/.env.example';

    $key = 'base64:'.base64_encode(random_bytes(32));

    // .env copied by hand from .env.example without `key:generate`.
    if (file_exists($env)) {
        $contents = (string) file_get_contents($env);

        if (preg_match('/^APP_KEY=\s*$/m', $contents) === 1 && is_writable($env)) {
            file_put_contents($env, preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY='.$key, $contents, 1) ?? $contents);
        }

        return;
    }

    if (! file_exists($example) || ! is_writable($basePath)) {
        return;
    }

    $contents = (string) file_get_contents($example);
    $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1) ?? $contents;

    file_put_contents($env, $contents);
})(dirname(__DIR__));
