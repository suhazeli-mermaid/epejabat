<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI sahaja.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'EPejabat\\Core\\' => APP_ROOT . '/app/Core/',
        'EPejabat\\Modules\\Training\\' => APP_ROOT . '/app/Modules/Training/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $name = substr($class, strlen($prefix));
        if (preg_match('/\A[A-Za-z]+\z/', $name) !== 1) return;
        $file = $directory . $name . '.php';
        if (is_file($file)) require $file;
        return;
    }
});

use EPejabat\Core\Database;
use EPejabat\Core\Migrations;
use EPejabat\Core\Upgrade;

/** @return array<string,mixed> */
function deploymentConfig(): array
{
    $file = APP_ROOT . '/config/app.php';
    if (!is_file($file)) throw new RuntimeException('config/app.php tempatan diperlukan untuk membina SQL.');
    $config = require $file;
    if (!is_array($config) || !is_array($config['db'] ?? null)) throw new RuntimeException('Konfigurasi DB tidak sah.');
    return $config['db'];
}

/** @param list<string> $files */
function applyMigrationFiles(PDO $pdo, array $files, callable $version): void
{
    foreach ($files as $file) {
        $migration = $version($file);
        Database::query(
            $pdo,
            "INSERT INTO database_migrations(version,checksum,status) VALUES(?,?,'started')",
            [$migration, hash_file('sha256', $file)]
        );
        if (basename($file) === '103_organisation_type_check.php'
            && str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
            // MariaDB 10.4 can terminate when CHECK_CONSTRAINTS is joined only
            // by its non-unique constraint name. Reproduce this migration's
            // intended result through SHOW CREATE TABLE in the disposable DB.
            $create = $pdo->query('SHOW CREATE TABLE organisations')->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || !isset($create[1])) throw new RuntimeException('DDL organisations tidak tersedia.');
            preg_match_all('/CONSTRAINT `([A-Za-z0-9_]+)` CHECK \(([^\r\n]+)\)/i', (string) $create[1], $checks, PREG_SET_ORDER);
            foreach ($checks as $check) {
                if (preg_match('/`?type`?/i', $check[2]) === 1) {
                    $pdo->exec('ALTER TABLE organisations DROP CONSTRAINT ' . quoteIdentifier($check[1]));
                }
            }
        } elseif (str_ends_with($file, '.sql')) {
            $sql = file_get_contents($file);
            if (!is_string($sql)) throw new RuntimeException('Migration tidak boleh dibaca: ' . basename($file));
            $pdo->exec($sql);
        } else {
            $runner = require $file;
            if (!is_callable($runner)) throw new RuntimeException('Migration PHP tidak sah: ' . basename($file));
            $runner($pdo);
        }
        Database::query(
            $pdo,
            "UPDATE database_migrations SET status='applied',applied_at=UTC_TIMESTAMP() WHERE version=?",
            [$migration]
        );
    }
}

function quoteIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-zA-Z0-9_]+\z/', $identifier) !== 1) throw new RuntimeException('Identifier SQL tidak sah.');
    return '`' . $identifier . '`';
}

/** @param list<string> $tables */
function exportSql(PDO $pdo, array $tables): string
{
    $sql = <<<'SQL'
-- e-Pejabat clean installation database
-- Generated only from versioned Phase 0, Phase 1, Training, e-Forms and Public Programs migrations.
-- Contains structure and non-personal seed data only. Import into an EMPTY database.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET @EPEJABAT_INSTANCE = LOWER(REPLACE(UUID(), '-', ''));

SQL;

    foreach ($tables as $table) {
        $row = $pdo->query('SHOW CREATE TABLE ' . quoteIdentifier($table))->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !isset($row[1])) throw new RuntimeException('DDL tidak tersedia untuk ' . $table);
        $ddl = preg_replace('/\sAUTO_INCREMENT=\d+\b/', '', (string) $row[1]);
        $sql .= "\n-- Table: {$table}\n{$ddl};\n";
    }

    $seedTables = [
        'module_registry',
        'master_data',
        'roles',
        'permissions',
        'role_permissions',
        'notification_templates',
        'portal_banners',
        'system_settings',
        'database_migrations',
    ];

    foreach ($seedTables as $table) {
        if (!in_array($table, $tables, true)) continue;
        $identifier = quoteIdentifier($table);
        $statement = $pdo->query("SELECT * FROM {$identifier}");
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) continue;
        $columns = array_keys($rows[0]);
        $sql .= "\n-- Safe seed data: {$table}\nINSERT INTO {$identifier} ("
            . implode(',', array_map('quoteIdentifier', $columns)) . ") VALUES\n";
        $values = [];
        foreach ($rows as $row) {
            $encoded = [];
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($table === 'system_settings' && $column === 'setting_value'
                    && ($row['setting_key'] ?? '') === 'installation_instance') {
                    $encoded[] = '@EPEJABAT_INSTANCE';
                } else {
                    $encoded[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }
            }
            $values[] = '(' . implode(',', $encoded) . ')';
        }
        $sql .= implode(",\n", $values) . ";\n";
    }

    $sql .= <<<'SQL'

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Salin nilai yang dipaparkan ke APP_INSTANCE dalam .env produksi.
SELECT setting_value AS APP_INSTANCE
FROM system_settings
WHERE setting_key = 'installation_instance';
SQL;
    return $sql . "\n";
}

$db = deploymentConfig();
$temporaryDatabase = 'epejabat_build_' . bin2hex(random_bytes(6));
$output = APP_ROOT . '/deployment/sql/epejabat_clean_install.sql';
$checksumOutput = APP_ROOT . '/deployment/sql/SHA256SUMS.txt';
$admin = null;
$pdo = null;

try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) ($db['port'] ?? 3306));
    $admin = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $admin->exec('CREATE DATABASE ' . quoteIdentifier($temporaryDatabase) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = new PDO($dsn . ';dbname=' . $temporaryDatabase, (string) $db['user'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    Migrations::runFresh($pdo, str_repeat('0', 32));
    Database::query($pdo, "UPDATE system_settings SET setting_value='complete' WHERE setting_key='installation_status'");

    applyMigrationFiles($pdo, Upgrade::files(), static fn(string $file): string => basename($file));
    Database::query($pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('phase','1') ON DUPLICATE KEY UPDATE setting_value='1'");

    $training = glob(APP_ROOT . '/database/modules/training/*.{sql,php}', GLOB_BRACE) ?: [];
    sort($training);
    applyMigrationFiles($pdo, $training, static fn(string $file): string => 'training:T1:' . basename($file));
    Database::query($pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('training_t1_version','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

    $trainingFull = glob(APP_ROOT . '/database/modules/training_complete/*.{sql,php}', GLOB_BRACE) ?: [];
    sort($trainingFull);
    applyMigrationFiles($pdo, $trainingFull, static fn(string $file): string => 'training:FULL:' . basename($file));
    Database::query($pdo, "INSERT INTO system_settings(setting_key,setting_value) VALUES('training_full_version','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

    $eforms = glob(APP_ROOT . '/database/modules/eforms/*.{sql,php}', GLOB_BRACE) ?: [];
    sort($eforms);
    applyMigrationFiles($pdo, $eforms, static fn(string $file): string => 'eforms:Phase2:' . basename($file));

    Database::query($pdo, "UPDATE system_settings SET setting_value='pending_admin' WHERE setting_key='installation_status'");
    Database::query($pdo, "UPDATE system_settings SET setting_value=? WHERE setting_key='installation_instance'", [str_repeat('0', 32)]);

    $tables = Database::query(
        $pdo,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $sql = exportSql($pdo, array_values(array_map('strval', $tables)));

    if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true)) {
        throw new RuntimeException('Direktori output tidak boleh dicipta.');
    }
    $temporaryOutput = $output . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporaryOutput, $sql, LOCK_EX) === false || !rename($temporaryOutput, $output)) {
        @unlink($temporaryOutput);
        throw new RuntimeException('Fail SQL tidak boleh ditulis.');
    }
    @chmod($output, 0644);
    $checksumLine = hash('sha256', $sql) . "  epejabat_clean_install.sql\n";
    if (file_put_contents($checksumOutput, $checksumLine, LOCK_EX) === false) {
        throw new RuntimeException('Checksum SQL tidak boleh ditulis.');
    }
    @chmod($checksumOutput, 0644);
    fwrite(STDOUT, sprintf("Generated %s (%d tables, %d bytes)\n", $output, count($tables), strlen($sql)));
} finally {
    // Release the connection using the temporary schema before dropping it;
    // otherwise MariaDB waits indefinitely on its schema metadata lock.
    $pdo = null;
    if ($admin instanceof PDO) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($temporaryDatabase));
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Amaran: DB sementara perlu dibuang manual: ' . $temporaryDatabase . "\n");
        }
    }
}
