<?php
declare(strict_types=1);

namespace EPejabat\Core;

use PDO;
use RuntimeException;

/** Idempotent schema/bootstrap state manager used by the cPanel CLI runner. */
final class CpanelUpgradeManager
{
    private const VERSION_PATTERN = '/^-- epejabat-schema-version: ([0-9]+\.[0-9]+\.[0-9]+)\s*$/m';

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{version:string,checksum:string,status:string} */
    public function prepare(PDO $pdo, string $instance, string $mode): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $instance) !== 1 || !in_array($mode, ['fresh', 'upgrade'], true)) {
            throw new RuntimeException('Konteks Upgrade Manager tidak sah.');
        }

        $schema = $this->schemaMetadata();
        $pdo->exec($schema['sql']);
        $this->requireSettingsShape($pdo);

        $currentInstance = Database::query($pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_instance'")->fetchColumn();
        if (is_string($currentInstance) && !hash_equals(strtolower($currentInstance), $instance)) {
            throw new RuntimeException('APP_INSTANCE bercanggah dengan system_settings.');
        }

        if ($mode === 'upgrade') {
            $status = Database::query($pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
            if ($status === false) {
                if (!$this->recoverableSettings($pdo)) throw new RuntimeException('system_settings tidak boleh dipulihkan secara automatik.');
                $this->set($pdo, 'installation_status', 'complete');
            } elseif ($status !== 'complete') {
                throw new RuntimeException('Upgrade memerlukan installation_status complete.');
            }
        } elseif (Database::query($pdo, "SELECT 1 FROM system_settings WHERE setting_key='installation_status'")->fetchColumn() === false) {
            $this->set($pdo, 'installation_status', 'pending_admin');
        }

        foreach ([
            'app_name' => 'epejabat.net',
            'timezone' => 'Asia/Kuala_Lumpur',
            'installation_instance' => $instance,
            'schema_version' => $schema['version'],
            'schema_checksum' => $schema['checksum'],
            'schema_updated_at' => gmdate('c'),
        ] as $key => $value) {
            if (in_array($key, ['app_name', 'timezone'], true)
                && Database::query($pdo, 'SELECT 1 FROM system_settings WHERE setting_key=?', [$key])->fetchColumn()) continue;
            $this->set($pdo, $key, $value);
        }
        $this->synchronizeVersions($pdo);

        return ['version' => $schema['version'], 'checksum' => $schema['checksum'], 'status' => 'READY'];
    }

    public function canRecoverSettings(PDO $pdo): bool
    {
        return $this->recoverableSettings($pdo);
    }

    /** @return array{status:string,detail:string} */
    public function verify(PDO $pdo): array
    {
        $this->requireSettingsShape($pdo);
        $schema = $this->schemaMetadata();
        $values = Database::query(
            $pdo,
            "SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('schema_version','schema_checksum')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($values === []) return ['status' => 'PASS', 'detail' => 'compatible legacy schema'];
        if (($values['schema_version'] ?? null) !== $schema['version']
            || !is_string($values['schema_checksum'] ?? null)
            || !hash_equals($schema['checksum'], $values['schema_checksum'])) {
            throw new RuntimeException('Versi/checksum database/schema.sql tidak sepadan.');
        }
        return ['status' => 'PASS', 'detail' => $schema['version'] . ' / ' . $schema['checksum']];
    }

    public function markComplete(PDO $pdo): void
    {
        $schema = $this->schemaMetadata();
        $this->synchronizeVersions($pdo);
        $this->set($pdo, 'schema_version', $schema['version']);
        $this->set($pdo, 'schema_checksum', $schema['checksum']);
        $this->set($pdo, 'schema_updated_at', gmdate('c'));
        $this->set($pdo, 'installation_status', 'complete');
    }

    public function writeInstalledLock(PDO $pdo, string $instance, string $runId, string $deploymentChecksum): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $instance) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $runId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $deploymentChecksum) !== 1) {
            throw new RuntimeException('Metadata installed.lock tidak sah.');
        }
        $status = Database::query($pdo, "SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
        if ($status !== 'complete') throw new RuntimeException('installation_status belum complete.');
        $file = $this->root . '/storage/installed.lock';
        if (is_link($file)) throw new RuntimeException('Symlink installed.lock ditolak.');
        Support::secureWrite($file, json_encode([
            'instance' => $instance,
            'completed_at' => gmdate('c'),
            'deployment_run' => $runId,
            'deployment_sha256' => $deploymentChecksum,
            'schema_version' => $this->schemaMetadata()['version'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function recoverableSettings(PDO $pdo): bool
    {
        foreach (['database_migrations', 'users'] as $table) {
            if (!Database::query($pdo, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->fetchColumn()) return false;
        }
        $migration = $this->root . '/database/migrations/011_system_settings.sql';
        if (!is_file($migration) || is_link($migration)) return false;
        $row = Database::query($pdo, "SELECT checksum,status FROM database_migrations WHERE version='011_system_settings'")->fetch();
        return is_array($row) && ($row['status'] ?? '') === 'applied'
            && is_string($row['checksum'] ?? null) && hash_equals(hash_file('sha256', $migration), $row['checksum'])
            && is_file($this->root . '/config/core.key') && !is_link($this->root . '/config/core.key')
            && is_file($this->root . '/storage/installed.lock') && !is_link($this->root . '/storage/installed.lock');
    }

    private function requireSettingsShape(PDO $pdo): void
    {
        $columns = Database::query(
            $pdo,
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_settings'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(['setting_key', 'setting_value', 'updated_at'], $columns)) {
            throw new RuntimeException('Struktur system_settings tidak serasi.');
        }
    }

    private function set(PDO $pdo, string $key, string $value): void
    {
        Database::query(
            $pdo,
            'INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
            [$key, $value]
        );
    }

    private function setIfMissing(PDO $pdo, string $key, string $value): void
    {
        Database::query($pdo, 'INSERT IGNORE INTO system_settings(setting_key,setting_value) VALUES(?,?)', [$key, $value]);
    }

    private function ledgerApplied(PDO $pdo, string $version): bool
    {
        if (!Database::query($pdo, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='database_migrations'")->fetchColumn()) return false;
        return (bool) Database::query($pdo, "SELECT 1 FROM database_migrations WHERE version=? AND status='applied'", [$version])->fetchColumn();
    }

    private function synchronizeVersions(PDO $pdo): void
    {
        if ($this->ledgerApplied($pdo, '201_portal_content.sql')) {
            foreach ([
                'phase' => '1',
                'app_version' => '1.0.0',
                'core_version' => '1.0.0',
                'module_version.core' => '1.0.0',
                'required_php' => '8.2',
                'migration_version' => '200_platform_versioning.sql',
                'installed_at' => gmdate('c'),
            ] as $key => $value) $this->setIfMissing($pdo, $key, $value);
        }
        if ($this->ledgerApplied($pdo, 'training:T1:002_seeds.php')) {
            $this->setIfMissing($pdo, 'training_t1_version', '1.0.0');
            $this->setIfMissing($pdo, 'default_staff_role.training', 'training_participant');
        }
        if ($this->ledgerApplied($pdo, 'training:FULL:002_permissions.php')) {
            $this->setIfMissing($pdo, 'training_full_version', '1.0.0');
            $this->setIfMissing($pdo, 'module_version.training', '1.0.0');
        }
        if ($this->ledgerApplied($pdo, 'eforms:Phase2:212_package_version.php')) {
            $this->setIfMissing($pdo, 'module_version.eforms', '1.3.0');
        }
        if ($this->ledgerApplied($pdo, 'eforms:Phase2:221_public_program_seed.php')) {
            $this->setIfMissing($pdo, 'module_version.public_programs', '0.1.0-rc1');
        }
    }

    /** @return array{version:string,checksum:string,sql:string} */
    private function schemaMetadata(): array
    {
        $file = $this->root . '/database/schema.sql';
        if (!is_file($file) || is_link($file)) throw new RuntimeException('database/schema.sql tidak tersedia atau tidak selamat.');
        $sql = file_get_contents($file);
        if (!is_string($sql) || strlen($sql) > 1048576 || preg_match(self::VERSION_PATTERN, $sql, $match) !== 1) {
            throw new RuntimeException('Metadata database/schema.sql tidak sah.');
        }
        if (preg_match('/\b(?:DROP|TRUNCATE|DELETE|REPLACE|ALTER|RENAME|GRANT|REVOKE)\b/i', $sql)
            || preg_match('/^\s*UPDATE\b/im', $sql)) {
            throw new RuntimeException('database/schema.sql bootstrap mengandungi operasi terlarang.');
        }
        return ['version' => $match[1], 'checksum' => hash('sha256', $sql), 'sql' => $sql];
    }
}
