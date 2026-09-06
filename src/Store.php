<?php

declare(strict_types=1);

namespace KintB24;

/**
 * SQLite-backed idempotency store and log.
 *
 * sync_map: kint_id + entity → b24_id (prevents duplicate creates)
 * sync_log: timestamped event log
 */
final class Store
{
    private \PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new \PDO("sqlite:{$dbPath}", null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sync_map (
                kint_id    TEXT    NOT NULL,
                entity     TEXT    NOT NULL,
                b24_id     INTEGER NOT NULL,
                updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
                PRIMARY KEY (kint_id, entity)
            );

            CREATE TABLE IF NOT EXISTS sync_log (
                ts    TEXT    NOT NULL DEFAULT (datetime('now')),
                level TEXT    NOT NULL,
                msg   TEXT    NOT NULL
            );

            CREATE TABLE IF NOT EXISTS sync_state (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        SQL);
    }

    /** Returns b24_id if mapping exists, null otherwise. */
    public function getMapped(string $kintId, string $entity): ?int
    {
        $st = $this->db->prepare(
            'SELECT b24_id FROM sync_map WHERE kint_id = ? AND entity = ?'
        );
        $st->execute([$kintId, $entity]);
        $row = $st->fetch();
        return $row ? (int)$row['b24_id'] : null;
    }

    /** Upsert mapping. */
    public function saveMapping(string $kintId, string $entity, int $b24Id): void
    {
        $this->db->prepare(
            'INSERT INTO sync_map (kint_id, entity, b24_id, updated_at)
             VALUES (?, ?, ?, datetime("now"))
             ON CONFLICT(kint_id, entity) DO UPDATE SET b24_id=excluded.b24_id, updated_at=excluded.updated_at'
        )->execute([$kintId, $entity, $b24Id]);
    }

    public function getState(string $key): ?string
    {
        $st = $this->db->prepare('SELECT value FROM sync_state WHERE key = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    public function saveState(string $key, string $value): void
    {
        $this->db->prepare(
            'INSERT INTO sync_state (key, value, updated_at) VALUES (?, ?, datetime("now"))
             ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at'
        )->execute([$key, $value]);
    }

    public function log(string $level, string $msg): void
    {
        $this->db->prepare(
            'INSERT INTO sync_log (ts, level, msg) VALUES (datetime("now"), ?, ?)'
        )->execute([$level, $msg]);
    }

    public function info(string $msg): void  { $this->log('INFO', $msg); }
    public function error(string $msg): void { $this->log('ERROR', $msg); }
    public function warn(string $msg): void  { $this->log('WARN', $msg); }

    /** For tests: truncate both tables. */
    public function reset(): void
    {
        $this->db->exec('DELETE FROM sync_map; DELETE FROM sync_log; DELETE FROM sync_state;');
    }

    /** Returns all deal mappings: [['kint_id' => ..., 'b24_id' => ...], ...]. */
    public function getMappedDeals(): array
    {
        return $this->query("SELECT kint_id, b24_id FROM sync_map WHERE entity='deal'");
    }

    /** Raw query — private; use dedicated public methods instead. */
    private function query(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
