<?php

use App\Installer\EnvWriter;

test('it replaces existing keys and appends new ones', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=EXSO\nDB_HOST=127.0.0.1\n");

    (new EnvWriter($path))->set([
        'DB_HOST' => 'mysql.local',
        'DB_PASSWORD' => 'p@ss word#1',
        'APP_DEBUG' => false,
    ]);

    expect(file_get_contents($path))->toBe(
        "APP_NAME=EXSO\nDB_HOST=mysql.local\nDB_PASSWORD=\"p@ss word#1\"\nAPP_DEBUG=false\n"
    );

    unlink($path);
});
