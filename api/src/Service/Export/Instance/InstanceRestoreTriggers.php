<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use PDO;

final class InstanceRestoreTriggers
{
    public const RECOVERY_TABLE = 'instance_restore_trigger_recovery';

    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $restore, callable $assertEmpty): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new InstanceExportException('restore_transaction_active', 'Obnova nesmí běžet uvnitř otevřené transakce.');
        }
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new InstanceExportException('restore_database_missing', 'Není vybraná cílová databáze.');
        }
        $context = $this->context();
        $lock = 'instance-restore:' . hash('sha256', $database);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new InstanceExportException('restore_locked', 'V cílové databázi již běží obnova.');
        }
        try {
            $this->recover();
            $this->setContext($context);
            $assertEmpty();
            $triggers = $this->snapshot();
            if ($triggers === []) {
                return $this->invoke($restore);
            }
            $this->pdo->exec('CREATE TABLE `' . self::RECOVERY_TABLE . '` (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, payload LONGTEXT NOT NULL) ENGINE=InnoDB');
            $statement = $this->pdo->prepare('INSERT INTO `' . self::RECOVERY_TABLE . '` (id, payload) VALUES (1, ?)');
            $statement->execute([json_encode($triggers, JSON_THROW_ON_ERROR)]);
            try {
                foreach ($triggers as $trigger) {
                    $this->pdo->exec('DROP TRIGGER ' . $this->quote($trigger['name']));
                }
                return $this->invoke($restore);
            } finally {
                $this->recover();
            }
        } finally {
            try {
                $this->setContext($context);
            } finally {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lock]);
            }
        }
    }

    private function invoke(callable $restore): mixed
    {
        try {
            $result = $restore();
            if ($this->pdo->inTransaction()) {
                throw new InstanceExportException('restore_transaction_unfinished', 'Obnova neukončila datovou transakci.');
            }
            return $result;
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    private function recover(): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $statement->execute([self::RECOVERY_TABLE]);
        if ((int) $statement->fetchColumn() === 0) {
            return;
        }
        $payload = $this->pdo->query('SELECT payload FROM `' . self::RECOVERY_TABLE . '` WHERE id = 1')->fetchColumn();
        if ($payload === false) {
            $this->pdo->exec('DROP TABLE `' . self::RECOVERY_TABLE . '`');
            return;
        }
        $triggers = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($triggers) || !array_is_list($triggers) || $triggers === []) {
            throw new InstanceExportException('restore_trigger_recovery_invalid', 'Záznam obnovy databázových triggerů je neplatný.');
        }
        $names = [];
        foreach ($triggers as $trigger) {
            foreach (['name', 'table', 'timing', 'event', 'order', 'sql', 'sql_mode', 'character_set_client', 'collation_connection', 'database_collation'] as $key) {
                if (!is_array($trigger) || !isset($trigger[$key]) || !is_string($trigger[$key])) {
                    throw new InstanceExportException('restore_trigger_recovery_invalid', 'Záznam obnovy databázových triggerů je neúplný.');
                }
            }
            $names[$trigger['name']] = true;
        }
        $existing = $this->snapshot();
        if ($existing !== $triggers) {
            foreach ($existing as $trigger) {
                if (!isset($names[$trigger['name']])) {
                    throw new InstanceExportException('restore_triggers_changed', 'Během obnovy se změnila sada databázových triggerů.');
                }
            }
            foreach ($triggers as $trigger) {
                $this->pdo->exec('DROP TRIGGER IF EXISTS ' . $this->quote($trigger['name']));
            }
            foreach ($triggers as $trigger) {
                $this->setContext($trigger);
                $this->pdo->exec($trigger['sql']);
            }
            if ($this->snapshot() !== $triggers) {
                throw new InstanceExportException('restore_triggers_mismatch', 'Obnovené databázové triggery se liší od původních definic.');
            }
        }
        $this->pdo->exec('DROP TABLE `' . self::RECOVERY_TABLE . '`');
    }

    private function snapshot(): array
    {
        $rows = $this->pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $definition = $this->pdo->query('SHOW CREATE TRIGGER ' . $this->quote($row['TRIGGER_NAME']))->fetch(PDO::FETCH_ASSOC);
            if (!is_array($definition) || empty($definition['SQL Original Statement'])) {
                throw new InstanceExportException('restore_trigger_definition_missing', 'Nelze načíst definici databázového triggeru.');
            }
            $result[] = [
                'name' => (string) $row['TRIGGER_NAME'],
                'table' => (string) $row['EVENT_OBJECT_TABLE'],
                'timing' => (string) $row['ACTION_TIMING'],
                'event' => (string) $row['EVENT_MANIPULATION'],
                'order' => (string) $row['ACTION_ORDER'],
                'sql' => (string) $definition['SQL Original Statement'],
                'sql_mode' => (string) $definition['sql_mode'],
                'character_set_client' => (string) $definition['character_set_client'],
                'collation_connection' => (string) $definition['collation_connection'],
                'database_collation' => (string) $definition['Database Collation'],
            ];
        }
        return $result;
    }

    private function context(): array
    {
        return $this->pdo->query('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.character_set_client AS character_set_client, @@SESSION.collation_connection AS collation_connection')->fetch(PDO::FETCH_ASSOC);
    }

    private function setContext(array $context): void
    {
        foreach (['sql_mode', 'character_set_client', 'collation_connection'] as $key) {
            $statement = $this->pdo->prepare('SET SESSION ' . $key . ' = ?');
            $statement->execute([$context[$key]]);
        }
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
