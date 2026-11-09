<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sauvegarde de la base (docs/DEPLOIEMENT.md, § Sauvegardes), même principe que la commande
 * adb:backup d'artisandubenin : une commande Artisan planifiée chaque jour, sans service externe.
 *
 *   - MySQL : mysqldump (mot de passe passé par MYSQL_PWD, jamais en argument visible dans `ps`) ;
 *     si mysqldump est absent ou interdit, dump en PHP pur (CREATE TABLE + INSERT).
 *   - SQLite : dump en PHP pur.
 *   - Fichier compressé storage/backups/db/compteurs_AAAA-MM-JJ_HHMMSS[_motif].sql.gz
 *   - Les sauvegardes de plus de --jours jours (30 par défaut) sont supprimées.
 */
final class BackupDatabase extends Command
{
    public const DIRECTORY = 'backups/db';

    protected $signature = 'compteurs:sauvegarde
        {--jours=30 : Durée de conservation des sauvegardes, en jours}
        {--motif= : Suffixe du nom de fichier (ex. avant-deploiement)}
        {--mysqldump=mysqldump : Chemin du programme mysqldump (« aucun » : dump PHP directement)}';

    protected $description = 'Sauvegarde la base de données (fichier .sql.gz) et supprime les sauvegardes trop anciennes';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('jours'));
        $directory = storage_path(self::DIRECTORY);
        File::ensureDirectoryExists($directory, 0700);

        $motif = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $this->option('motif')));
        $file = sprintf('%s/compteurs_%s%s.sql.gz', $directory, now()->format('Y-m-d_His'), $motif === '' ? '' : '_' . $motif);

        try {
            $method = $this->dump(DB::connection(), $file);
        } catch (Throwable $e) {
            File::delete($file);
            Log::error('Sauvegarde de la base impossible', ['exception' => $e]);
            $this->error('Sauvegarde impossible : ' . $e->getMessage());

            return self::FAILURE;
        }

        chmod($file, 0600);
        $this->info(sprintf('Sauvegarde : %s (%s Ko, %s)', basename($file), number_format(filesize($file) / 1024, 0, ',', ' '), $method));

        $this->prune($directory, $days);

        return self::SUCCESS;
    }

    private function dump(Connection $connection, string $file): string
    {
        if ($connection->getDriverName() === 'mysql' && $this->option('mysqldump') !== 'aucun' && $this->mysqldump($connection, $file)) {
            return 'mysqldump';
        }

        $this->phpDump($connection, $file);

        return 'dump PHP';
    }

    /**
     * @return bool false si mysqldump n'a pas pu être lancé (on passe alors au dump PHP)
     */
    private function mysqldump(Connection $connection, string $file): bool
    {
        $config = $connection->getConfig();

        try {
            $process = new Process([
                (string) $this->option('mysqldump'), '--single-transaction', '--quick', '--no-tablespaces', '--skip-lock-tables',
                '--default-character-set=utf8mb4',
                '--host=' . ($config['host'] ?? '127.0.0.1'),
                '--port=' . ($config['port'] ?? 3306),
                '--user=' . ($config['username'] ?? ''),
                (string) ($config['database'] ?? ''),
            ], env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')], timeout: 600);

            $gz = gzopen($file, 'wb6');
            $process->run(function (string $type, string $buffer) use ($gz): void {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
            gzclose($gz);
        } catch (Throwable) {
            File::delete($file);

            return false;
        }

        if (! $process->isSuccessful()) {
            File::delete($file);
            $this->warn('mysqldump indisponible (' . trim($process->getErrorOutput()) . ') : dump PHP à la place.');

            return false;
        }

        return true;
    }

    /**
     * Dump portable : structure puis données, par lots de 500 lignes.
     */
    private function phpDump(Connection $connection, string $file): void
    {
        $pdo = $connection->getPdo();
        $mysql = $connection->getDriverName() === 'mysql';
        $quote = static fn (string $name): string => $mysql ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';

        $gz = gzopen($file, 'wb6');
        gzwrite($gz, '-- Sauvegarde compteurs du ' . now()->toDateTimeString() . "\n");
        gzwrite($gz, $mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" : "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");

        $tables = $mysql
            ? array_map(static fn ($row): string => (string) array_values((array) $row)[0], $connection->select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\''))
            : array_map(static fn ($row): string => (string) $row->name, $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));

        foreach ($tables as $table) {
            $create = $mysql
                ? (string) array_values((array) $connection->selectOne('SHOW CREATE TABLE ' . $quote($table)))[1]
                : (string) $connection->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
            gzwrite($gz, 'DROP TABLE IF EXISTS ' . $quote($table) . ";\n" . $create . ";\n");

            // Les colonnes générées (occupancy_key, is_active…) sont recalculées par la base :
            // les réinsérer ferait échouer la restauration.
            $columns = $this->storedColumns($connection, $table);

            $offset = 0;
            do {
                $rows = $connection->table($table)->select($columns)->offset($offset)->limit(500)->get();
                foreach ($rows as $row) {
                    $values = array_map(static fn ($v): string => match (true) {
                        $v === null => 'NULL',
                        is_int($v) => (string) $v,
                        default => $pdo->quote((string) $v),
                    }, (array) $row);
                    gzwrite($gz, sprintf("INSERT INTO %s (%s) VALUES (%s);\n", $quote($table), implode(', ', array_map($quote, array_keys((array) $row))), implode(', ', $values)));
                }
                $offset += 500;
            } while ($rows->count() === 500);
            gzwrite($gz, "\n");
        }

        if (! $mysql) {
            foreach ($connection->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) {
                gzwrite($gz, $index->sql . ";\n");
            }
        }

        gzwrite($gz, $mysql ? "SET FOREIGN_KEY_CHECKS=1;\n" : "COMMIT;\n");
        gzclose($gz);
    }

    /**
     * @return list<string> colonnes réellement stockées (hors colonnes générées)
     */
    private function storedColumns(Connection $connection, string $table): array
    {
        if ($connection->getDriverName() === 'mysql') {
            $rows = $connection->select(
                "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION",
                [$table],
            );

            return array_map(static fn ($row): string => (string) $row->name, $rows);
        }

        // SQLite : table_xinfo signale les colonnes générées par hidden = 2 (virtuelle) ou 3 (stockée).
        $rows = $connection->select('PRAGMA table_xinfo(' . $connection->getPdo()->quote($table) . ')');

        return array_values(array_map(
            static fn ($row): string => (string) $row->name,
            array_filter($rows, static fn ($row): bool => ! in_array((int) $row->hidden, [2, 3], true)),
        ));
    }

    private function prune(string $directory, int $days): void
    {
        $limit = now()->subDays($days)->getTimestamp();
        foreach (File::files($directory) as $backup) {
            if (preg_match('/^compteurs_.*\.sql\.gz$/', $backup->getFilename()) === 1 && $backup->getMTime() < $limit) {
                File::delete($backup->getPathname());
                $this->line('Supprimée (plus de ' . $days . ' jours) : ' . $backup->getFilename());
            }
        }
    }
}
