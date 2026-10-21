<?php
declare(strict_types=1);

namespace EPejabat\Core;

use EPejabat\Modules\Training\ExtendedMigrations;
use EPejabat\Modules\Training\Migrations as TrainingMigrations;
use PDO;
use RuntimeException;
use Throwable;

/** Transactional cPanel deployment runner for the canonical root domain. */
final class CpanelDeployment
{
    private const ORIGIN = 'https://pkdkb.epejabat.net';
    private const MUTATED_FILES = [
        '.env',
        '.htaccess',
        '.user.ini',
        'config/app.php',
        'config/core.key',
        'config/setup.php',
        'storage/installed.lock',
    ];
    private const RUNTIME_DIRECTORIES = [
        'storage/logs',
        'storage/files',
        'storage/sessions',
        'storage/documents',
        'storage/imports',
        'storage/backups',
        'storage/uploads',
        'storage/upgrades',
        'storage/deployments',
        'storage/eforms-originals',
        'storage/eforms-packages',
    ];

    /** @var array<string,string> */
    private array $env;
    /** @var array<string,string|int> */
    private array $dbConfig;
    private ?PDO $pdo = null;
    private string $runId = '';
    private string $runDirectory = '';
    private string $logFile = '';
    private string $databaseBackup = '';
    private string $databaseBackupHash = '';
    private string $fileBackup = '';
    private string $startedAt = '';
    /** @var array<int,array<string,mixed>> */
    private array $phaseReport = [];

    private function __construct()
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        umask(0077);
        $this->env = self::readEnv(APP_ROOT . '/.env');
        $this->dbConfig = [
            'host' => $this->env['DB_HOST'] ?? '',
            'port' => isset($this->env['DB_PORT']) && ctype_digit($this->env['DB_PORT']) ? (int) $this->env['DB_PORT'] : 3306,
            'name' => $this->env['DB_NAME'] ?? '',
            'user' => $this->env['DB_USER'] ?? '',
            'password' => $this->env['DB_PASSWORD'] ?? ($this->env['DB_PASS'] ?? ''),
        ];
    }

    public static function main(array $argv): int
    {
        try {
            $command = strtolower((string) ($argv[1] ?? 'help'));
            if (in_array($command, ['help', '--help', '-h'], true)) return self::help();
            $runner = new self();
            $options = self::options(array_slice($argv, 2));
            return match ($command) {
                'preflight' => $runner->preflightCommand(self::onlyOptions($options, [])),
                'regression' => $runner->regressionCommand(self::onlyOptions($options, ['origin', 'actor'])),
                'deploy' => $runner->deployCommand(self::onlyOptions($options, ['mode', 'actor', 'confirm'])),
                default => throw new RuntimeException('Arahan tidak sah. Gunakan: php bin/cpanel-deploy.php help'),
            };
        } catch (Throwable $error) {
            fwrite(STDERR, "DEPLOYMENT BLOCKED: " . self::safeError($error) . "\n");
            return 1;
        }
    }

    private static function help(): int
    {
        echo <<<'TXT'
e-Pejabat cPanel deployment CLI

  php bin/cpanel-deploy.php preflight
  php bin/cpanel-deploy.php regression --origin=https://pkdkb.epejabat.net
  php bin/cpanel-deploy.php deploy --mode=fresh --confirm=pkdkb.epejabat.net
  php bin/cpanel-deploy.php deploy --mode=upgrade --actor=1 --confirm=pkdkb.epejabat.net

Fresh deployment requires EPEJABAT_ADMIN_NAME, EPEJABAT_ADMIN_USERNAME,
EPEJABAT_ADMIN_EMAIL, EPEJABAT_ORG_CODE and EPEJABAT_ORG_NAME. Supply the
password through EPEJABAT_ADMIN_PASSWORD or an interactive hidden prompt.
Never place a password in CLI arguments.
TXT;
        echo "\n";
        return 0;
    }

    /** @return array<string,string> */
    private static function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || preg_match('/\A--([a-z][a-z0-9-]*)=(.*)\z/s', $argument, $match) !== 1) {
                throw new RuntimeException('Pilihan CLI tidak sah: ' . (is_scalar($argument) ? (string) $argument : 'unknown'));
            }
            if (isset($options[$match[1]])) throw new RuntimeException('Pilihan CLI berulang: --' . $match[1]);
            $options[$match[1]] = $match[2];
        }
        return $options;
    }

    private function preflightCommand(array $options): int
    {
        $checks = $this->preflight(false);
        echo json_encode(['status' => self::allPass($checks) ? 'PASS' : 'BLOCKED', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return self::allPass($checks) ? 0 : 1;
    }

    /** @param array<string,string> $options */
    private function regressionCommand(array $options): int
    {
        $origin = rtrim($options['origin'] ?? self::ORIGIN, '/');
        $config = $this->activeConfig();
        $this->pdo = Database::connect($config['db']);
        $actor = $this->resolveSuperAdmin(isset($options['actor']) ? self::positiveInt($options['actor'], 'actor') : null);
        $tests = [...$this->schemaTests($actor, true), ...$this->httpTests($origin)];
        $passed = count(array_filter($tests, static fn(array $test): bool => $test['status'] === 'PASS'));
        $total = count($tests);
        $report = [
            'status' => $passed === $total ? 'PASS' : 'FAIL',
            'origin' => $origin,
            'passed' => $passed,
            'total' => $total,
            'pass_rate' => $total > 0 ? round($passed * 100 / $total, 2) : 0,
            'tests' => $tests,
        ];
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return $passed === $total ? 0 : 1;
    }

    /** @param array<string,string> $options */
    private function deployCommand(array $options): int
    {
        if (($options['confirm'] ?? '') !== 'pkdkb.epejabat.net') {
            throw new RuntimeException('Pengesahan domain diperlukan: --confirm=pkdkb.epejabat.net');
        }
        $mode = $options['mode'] ?? '';
        if (!in_array($mode, ['fresh', 'upgrade'], true)) throw new RuntimeException('--mode=fresh atau --mode=upgrade diperlukan.');

        $this->ensureRuntimeDirectories();
        $lockPath = APP_ROOT . '/storage/deployments/deploy.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Deployment lain sedang berjalan.');

        $this->runId = bin2hex(random_bytes(16));
        $this->runDirectory = APP_ROOT . '/storage/deployments/' . $this->runId;
        if (!mkdir($this->runDirectory, 0700) || !chmod($this->runDirectory, 0700)) throw new RuntimeException('Direktori deployment tidak boleh dicipta.');
        $this->logFile = APP_ROOT . '/storage/logs/cpanel-deploy-' . $this->runId . '.log';
        $this->startedAt = gmdate('c');
        $rollbackRequired = false;

        try {
            $this->log('INFO', 'Deployment started', ['mode' => $mode, 'run_id' => $this->runId]);
            $checks = $this->preflight(true);
            $this->requirePass($checks, 'Preflight gagal.');
            $this->snapshotFiles();
            $this->connect();
            $this->assertMode($mode);
            $this->backupDatabase();
            $rollbackRequired = true;
            $this->writeMaintenanceLock();
            $this->installProductionFiles();

            $actor = $mode === 'fresh'
                ? $this->migrateFresh()
                : $this->migrateExisting(isset($options['actor']) ? self::positiveInt($options['actor'], 'actor') : null);

            $preFinalTests = $this->schemaTests($actor, false);
            $this->requirePass($preFinalTests, 'Ujian skema/RBAC sebelum finalization gagal.');
            $this->finalize($mode, $actor);

            $finalSchemaTests = $this->schemaTests($actor, true);
            $this->clearMaintenanceLock();
            $httpTests = $this->httpTests(self::ORIGIN);
            $tests = [...$preFinalTests, ...$finalSchemaTests, ...$httpTests];
            $this->requirePass($tests, 'Ujian regresi selepas finalization gagal.');

            $report = $this->officialReport($mode, $tests);
            $rollbackRequired = false;
            $this->log('INFO', 'Deployment verified', ['report_sha256' => $report['report_sha256']]);
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            return 0;
        } catch (Throwable $error) {
            $this->log('ERROR', 'Deployment failed', ['error' => self::safeError($error), 'trace' => self::safeTrace($error)]);
            $rollback = ['status' => 'NOT_REQUIRED'];
            if ($rollbackRequired) {
                try {
                    $rollback = $this->rollback();
                    $this->log('WARNING', 'Automatic rollback completed', $rollback);
                } catch (Throwable $rollbackError) {
                    $rollback = ['status' => 'FAILED', 'error' => self::safeError($rollbackError)];
                    $this->log('CRITICAL', 'Automatic rollback failed', ['error' => self::safeError($rollbackError), 'trace' => self::safeTrace($rollbackError)]);
                    $this->writeBlockedLock();
                }
            }
            fwrite(STDERR, "DEPLOYMENT FAILED: " . self::safeError($error) . "\n");
            fwrite(STDERR, 'ROLLBACK: ' . ($rollback['status'] ?? 'UNKNOWN') . "\n");
            fwrite(STDERR, 'LOG: ' . ($this->logFile !== '' ? $this->logFile : 'unavailable') . "\n");
            return 1;
        } finally {
            $this->pdo = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<int,array{name:string,status:string,detail:string}> */
    private function preflight(bool $deployment): array
    {
        $checks = [];
        $add = static function (string $name, bool $pass, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
        };

        $add('PHP 8.2+', PHP_VERSION_ID >= 80200, PHP_VERSION);
        foreach (['pdo_mysql', 'gd', 'curl', 'openssl', 'fileinfo', 'json', 'mbstring', 'zip', 'session'] as $extension) {
            $add('Extension ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing');
        }
        $root = realpath(APP_ROOT);
        $cwd = realpath(getcwd() ?: '');
        $add('Document root command context', $root !== false && $cwd === $root, $cwd ?: 'unresolved');
        $add('index.php at root', is_file(APP_ROOT . '/index.php'), APP_ROOT . '/index.php');

        $appUrl = rtrim($this->env['APP_URL'] ?? '', '/');
        $productionValues = [
            'APP_URL canonical root' => [$appUrl === self::ORIGIN && (string) parse_url($appUrl, PHP_URL_PATH) === '', $appUrl !== '' ? $appUrl : 'missing', self::ORIGIN],
            'APP_ENV production' => [($this->env['APP_ENV'] ?? '') === 'production', $this->env['APP_ENV'] ?? 'missing', 'production'],
            'display_errors disabled' => [($this->env['APP_DISPLAY_ERRORS'] ?? '') === '0', $this->env['APP_DISPLAY_ERRORS'] ?? 'missing', '0'],
            'log_errors enabled' => [($this->env['APP_LOG_ERRORS'] ?? '') === '1', $this->env['APP_LOG_ERRORS'] ?? 'missing', '1'],
        ];
        foreach ($productionValues as $name => [$alreadyValid, $current, $target]) {
            $add($name, $deployment || $alreadyValid, $alreadyValid ? (string) $current : ($deployment ? 'will set to ' . $target : (string) $current));
        }
        $add('APP_INSTANCE', preg_match('/\A[a-f0-9]{32}\z/', strtolower($this->env['APP_INSTANCE'] ?? '')) === 1, '32 hex required');
        $productionDb = isset($this->env['DB_HOST'], $this->env['DB_PORT'], $this->env['DB_NAME'], $this->env['DB_USER'], $this->env['DB_PASSWORD'])
            && preg_match('/\A[^\x00-\x20]{1,255}\z/', $this->env['DB_HOST']) === 1
            && ctype_digit($this->env['DB_PORT']) && (int) $this->env['DB_PORT'] >= 1 && (int) $this->env['DB_PORT'] <= 65535
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}\z/', $this->env['DB_NAME']) === 1
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_$.-]{0,127}\z/', $this->env['DB_USER']) === 1
            && $this->env['DB_PASSWORD'] !== ''
            && !str_starts_with($this->env['DB_NAME'], 'CPANEL_') && !str_starts_with($this->env['DB_USER'], 'CPANEL_')
            && !str_starts_with($this->env['DB_PASSWORD'], 'REPLACE_');
        $add('Production DB environment', $productionDb, $productionDb ? 'complete' : 'DB_HOST/PORT/NAME/USER/PASSWORD incomplete');

        $htaccess = is_file(APP_ROOT . '/.htaccess.production') ? (string) file_get_contents(APP_ROOT . '/.htaccess.production') : '';
        $rootRewrite = preg_match('/^RewriteBase \/\s*$/m', $htaccess) === 1 && !str_contains($htaccess, 'RewriteBase /epejabat');
        $add('Root-domain .htaccess', $rootRewrite, $rootRewrite ? 'RewriteBase /' : 'invalid production template');
        $ini = is_file(APP_ROOT . '/.user.ini.production') ? (string) file_get_contents(APP_ROOT . '/.user.ini.production') : '';
        $add('Production PHP error policy', str_contains($ini, 'display_errors = Off') && str_contains($ini, 'log_errors = On'), '.user.ini.production');
        $add('Production app config template', is_file(APP_ROOT . '/config/app.php.production'), 'config/app.php.production');

        foreach (self::RUNTIME_DIRECTORIES as $relative) {
            $path = APP_ROOT . '/' . $relative;
            $pass = is_dir($path) && !is_link($path) && is_writable($path);
            $add('Writable ' . $relative, $pass, $pass ? 'writable' : 'missing, symlink or not writable');
        }
        $free = @disk_free_space(APP_ROOT . '/storage');
        $add('Free storage >= 256 MiB', is_float($free) && $free >= 268435456, is_float($free) ? (string) (int) $free : 'unknown');
        $add('proc_open available', function_exists('proc_open'), function_exists('proc_open') ? 'available' : 'disabled');
        $dump = $this->findBinary('mysqldump');
        $mysql = $this->findBinary('mysql');
        $add('mysqldump CLI', $dump !== null, $dump ?? 'missing');
        $add('mysql CLI', $mysql !== null, $mysql ?? 'missing');
        if ($deployment) {
            $maintenance = APP_ROOT . '/storage/upgrades/maintenance.lock';
            $blocked = APP_ROOT . '/storage/upgrades/blocked.lock';
            $add('No stale deployment locks', !is_file($maintenance) && !is_file($blocked), is_file($maintenance) || is_file($blocked) ? 'maintenance/recovery lock exists' : 'clear');
        }

        try {
            $pdo = Database::connect($this->dbConfig);
            $server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            preg_match('/(\d+\.\d+\.\d+)/', $server, $version);
            $minimum = str_contains($server, 'MariaDB') ? '10.6.0' : '8.0.16';
            $compatible = isset($version[1]) && version_compare($version[1], $minimum, '>=');
            $add('Database connection', true, 'connected');
            $add('Database version', $compatible, $server . ' (minimum ' . $minimum . ')');
            $pdo = null;
        } catch (Throwable $error) {
            $add('Database connection', false, self::safeError($error));
        }

        if ($deployment && !self::allPass($checks)) $this->log('ERROR', 'Preflight blocked', ['checks' => $checks]);
        return $checks;
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach (self::RUNTIME_DIRECTORIES as $relative) {
            $path = APP_ROOT . '/' . $relative;
            if (is_link($path)) throw new RuntimeException('Symlink runtime ditolak: ' . $relative);
            if (!is_dir($path) && !mkdir($path, 0750, true)) throw new RuntimeException('Direktori tidak boleh dicipta: ' . $relative);
            @chmod($path, 0750);
        }
    }

    private function connect(): void
    {
        $this->pdo = Database::connect($this->dbConfig);
    }

    private function assertMode(string $mode): void
    {
        $tables = $this->pdo?->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?? [];
        if ($mode === 'fresh' && $tables !== []) throw new RuntimeException('Mode fresh memerlukan database kosong.');
        if ($mode === 'upgrade') {
            foreach (['database_migrations', 'system_settings', 'users'] as $required) {
                if (!in_array($required, $tables, true)) throw new RuntimeException('Mode upgrade memerlukan pemasangan lengkap.');
            }
            $status = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
            if ($status !== 'complete' || !is_file(APP_ROOT . '/storage/installed.lock')) throw new RuntimeException('Mode upgrade memerlukan pemasangan complete dan installed.lock.');
            $instance = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_instance'")->fetchColumn();
            if (!is_string($instance) || !hash_equals(strtolower($this->env['APP_INSTANCE']), strtolower($instance))) throw new RuntimeException('APP_INSTANCE tidak sepadan.');
            Crypto::key();
        }
    }

    private function snapshotFiles(): void
    {
        $snapshot = [];
        foreach (self::MUTATED_FILES as $relative) {
            $path = APP_ROOT . '/' . $relative;
            if (is_link($path)) throw new RuntimeException('Symlink konfigurasi ditolak: ' . $relative);
            $snapshot[$relative] = is_file($path)
                ? ['exists' => true, 'mode' => fileperms($path) & 0777, 'content' => base64_encode((string) file_get_contents($path))]
                : ['exists' => false];
        }
        $this->fileBackup = $this->runDirectory . '/files-backup.json';
        Support::secureWrite($this->fileBackup, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->log('INFO', 'Runtime files snapshotted', ['count' => count($snapshot)]);
    }

    private function backupDatabase(): void
    {
        $dump = $this->findBinary('mysqldump');
        if ($dump === null) throw new RuntimeException('mysqldump tidak tersedia.');
        $this->databaseBackup = APP_ROOT . '/storage/backups/cpanel_pre_' . gmdate('Ymd_His') . '_' . $this->runId . '.sql';
        $arguments = [
            '--host=' . $this->dbConfig['host'],
            '--port=' . $this->dbConfig['port'],
            '--user=' . $this->dbConfig['user'],
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--add-drop-table',
            '--result-file=' . $this->databaseBackup,
            (string) $this->dbConfig['name'],
        ];
        $this->runProcess($dump, $arguments, null, $this->runDirectory . '/mysqldump.stderr.log');
        clearstatcache(true, $this->databaseBackup);
        if (!is_file($this->databaseBackup) || filesize($this->databaseBackup) < 100) throw new RuntimeException('Snapshot database tidak lengkap.');
        @chmod($this->databaseBackup, 0600);
        $this->databaseBackupHash = hash_file('sha256', $this->databaseBackup);
        Support::secureWrite($this->databaseBackup . '.sha256', $this->databaseBackupHash . '  ' . basename($this->databaseBackup) . "\n");
        $this->log('INFO', 'Database snapshot completed', ['file' => basename($this->databaseBackup), 'sha256' => $this->databaseBackupHash]);
    }

    private function installProductionFiles(): void
    {
        $this->rewriteEnvironment();
        $this->copyTemplate('.htaccess.production', '.htaccess', 0644);
        $this->copyTemplate('.user.ini.production', '.user.ini', 0644);
        $this->copyTemplate('config/app.php.production', 'config/app.php', 0600);
        foreach (['APP_ENV' => 'production', 'APP_URL' => self::ORIGIN, 'APP_DISPLAY_ERRORS' => '0', 'APP_LOG_ERRORS' => '1'] as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        $loaded = require APP_ROOT . '/config/app.php';
        if (!is_array($loaded) || ($loaded['app_url'] ?? '') !== self::ORIGIN || ($loaded['environment'] ?? '') !== 'production') {
            throw new RuntimeException('config/app.php produksi tidak sah.');
        }
        $this->log('INFO', 'Production root-domain files activated');
    }

    private function rewriteEnvironment(): void
    {
        $file = APP_ROOT . '/.env';
        if (!is_file($file) || is_link($file)) throw new RuntimeException('.env produksi biasa diperlukan.');
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) throw new RuntimeException('.env tidak boleh dibaca.');
        $updates = ['APP_ENV' => 'production', 'APP_URL' => self::ORIGIN, 'APP_DISPLAY_ERRORS' => '0', 'APP_LOG_ERRORS' => '1'];
        $seen = [];
        foreach ($lines as &$line) {
            if (preg_match('/\A\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $match) === 1 && array_key_exists($match[1], $updates)) {
                $line = $match[1] . '=' . $updates[$match[1]];
                $seen[$match[1]] = true;
            }
        }
        unset($line);
        foreach ($updates as $key => $value) if (!isset($seen[$key])) $lines[] = $key . '=' . $value;
        Support::secureWrite($file, implode("\n", $lines) . "\n");
    }

    private function copyTemplate(string $source, string $target, int $mode): void
    {
        $from = APP_ROOT . '/' . $source;
        $to = APP_ROOT . '/' . $target;
        if (!is_file($from) || is_link($from) || is_link($to)) throw new RuntimeException('Templat produksi tidak selamat: ' . $source);
        Support::secureWrite($to, (string) file_get_contents($from));
        if (!chmod($to, $mode)) throw new RuntimeException('Permission fail tidak boleh ditetapkan: ' . $target);
    }

    private function writeMaintenanceLock(): void
    {
        $file = APP_ROOT . '/storage/upgrades/maintenance.lock';
        if (is_file($file) || is_file(APP_ROOT . '/storage/upgrades/blocked.lock')) throw new RuntimeException('Maintenance/recovery lock sedia ada dikesan.');
        Support::secureWrite($file, $this->runId);
    }

    private function clearMaintenanceLock(): void
    {
        $file = APP_ROOT . '/storage/upgrades/maintenance.lock';
        if (!is_file($file)) return;
        if (!hash_equals($this->runId, trim((string) file_get_contents($file)))) throw new RuntimeException('Maintenance lock dimiliki proses lain.');
        if (!unlink($file)) throw new RuntimeException('Maintenance lock tidak boleh dibuang.');
    }

    private function writeBlockedLock(): void
    {
        if ($this->runId === '') return;
        try {
            Support::secureWrite(APP_ROOT . '/storage/upgrades/blocked.lock', $this->runId);
        } catch (Throwable) {
        }
    }

    private function migrateFresh(): int
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        if (is_file(APP_ROOT . '/config/core.key') || is_file(APP_ROOT . '/storage/installed.lock')) {
            throw new RuntimeException('Mode fresh menolak core.key atau installed.lock sedia ada.');
        }
        $instance = strtolower($this->env['APP_INSTANCE']);
        Migrations::runFresh($this->pdo, $instance);
        $this->phaseReport[] = ['phase' => 'Phase 0', 'status' => 'APPLIED', 'migrations' => count(glob(APP_ROOT . '/database/migrations/*.sql') ?: [])];

        $core = Upgrade::files();
        $this->applyFiles($core, static fn(string $file): string => basename($file));
        Database::query($this->pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('phase','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        $this->phaseReport[] = ['phase' => 'Phase 1', 'status' => 'APPLIED', 'migrations' => count($core)];

        $training = glob(APP_ROOT . '/database/modules/training/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($training);
        $this->applyFiles($training, static fn(string $file): string => 'training:T1:' . basename($file));
        Database::query($this->pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('training_t1_version','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $trainingFull = glob(APP_ROOT . '/database/modules/training_complete/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($trainingFull);
        $this->applyFiles($trainingFull, static fn(string $file): string => 'training:FULL:' . basename($file));
        Database::query($this->pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('training_full_version','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $this->phaseReport[] = ['phase' => 'Training T1-T6', 'status' => 'APPLIED', 'migrations' => count($training) + count($trainingFull)];

        $eforms = glob(APP_ROOT . '/database/modules/eforms/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($eforms);
        $this->applyFiles($eforms, static fn(string $file): string => 'eforms:Phase2:' . basename($file));
        $this->phaseReport[] = ['phase' => 'Phase 2 e-Forms & Public Programs', 'status' => 'APPLIED', 'migrations' => count($eforms)];

        $actor = $this->createInitialAdmin();
        $this->log('INFO', 'Fresh migrations completed', ['actor_id' => $actor, 'phases' => $this->phaseReport]);
        return $actor;
    }

    private function migrateExisting(?int $requestedActor): int
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        $actor = $this->resolveSuperAdmin($requestedActor);
        Migrations::verify($this->pdo);
        Upgrade::apply($this->pdo, $actor);
        TrainingMigrations::apply($this->pdo, $actor);
        ExtendedMigrations::apply($this->pdo, $actor);
        require_once APP_ROOT . '/app/Modules/EForms/Migrations.php';
        \EPejabat\Modules\EForms\Migrations::apply($this->pdo, $actor);
        $this->phaseReport = [
            ['phase' => 'Phase 0', 'status' => 'VERIFIED'],
            ['phase' => 'Phase 1', 'status' => 'APPLIED_OR_VERIFIED'],
            ['phase' => 'Training T1-T6', 'status' => 'APPLIED_OR_VERIFIED'],
            ['phase' => 'Phase 2 e-Forms & Public Programs', 'status' => 'APPLIED_OR_VERIFIED'],
        ];
        $this->log('INFO', 'Upgrade migrations completed', ['actor_id' => $actor]);
        return $actor;
    }

    /** @param list<string> $files */
    private function applyFiles(array $files, callable $version): void
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        foreach ($files as $file) {
            $migration = $version($file);
            if (Database::query($this->pdo, 'SELECT 1 FROM database_migrations WHERE version=?', [$migration])->fetchColumn()) {
                throw new RuntimeException('Migration fresh berulang: ' . $migration);
            }
            Database::query($this->pdo, "INSERT INTO database_migrations(version,checksum,status) VALUES(?,?,'started')", [$migration, hash_file('sha256', $file)]);
            if (str_ends_with($file, '.sql')) {
                $sql = file_get_contents($file);
                if (!is_string($sql)) throw new RuntimeException('Migration tidak boleh dibaca: ' . basename($file));
                $this->pdo->exec($sql);
            } else {
                $runner = require $file;
                if (!is_callable($runner)) throw new RuntimeException('Migration PHP tidak sah: ' . basename($file));
                $runner($this->pdo);
            }
            Database::query($this->pdo, "UPDATE database_migrations SET status='applied',applied_at=UTC_TIMESTAMP() WHERE version=?", [$migration]);
        }
    }

    private function createInitialAdmin(): int
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        $name = trim((string) getenv('EPEJABAT_ADMIN_NAME'));
        $username = strtolower(trim((string) getenv('EPEJABAT_ADMIN_USERNAME')));
        $email = strtolower(trim((string) getenv('EPEJABAT_ADMIN_EMAIL')));
        $orgCode = trim((string) getenv('EPEJABAT_ORG_CODE'));
        $orgName = trim((string) getenv('EPEJABAT_ORG_NAME'));
        $password = (string) getenv('EPEJABAT_ADMIN_PASSWORD');
        if ($password === '') $password = $this->hiddenPassword();
        putenv('EPEJABAT_ADMIN_PASSWORD');
        unset($_ENV['EPEJABAT_ADMIN_PASSWORD'], $_SERVER['EPEJABAT_ADMIN_PASSWORD']);

        if ($name === '' || mb_strlen($name) > 190 || preg_match('/\A[a-z0-9_.-]{3,64}\z/', $username) !== 1
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72
            || str_contains($password, "\0") || preg_match('/\A[A-Za-z0-9_-]{2,50}\z/', $orgCode) !== 1
            || $orgName === '' || mb_strlen($orgName) > 190) {
            $password = '';
            throw new RuntimeException('Maklumat Super Admin/organisasi fresh tidak sah.');
        }

        $this->pdo->beginTransaction();
        try {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('Akaun sedia ada dikesan.');
            $uuid = self::uuid();
            Database::query($this->pdo, 'INSERT INTO staff_identities(identity_uuid) VALUES(?)', [$uuid]);
            $staff = (int) $this->pdo->lastInsertId();
            Database::query($this->pdo, 'INSERT INTO staff_profiles(staff_identity_id,full_name,official_email) VALUES(?,?,?)', [$staff, $name, $email]);
            Database::query($this->pdo, 'INSERT INTO users(staff_identity_id,username,email,password_hash) VALUES(?,?,?,?)', [$staff, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $actor = (int) $this->pdo->lastInsertId();
            $password = '';
            Database::query($this->pdo, "INSERT INTO user_role_assignments(user_id,role_id,organisation_id,include_descendants,module_code) SELECT ?,id,NULL,1,'core' FROM roles WHERE code='super_admin'", [$actor]);
            Database::query($this->pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('deployment_initial_org_code',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$orgCode]);
            Database::query($this->pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('deployment_initial_org_name',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$orgName]);
            $this->pdo->commit();
            return $actor;
        } catch (Throwable $error) {
            $password = '';
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function hiddenPassword(): string
    {
        if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || DIRECTORY_SEPARATOR === '\\') {
            throw new RuntimeException('Tetapkan EPEJABAT_ADMIN_PASSWORD melalui environment untuk sesi CLI bukan interaktif.');
        }
        $state = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($state === '' || preg_match('/\A[0-9a-fA-F:]+\z/', $state) !== 1) throw new RuntimeException('Input password tersembunyi tidak tersedia.');
        try {
            shell_exec('stty -echo 2>/dev/null');
            fwrite(STDERR, 'Kata laluan Super Admin (hidden): ');
            return rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
            fwrite(STDERR, "\n");
        }
    }

    private function finalize(string $mode, int $actor): void
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        if ($mode === 'fresh') {
            Crypto::initialize();
            Database::query($this->pdo, "UPDATE system_settings SET setting_value='complete' WHERE setting_key='installation_status'");
            if (!defined('FRESH_INSTALL_MIGRATION')) define('FRESH_INSTALL_MIGRATION', true);
            $code = (string) Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='deployment_initial_org_code'")->fetchColumn();
            $name = (string) Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='deployment_initial_org_name'")->fetchColumn();
            FreshPlatform::complete($this->pdo, $actor, [$code, $name]);
            Database::query($this->pdo, "DELETE FROM system_settings WHERE setting_key IN ('deployment_initial_org_code','deployment_initial_org_name')");
        } else {
            Crypto::key();
            $status = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
            if ($status !== 'complete') throw new RuntimeException('Status pemasangan berubah semasa upgrade.');
        }

        $checksum = $this->deploymentChecksum();
        Support::secureWrite(APP_ROOT . '/storage/installed.lock', json_encode([
            'instance' => strtolower($this->env['APP_INSTANCE']),
            'completed_at' => gmdate('c'),
            'deployment_run' => $this->runId,
            'deployment_sha256' => $checksum,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $setup = APP_ROOT . '/config/setup.php';
        if (is_file($setup) && !is_link($setup) && !unlink($setup)) throw new RuntimeException('config/setup.php tidak boleh dibuang.');
        $this->log('INFO', 'Finalization candidate created', ['deployment_sha256' => $checksum]);
    }

    /** @return array<int,array{name:string,status:string,detail:string}> */
    private function schemaTests(int $actor, bool $final): array
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        $tests = [];
        $this->test($tests, 'Phase 0 checksum integrity', function (): string {
            Migrations::verify($this->pdo);
            return 'verified';
        });
        $this->test($tests, 'Complete migration ledger', function (): string {
            $expected = $this->expectedMigrations();
            foreach ($expected as $version => $checksum) {
                $row = Database::query($this->pdo, 'SELECT checksum,status FROM database_migrations WHERE version=?', [$version])->fetch();
                if (!$row || $row['status'] !== 'applied' || !hash_equals($checksum, (string) $row['checksum'])) throw new RuntimeException('Ledger mismatch: ' . $version);
            }
            $started = (int) Database::query($this->pdo, "SELECT COUNT(*) FROM database_migrations WHERE status<>'applied'")->fetchColumn();
            if ($started !== 0) throw new RuntimeException('Ledger masih mempunyai migration tidak lengkap.');
            return count($expected) . ' applied';
        });
        $this->test($tests, 'e-Forms schema integrity', function (): string {
            $this->requireColumns('eforms_templates', ['id', 'organisation_id', 'status', 'created_by']);
            $this->requireColumns('eforms_submissions', ['version_id', 'organisation_id', 'applicant_id', 'payload_cipher', 'status']);
            foreach (['eforms_versions', 'eforms_history', 'eforms_attachments', 'eforms_package_stages', 'eforms_package_forms', 'eforms_package_imports'] as $table) $this->requireTable($table);
            $version = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='module_version.eforms'")->fetchColumn();
            if ($version !== '1.3.0') throw new RuntimeException('Versi e-Forms tidak sah.');
            return '1.3.0';
        });
        $this->test($tests, 'Public Programs schema integrity', function (): string {
            $this->requireColumns('public_programs', ['organisation_id', 'public_code', 'quota', 'version_id']);
            $this->requireColumns('public_guardians', ['contact_cipher', 'access_hash', 'request_hash']);
            $this->requireColumns('public_participants', ['identity_hash', 'profile_cipher', 'registration_status']);
            $this->requireTable('public_program_attempts');
            $version = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='module_version.public_programs'")->fetchColumn();
            if ($version !== '0.1.0-rc1') throw new RuntimeException('Versi Public Programs tidak sah.');
            return '0.1.0-rc1';
        });
        $this->test($tests, 'Portal content schema integrity', function (): string {
            $this->requireColumns('portal_banners', ['content_type', 'slot', 'image_path', 'target_url', 'updated_by']);
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM portal_banners WHERE (content_type='HERO' AND slot=1) OR (content_type='ACTIVITY' AND slot BETWEEN 1 AND 6) OR (content_type='QUICK_LINK' AND slot BETWEEN 1 AND 4)")->fetchColumn();
            if ($count !== 11) throw new RuntimeException('Slot portal tidak lengkap.');
            return '11 slots';
        });
        $this->test($tests, 'Super Admin RBAC', function () use ($actor): string {
            if (!Authorization::super($this->pdo, $actor) || !Authorization::allowed($this->pdo, $actor, 'core.portal.manage')) throw new RuntimeException('Super Admin permission gagal.');
            return 'authorized';
        });
        $this->test($tests, 'Default staff RBAC deny', function (): string {
            $staffRole = Database::query($this->pdo, "SELECT id FROM roles WHERE code='staff'")->fetchColumn();
            $portal = Database::query($this->pdo, "SELECT p.id FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=? AND p.code='core.portal.manage'", [$staffRole])->fetchColumn();
            $audit = Database::query($this->pdo, "SELECT p.id FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=? AND p.code='core.audit.view'", [$staffRole])->fetchColumn();
            if ($portal || $audit) throw new RuntimeException('Role staff mempunyai permission pentadbiran.');
            return 'portal/audit denied';
        });
        if ($final) {
            $this->test($tests, 'Shared Core ready', fn(): string => Upgrade::ready($this->pdo) ? 'ready' : throw new RuntimeException('Core tidak ready.'));
            $this->test($tests, 'Training ready', fn(): string => TrainingMigrations::ready($this->pdo) && ExtendedMigrations::ready($this->pdo) ? 'ready' : throw new RuntimeException('Training tidak ready.'));
            $this->test($tests, 'Installation finalized', function (): string {
                $status = Database::query($this->pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
                if ($status !== 'complete' || !is_file(APP_ROOT . '/storage/installed.lock')) throw new RuntimeException('Finalization tidak lengkap.');
                Crypto::key();
                return 'complete';
            });
        }
        return $tests;
    }

    /** @return array<int,array{name:string,status:string,detail:string}> */
    private function httpTests(string $origin): array
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        $parts = parse_url($origin);
        $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (!$local && (($parts['path'] ?? '') !== '' || $origin !== self::ORIGIN))) {
            throw new RuntimeException('Origin ujian HTTP tidak sah.');
        }
        $tests = [];
        foreach ([
            ['Front Controller', '/', [200]],
            ['Login route', '/login', [200]],
            ['Public Programs route', '/public-program.php', [200]],
            ['e-Forms authentication route', '/eforms', [303]],
            ['Dashboard authentication route', '/dashboard', [303]],
            ['Public portal-admin deny', '/admin/portal-settings', [403]],
            ['Public audit-admin deny', '/audit', [403]],
            ['Public program-admin deny', '/public-program.php?admin=1', [403]],
        ] as [$name, $path, $expected]) {
            $this->test($tests, (string) $name, function () use ($origin, $path, $expected): string {
                $response = $this->http($origin . $path);
                if (!in_array($response['status'], $expected, true)) throw new RuntimeException('HTTP ' . $response['status'] . ', expected ' . implode('/', $expected));
                return 'HTTP ' . $response['status'];
            });
        }
        foreach (AccessProtection::check($origin) as $probe) {
            $tests[] = ['name' => 'Protected path ' . $probe['path'], 'status' => $probe['status'], 'detail' => 'HTTP ' . $probe['http']];
        }
        $ordinary = $this->ordinarySession();
        try {
            foreach ([
                ['Ordinary user portal-admin deny', '/admin/portal-settings'],
                ['Ordinary user audit deny', '/audit'],
                ['Ordinary user settings deny', '/settings'],
                ['Ordinary user program-admin deny', '/public-program.php?admin=1'],
            ] as [$name, $path]) {
                $this->test($tests, (string) $name, function () use ($origin, $path, $ordinary): string {
                    $response = $this->http($origin . $path, 'epejabat_session=' . $ordinary['session_id']);
                    if ($response['status'] !== 403) throw new RuntimeException('HTTP ' . $response['status'] . ', expected 403');
                    return 'HTTP 403';
                });
            }
        } finally {
            $this->removeOrdinarySession($ordinary);
        }
        return $tests;
    }

    /** @return array{status:int,location:string} */
    private function http(string $url, string $cookie = ''): array
    {
        $headers = [];
        $body = '';
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('cURL tidak boleh dimulakan.');
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'EPejabat-cPanel-Deploy/1.0',
            CURLOPT_COOKIE => $cookie,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) < 4096) $body .= substr($chunk, 0, 4096 - strlen($body));
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = $ok === false ? 0 : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($ok === false) throw new RuntimeException('HTTP probe gagal: ' . $error);
        $location = '';
        foreach ($headers as $header) if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
        return ['status' => $status, 'location' => $location];
    }

    /** @return array{user_id:int,staff_id:int,session_id:string,session_file:string} */
    private function ordinarySession(): array
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        if (ini_get('session.serialize_handler') !== 'php') throw new RuntimeException('Session serialize handler php diperlukan untuk ujian RBAC HTTP.');
        $organisation = (int) $this->pdo->query('SELECT id FROM organisations WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetchColumn();
        if ($organisation < 1) throw new RuntimeException('Organisasi ujian RBAC tidak tersedia.');
        $token = bin2hex(random_bytes(12));
        $this->pdo->beginTransaction();
        try {
            Database::query($this->pdo, 'INSERT INTO staff_identities(identity_uuid) VALUES(?)', [self::uuid()]);
            $staff = (int) $this->pdo->lastInsertId();
            Database::query($this->pdo, 'INSERT INTO staff_profiles(staff_identity_id,full_name,organisation_id,official_email) VALUES(?,?,?,?)', [$staff, 'Deployment Regression User', $organisation, 'deploy-' . $token . '@invalid.local']);
            Database::query($this->pdo, 'INSERT INTO users(staff_identity_id,username,email,password_hash,is_active) VALUES(?,?,?,?,1)', [$staff, 'deploy_' . $token, 'deploy-' . $token . '@invalid.local', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $user = (int) $this->pdo->lastInsertId();
            Database::query($this->pdo, "INSERT INTO user_role_assignments(user_id,role_id,organisation_id,module_code) SELECT ?,id,?,'core' FROM roles WHERE code='staff'", [$user, $organisation]);
            if ((int) Database::query($this->pdo, 'SELECT COUNT(*) FROM user_role_assignments WHERE user_id=?', [$user])->fetchColumn() !== 1) {
                throw new RuntimeException('Role staff fixture regresi tidak tersedia.');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $sessionId = bin2hex(random_bytes(24));
        $sessionFile = APP_ROOT . '/storage/sessions/sess_' . $sessionId;
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $revision = (int) Database::query($this->pdo, 'SELECT credential_revision FROM users WHERE id=?', [$user])->fetchColumn();
        $content = 'csrf|s:64:"' . $csrf . '";user_id|i:' . $user . ';signed_in|i:' . $now . ';last_seen|i:' . $now . ';credential_revision|i:' . $revision . ';';
        Support::secureWrite($sessionFile, $content);
        return ['user_id' => $user, 'staff_id' => $staff, 'session_id' => $sessionId, 'session_file' => $sessionFile];
    }

    /** @param array{user_id:int,staff_id:int,session_id:string,session_file:string} $ordinary */
    private function removeOrdinarySession(array $ordinary): void
    {
        if (is_file($ordinary['session_file']) && !unlink($ordinary['session_file'])) throw new RuntimeException('Sesi regresi tidak boleh dibuang.');
        if ($this->pdo === null) return;
        $this->pdo->beginTransaction();
        try {
            Database::query($this->pdo, 'DELETE FROM user_role_assignments WHERE user_id=?', [$ordinary['user_id']]);
            Database::query($this->pdo, 'DELETE FROM users WHERE id=?', [$ordinary['user_id']]);
            Database::query($this->pdo, 'DELETE FROM staff_profiles WHERE staff_identity_id=?', [$ordinary['staff_id']]);
            Database::query($this->pdo, 'DELETE FROM staff_identities WHERE id=?', [$ordinary['staff_id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw new RuntimeException('Fixture regresi tidak boleh dibersihkan.', 0, $error);
        }
    }

    /** @return array<string,string> */
    private function expectedMigrations(): array
    {
        $expected = [];
        $base = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($base);
        foreach ($base as $file) $expected[basename($file, '.sql')] = hash_file('sha256', $file);
        foreach (Upgrade::files() as $file) $expected[basename($file)] = hash_file('sha256', $file);
        foreach ([
            [APP_ROOT . '/database/modules/training/*.{sql,php}', 'training:T1:'],
            [APP_ROOT . '/database/modules/training_complete/*.{sql,php}', 'training:FULL:'],
            [APP_ROOT . '/database/modules/eforms/*.{sql,php}', 'eforms:Phase2:'],
        ] as [$pattern, $prefix]) {
            $files = glob($pattern, GLOB_BRACE) ?: [];
            sort($files);
            foreach ($files as $file) $expected[$prefix . basename($file)] = hash_file('sha256', $file);
        }
        ksort($expected);
        return $expected;
    }

    private function requireTable(string $table): void
    {
        if (!Database::query($this->pdo, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'', [$table])->fetchColumn()) {
            throw new RuntimeException('Jadual tiada: ' . $table);
        }
    }

    /** @param list<string> $columns */
    private function requireColumns(string $table, array $columns): void
    {
        $this->requireTable($table);
        $actual = Database::query($this->pdo, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($columns, $actual));
        if ($missing !== []) throw new RuntimeException('Kolum tiada ' . $table . ': ' . implode(',', $missing));
    }

    /** @param array<int,array{name:string,status:string,detail:string}> $tests */
    private function test(array &$tests, string $name, callable $test): void
    {
        try {
            $detail = $test();
            $tests[] = ['name' => $name, 'status' => 'PASS', 'detail' => is_string($detail) ? $detail : 'verified'];
        } catch (Throwable $error) {
            $tests[] = ['name' => $name, 'status' => 'FAIL', 'detail' => self::safeError($error)];
        }
    }

    /** @param array<int,array<string,mixed>> $tests */
    private function requirePass(array $tests, string $message): void
    {
        $failed = array_values(array_filter($tests, static fn(array $test): bool => ($test['status'] ?? '') !== 'PASS'));
        if ($failed !== []) throw new RuntimeException($message . ' ' . implode('; ', array_map(static fn(array $test): string => ($test['name'] ?? 'test') . ': ' . ($test['detail'] ?? 'failed'), $failed)));
    }

    private static function allPass(array $tests): bool
    {
        return $tests !== [] && count(array_filter($tests, static fn(array $test): bool => ($test['status'] ?? '') === 'PASS')) === count($tests);
    }

    private function resolveSuperAdmin(?int $requested): int
    {
        if ($this->pdo === null) throw new RuntimeException('Database tidak tersedia.');
        $rows = Database::query($this->pdo, "SELECT DISTINCT u.id FROM users u JOIN user_role_assignments a ON a.user_id=u.id JOIN roles r ON r.id=a.role_id WHERE r.code='super_admin' AND a.organisation_id IS NULL AND u.is_active=1 ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
        if ($requested !== null) {
            if (!in_array($requested, array_map('intval', $rows), true) || !Authorization::super($this->pdo, $requested)) throw new RuntimeException('Actor bukan Super Admin aktif.');
            return $requested;
        }
        if (count($rows) !== 1) throw new RuntimeException('--actor diperlukan apabila bilangan Super Admin aktif bukan satu.');
        $actor = (int) $rows[0];
        if (!Authorization::super($this->pdo, $actor)) throw new RuntimeException('Super Admin tidak mempunyai permission migration.');
        return $actor;
    }

    /** @return array<string,mixed> */
    private function officialReport(string $mode, array $tests): array
    {
        $passed = count(array_filter($tests, static fn(array $test): bool => ($test['status'] ?? '') === 'PASS'));
        $total = count($tests);
        if ($passed !== $total || $total === 0) throw new RuntimeException('Laporan rasmi memerlukan 100% pass rate.');
        $report = [
            'format' => 'epejabat-cpanel-deployment-v1',
            'status' => 'DEPLOYED_VERIFIED',
            'run_id' => $this->runId,
            'mode' => $mode,
            'origin' => self::ORIGIN,
            'base_path' => '/',
            'started_at' => $this->startedAt,
            'completed_at' => gmdate('c'),
            'phases' => $this->phaseReport,
            'tests_passed' => $passed,
            'tests_total' => $total,
            'pass_rate' => 100,
            'tests' => $tests,
            'database_backup' => ['file' => basename($this->databaseBackup), 'sha256' => $this->databaseBackupHash],
            'deployment_sha256' => $this->deploymentChecksum(),
        ];
        $path = $this->runDirectory . '/deployment-report.json';
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        Support::secureWrite($path, $json);
        $hash = hash('sha256', $json);
        Support::secureWrite($path . '.sha256', $hash . '  deployment-report.json' . "\n");
        Support::secureWrite(APP_ROOT . '/storage/deployments/latest-report.json', $json);
        $report['report_file'] = $path;
        $report['report_sha256'] = $hash;
        return $report;
    }

    private function deploymentChecksum(): string
    {
        $files = [];
        foreach (['app', 'assets', 'database', 'bin'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APP_ROOT . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) continue;
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen(APP_ROOT) + 1));
                $files[$relative] = $item->getPathname();
            }
        }
        foreach (['index.php', 'bootstrap.php', 'public-program.php', '.htaccess', '.user.ini', 'config/app.php', 'composer.json', 'composer.lock'] as $relative) {
            $path = APP_ROOT . '/' . $relative;
            if (is_file($path) && !is_link($path)) $files[$relative] = $path;
        }
        ksort($files);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $path) hash_update($hash, $relative . "\0" . hash_file('sha256', $path) . "\n");
        return hash_final($hash);
    }

    /** @return array<string,mixed> */
    private function rollback(): array
    {
        $this->writeMaintenanceLockForRollback();
        $database = $this->restoreDatabase();
        $files = $this->restoreFiles();
        $this->clearMaintenanceAfterRollback();
        return ['status' => 'ROLLED_BACK', 'database' => $database, 'files' => $files];
    }

    private function writeMaintenanceLockForRollback(): void
    {
        $file = APP_ROOT . '/storage/upgrades/maintenance.lock';
        if (is_file($file)) {
            $owner = trim((string) file_get_contents($file));
            if ($owner !== $this->runId) throw new RuntimeException('Rollback lock dimiliki proses lain.');
            return;
        }
        Support::secureWrite($file, $this->runId);
    }

    /** @return array<string,mixed> */
    private function restoreDatabase(): array
    {
        if ($this->databaseBackup === '' || !is_file($this->databaseBackup)
            || !hash_equals($this->databaseBackupHash, hash_file('sha256', $this->databaseBackup))) {
            throw new RuntimeException('Snapshot rollback database tidak sah.');
        }
        $pdo = Database::connect($this->dbConfig);
        $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $views = [];
        $tables = [];
        foreach ($objects as $object) {
            $name = (string) ($object[0] ?? '');
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) throw new RuntimeException('Nama objek DB rollback tidak sah.');
            if (($object[1] ?? '') === 'VIEW') $views[] = '`' . $name . '`';
            else $tables[] = '`' . $name . '`';
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        if ($views !== []) $pdo->exec('DROP VIEW IF EXISTS ' . implode(',', $views));
        if ($tables !== []) $pdo->exec('DROP TABLE IF EXISTS ' . implode(',', $tables));
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo = null;
        $this->pdo = null;

        $mysql = $this->findBinary('mysql');
        if ($mysql === null) throw new RuntimeException('mysql CLI tidak tersedia untuk rollback.');
        $arguments = [
            '--host=' . $this->dbConfig['host'],
            '--port=' . $this->dbConfig['port'],
            '--user=' . $this->dbConfig['user'],
            '--default-character-set=utf8mb4',
            (string) $this->dbConfig['name'],
        ];
        $this->runProcess($mysql, $arguments, $this->databaseBackup, $this->runDirectory . '/mysql-restore.stderr.log');
        $this->connect();
        return ['status' => 'RESTORED', 'sha256' => $this->databaseBackupHash];
    }

    /** @return array<string,mixed> */
    private function restoreFiles(): array
    {
        if ($this->fileBackup === '' || !is_file($this->fileBackup)) throw new RuntimeException('Snapshot fail rollback tiada.');
        $snapshot = json_decode((string) file_get_contents($this->fileBackup), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || array_keys($snapshot) !== self::MUTATED_FILES) throw new RuntimeException('Manifest fail rollback tidak sah.');
        foreach (self::MUTATED_FILES as $relative) {
            $entry = $snapshot[$relative];
            $path = APP_ROOT . '/' . $relative;
            if (is_link($path)) throw new RuntimeException('Symlink rollback ditolak: ' . $relative);
            if (!empty($entry['exists'])) {
                $content = base64_decode((string) ($entry['content'] ?? ''), true);
                if ($content === false) throw new RuntimeException('Kandungan rollback rosak: ' . $relative);
                Support::secureWrite($path, $content);
                @chmod($path, (int) ($entry['mode'] ?? 0600));
            } elseif (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Fail baharu tidak boleh dibuang semasa rollback: ' . $relative);
            }
        }
        return ['status' => 'RESTORED', 'count' => count(self::MUTATED_FILES)];
    }

    private function clearMaintenanceAfterRollback(): void
    {
        foreach (['maintenance.lock', 'blocked.lock'] as $name) {
            $file = APP_ROOT . '/storage/upgrades/' . $name;
            if (is_file($file) && trim((string) file_get_contents($file)) === $this->runId) @unlink($file);
        }
    }

    private function runProcess(string $binary, array $arguments, ?string $stdinFile, string $stderrFile): void
    {
        $command = array_merge([$binary], array_map('strval', $arguments));
        $descriptors = [
            0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
            1 => ['file', $this->runDirectory . '/process.stdout.log', 'a'],
            2 => ['file', $stderrFile, 'a'],
        ];
        $environment = getenv();
        if (!is_array($environment)) $environment = [];
        $environment['MYSQL_PWD'] = (string) $this->dbConfig['password'];
        $process = proc_open($command, $descriptors, $pipes, APP_ROOT, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Proses CLI database tidak boleh dimulakan.');
        if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        $exit = proc_close($process);
        unset($environment['MYSQL_PWD']);
        if ($exit !== 0) {
            $detail = is_file($stderrFile) ? trim(substr((string) file_get_contents($stderrFile), -4000)) : '';
            throw new RuntimeException('Proses CLI database gagal (exit ' . $exit . '). ' . $detail);
        }
    }

    private function findBinary(string $name): ?string
    {
        $envKey = $name === 'mysql' ? 'EPEJABAT_MYSQL_BIN' : 'EPEJABAT_MYSQLDUMP_BIN';
        $specified = getenv($envKey);
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $candidates = [];
        if (is_string($specified) && $specified !== '') $candidates[] = $specified;
        $path = getenv('PATH');
        if (is_string($path)) foreach (explode(PATH_SEPARATOR, $path) as $directory) if ($directory !== '') $candidates[] = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . $suffix;
        $xampp = dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name . $suffix;
        $candidates = [...$candidates, $xampp, '/usr/bin/' . $name, '/usr/local/bin/' . $name];
        foreach (array_unique($candidates) as $candidate) if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) return $candidate;
        return null;
    }

    /** @return array<string,mixed> */
    private function activeConfig(): array
    {
        $file = APP_ROOT . '/config/app.php';
        if (!is_file($file)) throw new RuntimeException('config/app.php tidak tersedia.');
        $config = require $file;
        if (!is_array($config) || !is_array($config['db'] ?? null)) throw new RuntimeException('config/app.php tidak sah.');
        return $config;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logFile === '') return;
        $line = json_encode(['at' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $context], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('Log deployment tidak boleh ditulis.');
        @chmod($this->logFile, 0600);
    }

    /** @return array<string,string> */
    private static function readEnv(string $file): array
    {
        if (!is_file($file) || is_link($file)) throw new RuntimeException('.env diperlukan dan tidak boleh berupa symlink.');
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) throw new RuntimeException('.env tidak boleh dibaca.');
        $env = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (preg_match('/\A([A-Z][A-Z0-9_]*)=(.*)\z/s', $line, $match) !== 1) continue;
            $value = trim($match[2], " \t\n\r\0\x0B\"'");
            $env[$match[1]] = $value;
            putenv($match[1] . '=' . $value);
            $_ENV[$match[1]] = $value;
            $_SERVER[$match[1]] = $value;
        }
        return $env;
    }

    /** @param array<string,string> $options @param list<string> $allowed @return array<string,string> */
    private static function onlyOptions(array $options, array $allowed): array
    {
        $unknown = array_values(array_diff(array_keys($options), $allowed));
        if ($unknown !== []) throw new RuntimeException('Pilihan tidak disokong: --' . implode(', --', $unknown));
        return $options;
    }

    private static function positiveInt(string $value, string $name): int
    {
        if (!ctype_digit($value) || (int) $value < 1) throw new RuntimeException('--' . $name . ' mesti integer positif.');
        return (int) $value;
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-' . dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    private static function safeError(Throwable $error): string
    {
        $message = preg_replace('/password\s*=\s*[^\s;]+/i', 'password=[REDACTED]', $error->getMessage()) ?? 'Deployment error';
        return get_class($error) . ': ' . substr($message, 0, 2000);
    }

    private static function safeTrace(Throwable $error): string
    {
        return substr($error->getTraceAsString(), 0, 12000);
    }
}
