<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Service\Accounting\Dimension\BankRuleDimensions;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dimenze pravidla se k pohybu zapisují ve schválení nebo automatickém zaúčtování, zatímco
 * účetní může tentýž pohyb upravit v panelu Dimenze. Ruční hodnotu uloženou až po vzniku
 * snímku transakce zápis pravidla nesmí přepsat ani na ní spadnout: typy pohybu se proto
 * čtou zamykacím čtením. Druhé spojení commituje, proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class BankRuleDimensionsConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'bank_rule_dims';

    public function testManualDimensionSavedAfterSnapshotWinsOverRule(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $tx, $ruleId, $center, $manual, $project, $ruleProject] = $this->fixture($pdo, $container);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM document_dimensions')->fetchColumn();
            $this->secondConnection()->prepare(
                "INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin)
                 VALUES (?, 'bank_transaction', ?, 0, ?, ?, 'explicit')"
            )->execute([$supplier, $tx, $center, $manual]);

            (new BankRuleDimensions($db))->applyToTransaction($supplier, $tx, $ruleId);
            $pdo->commit();

            $stmt = $pdo->prepare(
                "SELECT dimension_type_id, dimension_value_id, assignment_origin FROM document_dimensions
                  WHERE supplier_id = ? AND doc_type = 'bank_transaction' AND doc_id = ? AND item_no = 0 ORDER BY dimension_type_id"
            );
            $stmt->execute([$supplier, $tx]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int) $r['dimension_type_id']] = [(int) $r['dimension_value_id'], (string) $r['assignment_origin']];
            }
            self::assertEquals([
                $center => [$manual, 'explicit'],
                $project => [$ruleProject, BankRuleDimensions::ORIGIN],
            ], $rows, 'Ruční středisko z panelu zůstane, pravidlo doplní jen projekt.');
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /** @return array{int,int,int,int,int,int,int} firma, pohyb, pravidlo, typ střediska, ruční středisko, typ projektu, projekt pravidla */
    private function fixture(PDO $pdo, \Psr\Container\ContainerInterface $container): array
    {
        $supplier = (int) $pdo->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id LIMIT 1")->fetchColumn();
        if ($supplier === 0) self::markTestSkipped('Chybí firma v podvojném účetnictví.');
        $dimensions = $container->get(DimensionService::class);
        $dimensions->setEnabled($supplier, true);
        $types = $dimensions->ensureDefaultTypes($supplier, ['projekt', 'stredisko']);
        $center = (int) $types['cost_center'];
        $project = (int) $types['project'];
        $value = static fn (int $type, string $code): int => (int) $dimensions->createValue($supplier, $type, ['code' => $code, 'name' => 'Syntetická ' . $code])['id'];
        $ruleCenter = $value($center, 'SB-RULE');
        $manual = $value($center, 'SB-MAN');
        $ruleProject = $value($project, 'SB-PRJ');

        $rules = $container->get(BankPostingRuleRepository::class);
        $ruleId = $rules->insert($supplier, [
            'name' => 'Syntetický úrok', 'direction' => 'incoming', 'message_contains' => 'urok',
            'debit_account_code' => '221', 'credit_account_code' => '662', 'mode' => 'suggest',
            'is_active' => 1, 'priority' => 100, 'applies_currency' => 'CZK',
        ], null);
        $rules->replaceDimensions($supplier, $ruleId, [$center => $ruleCenter, $project => $ruleProject]);

        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplier, 'souběh', hash('sha256', uniqid('', true)), '1000000005', '0100', '2099-01-20', 'CZK']);
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, "2099-01-20", 120, "CZK", "unmatched", NULL, "Úrok")'
        )->execute([(int) $pdo->lastInsertId()]);
        return [$supplier, (int) $pdo->lastInsertId(), $ruleId, $center, $manual, $project, $ruleProject];
    }

    private function secondConnection(): PDO
    {
        $config = self::sandboxConfig(self::SANDBOX);
        return new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306)
            . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
