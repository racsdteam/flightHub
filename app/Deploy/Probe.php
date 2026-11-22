<?php

namespace App\Deploy;

use App\Database\Grants\GrantComparator;
use App\Database\Grants\GrantRegistry;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What hub:preflight reads from the machine it runs on: PHP, files, the .env file, caches, the
 * database and the certificate of APP_URL. Every method answers yes/no or a fingerprint; no
 * method hands a secret value back. Tests replace it with a fake.
 */
class Probe
{
    /** The hub's own needs beyond composer.lock: MariaDB (PDO) and gzip for raw responses. */
    public const HUB_EXTENSIONS = ['pdo_mysql', 'zlib'];

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    /**
     * The CLI's setting (the web server's PHP may differ: .user.ini applies to the web only).
     */
    public function ini(string $key): string|false
    {
        return ini_get($key);
    }

    public function writable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    /**
     * ext-* required by the production packages of composer.lock (not packages-dev) and by its
     * platform section, plus HUB_EXTENSIONS.
     *
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        $lock = json_decode((string) @file_get_contents(base_path('composer.lock')), true);
        $extensions = self::HUB_EXTENSIONS;
        foreach ([...($lock['packages'] ?? []), ['require' => $lock['platform'] ?? []]] as $package) {
            foreach (array_keys($package['require'] ?? []) as $name) {
                if (str_starts_with($name, 'ext-')) {
                    $extensions[] = substr($name, 4);
                }
            }
        }
        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        return $extensions;
    }

    /**
     * Whether the .env file gives the key a non-empty value ("null" counts as empty). The value
     * itself never leaves this method.
     */
    public function envFileHasValue(string $key): bool
    {
        $path = app()->environmentFilePath();
        if (! is_file($path)) {
            return false;
        }
        $value = Dotenv::parse((string) file_get_contents($path))[$key] ?? null;

        return $value !== null && ! in_array(strtolower(trim($value)), ['', 'null', '(null)', 'empty', '(empty)'], true);
    }

    /**
     * The config cache (bootstrap/cache/config.php), or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function cachedConfig(): ?array
    {
        $path = app()->getCachedConfigPath();

        return is_file($path) ? (require $path) : null;
    }

    public function routesCached(): bool
    {
        return app()->routesAreCached();
    }

    /** public/.htaccess as it is on disk, or null when there is none. */
    public function htaccess(): ?string
    {
        $path = public_path('.htaccess');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * This run's process environment: inside deploy.sh the DDL credentials arrive this way (an
     * assignment prefix), never through the .env file or the config cache.
     */
    public function environmentHas(string $key): bool
    {
        $value = Env::get($key);

        return $value !== null && $value !== '';
    }

    /**
     * @return array{valid: bool, expires_at: int|null}
     */
    public function certificate(string $host, int $port = 443): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return ['valid' => false, 'expires_at' => null];
        }
        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $certificate === null ? false : openssl_x509_parse($certificate);
        $expires = is_array($info) ? ($info['validTo_time_t'] ?? null) : null;

        return ['valid' => $expires !== null && $expires > time(), 'expires_at' => $expires];
    }

    /**
     * Config and route fingerprints of a fresh boot (no caches), from a child process.
     *
     * @return array{config: string, routes: string}|null
     */
    public function freshFingerprints(): ?array
    {
        $none = rtrim(sys_get_temp_dir(), '/').'/hub-preflight-none-'.bin2hex(random_bytes(8));
        $process = new Process([PHP_BINARY, base_path('artisan'), 'hub:preflight', '--fingerprint'], base_path(), [
            'APP_CONFIG_CACHE' => $none.'-config.php',
            'APP_ROUTES_CACHE' => $none.'-routes.php',
        ]);
        $process->setTimeout(60)->run();
        $data = $process->isSuccessful() ? json_decode(trim($process->getOutput()), true) : null;

        return is_array($data) && is_string($data['config'] ?? null) && is_string($data['routes'] ?? null) ? $data : null;
    }

    public function runtimeConnectionWorks(): bool
    {
        try {
            DB::connection('mariadb')->select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * hub:check-grants' comparison (the grant lines hold password hashes: parsed, never kept).
     */
    public function grantsMatch(): bool
    {
        try {
            $connection = DB::connection('mariadb');
            $lines = array_map(fn ($row) => (string) array_values((array) $row)[0], $connection->select('SHOW GRANTS FOR CURRENT_USER()'));

            return GrantComparator::compare(GrantRegistry::fromConfig(), $connection->getDatabaseName(), $lines) === [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The DDL connection with the credentials of this run's environment (also when the config
     * is cached: the cache never holds them). Held in memory only.
     */
    public function useDdlFromEnvironment(): void
    {
        config([
            'database.connections.mariadb_ddl.username' => (string) Env::get('DB_DDL_USERNAME', ''),
            'database.connections.mariadb_ddl.password' => (string) Env::get('DB_DDL_PASSWORD', ''),
        ]);
        DB::purge('mariadb_ddl');
    }

    public function ddlLoginWorks(): bool
    {
        try {
            DB::connection('mariadb_ddl')->select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Migrations not yet run, read as the DDL user; null when that fails.
     */
    public function pendingMigrations(): ?int
    {
        try {
            // Not Migrator::setConnection(): it changes the default database connection and
            // never restores it. The repository alone is pointed at the DDL connection.
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $repository = $migrator->getRepository();
            $repository->setSource('mariadb_ddl');
            try {
                $ran = $repository->repositoryExists() ? $repository->getRan() : [];
            } finally {
                $repository->setSource(null);
            }

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tables (of the given ones) without both a BEFORE UPDATE and a BEFORE DELETE trigger, read as
     * the DDL user; null when that fails.
     *
     * @param  list<string>  $tables
     * @return list<string>|null
     */
    public function tablesMissingTriggers(array $tables): ?array
    {
        try {
            $rows = DB::connection('mariadb_ddl')->select(
                "SELECT EVENT_OBJECT_TABLE AS t, EVENT_MANIPULATION AS m FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND ACTION_TIMING = 'BEFORE'"
            );
        } catch (Throwable) {
            return null;
        }
        $have = [];
        foreach ($rows as $row) {
            $have[$row->t][$row->m] = true;
        }

        return array_values(array_filter($tables, fn ($table) => ! isset($have[$table]['UPDATE'], $have[$table]['DELETE'])));
    }
}
