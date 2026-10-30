<?php

namespace App\Installer;

use App\Authorization\Permissions;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\StoreDefaults;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class Installer
{
    /** Sample data accounts: demo@exso.demo, nhanvien1@exso.demo... */
    public const DEMO_DOMAIN = 'exso.demo';

    public const DEMO_LOGIN = 'demo';

    /**
     * .env values written only after a successful install, so a failed
     * attempt leaves the file untouched (and `artisan serve` does not
     * restart mid-request when .env changes).
     *
     * @var array<string, string>
     */
    protected array $pendingEnv = [];

    public function __construct(protected TenantContext $tenancy) {}

    public function isInstalled(): bool
    {
        $flag = config('installer.installed');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOL);
        }

        return File::exists(config('installer.marker'));
    }

    /**
     * @return list<array{label: string, ok: bool}>
     */
    public function requirements(): array
    {
        $checks = [[
            'label' => 'PHP >= '.config('installer.php_version').' (đang dùng '.PHP_VERSION.')',
            'ok' => version_compare(PHP_VERSION, config('installer.php_version'), '>='),
        ]];

        foreach (config('installer.extensions') as $extension) {
            $checks[] = ['label' => "Extension {$extension}", 'ok' => extension_loaded($extension)];
        }

        foreach (config('installer.writable') as $path) {
            $checks[] = ['label' => "Ghi được thư mục {$path}", 'ok' => is_writable(base_path($path))];
        }

        $checks[] = ['label' => 'Ghi được file .env', 'ok' => $this->envIsWritable()];

        return $checks;
    }

    public function meetsRequirements(): bool
    {
        return collect($this->requirements())->every(fn (array $check): bool => $check['ok']);
    }

    /**
     * Point the app at the given MySQL database and check that it answers.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $db
     */
    public function configureDatabase(array $db): void
    {
        $previous = [
            'database.default' => config('database.default'),
            'database.connections.mysql' => config('database.connections.mysql'),
        ];

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $db['host'],
            'database.connections.mysql.port' => $db['port'],
            'database.connections.mysql.database' => $db['database'],
            'database.connections.mysql.username' => $db['username'],
            'database.connections.mysql.password' => $db['password'] ?? '',
        ]);

        DB::purge('mysql');

        try {
            DB::connection('mysql')->getPdo();
        } catch (Throwable $e) {
            config($previous);
            DB::purge('mysql');

            throw $e;
        }

        $this->pendingEnv = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'] ?? '',
        ];
    }

    /**
     * Whether the configured database already has tables: a previous or
     * failed install that must be emptied before installing again.
     */
    public function databaseHasTables(): bool
    {
        return Schema::connection('mysql')->getTables() !== [];
    }

    /**
     * Create the schema, the store (tenant 1) and its first admin. With a
     * `demo_password`, also load sample data and demo accounts that sign
     * in with that password.
     *
     * @param  array{store_name: string, admin_name: string, admin_email: string, admin_password: string, app_url?: string, demo_password?: string|null}  $data
     */
    public function install(array $data): User
    {
        Artisan::call('migrate', ['--force' => true]);

        $tenant = Tenant::query()->create([
            'name' => $data['store_name'],
            'slug' => 'main',
            'status' => 'active',
        ]);

        Permissions::createDefaultRoles($tenant);

        $admin = $this->tenancy->run($tenant, function () use ($data): User {
            app(SettingsRepository::class)->setMany([
                'store.name' => $data['store_name'],
            ]);
            StoreDefaults::install();

            $branch = Branch::query()->create([
                'code' => 'CN01',
                'name' => 'Chi nhánh chính',
                'is_default' => true,
            ]);

            $admin = new User([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
            ]);
            $admin->email_verified_at = now();
            $admin->is_owner = true;
            $admin->save();

            $admin->branches()->attach($branch);
            $admin->assignRole('Quản trị');

            if (($data['demo_password'] ?? null) !== null) {
                $seeder = app(DemoDataSeeder::class);
                $seeder->domain = self::DEMO_DOMAIN;
                $seeder->password = $data['demo_password'];
                $seeder->setContainer(app())->__invoke();
            }

            return $admin;
        });

        if (isset($data['app_url'])) {
            $this->pendingEnv['APP_URL'] = $data['app_url'];
        }

        if ($this->pendingEnv !== []) {
            $this->env()->set($this->pendingEnv);
        }

        $this->markInstalled();

        return $admin;
    }

    public function markInstalled(): void
    {
        File::ensureDirectoryExists(dirname(config('installer.marker')));
        File::put(config('installer.marker'), (string) json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => config('app.version'),
        ], JSON_PRETTY_PRINT));
    }

    protected function env(): EnvWriter
    {
        return new EnvWriter(app()->environmentFilePath());
    }

    protected function envIsWritable(): bool
    {
        $path = app()->environmentFilePath();

        return file_exists($path) ? is_writable($path) : is_writable(dirname($path));
    }
}
