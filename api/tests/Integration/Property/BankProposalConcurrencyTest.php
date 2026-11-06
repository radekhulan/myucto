<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Tests\Support\{BankProposalFixture, ChargeIssuanceFixture};
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Souběh návrhů z importu: dva importy téhož pohybu založí jediný návrh a návrh
 * potvrzený jedním uživatelem nejde druhým starým náhledem zaplatit podruhé.
 */
final class BankProposalConcurrencyTest extends TestCase
{
    use ChargeIssuanceFixture, BankProposalFixture;

    private string $schema;
    private PDO $server;
    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->schema = 'myucto_bank_proposal_' . bin2hex(random_bytes(5)) . '_test';
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $this->schema, static function (): void {});
        $cloner->finalize($plan, $this->schema);
        $data = $config->all();
        $data['db']['name'] = $this->schema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesRegularExpression('/^myucto_bank_proposal_[a-f0-9]{10}_test$/D', $this->schema);
        $this->server->exec('DROP DATABASE ' . DatabaseCloner::quote($this->schema));
    }

    public function testConcurrentImportsAndConfirmationsNeverDuplicate(): void
    {
        [$a, $b] = $this->connections;
        $a->pdo()->beginTransaction();
        $f = $this->issuanceFixture($a);
        $case = $this->postedDebts($a, $f, ['U05']);
        $tx = $this->incoming($a, $f['supplier'], bcdiv($case['totals']['U05'], '100', 2), ['variable_symbol' => '5']);
        $a->pdo()->commit();

        $a->pdo()->beginTransaction();
        $a->pdo()->query('SELECT COUNT(*) FROM unit_payment_bank_proposals')->fetchColumn();
        self::assertSame(1, $this->proposalService($b)->afterImport($f['supplier'], [$tx]));
        self::assertSame(1, $this->proposalService($a)->afterImport($f['supplier'], [$tx]), 'Old snapshot still upserts onto the committed row.');
        $a->pdo()->commit();
        self::assertSame([1, 1], $this->proposalRows($b, $tx));

        $planA = $this->proposalService($a)->plan($f['supplier'], $tx);
        $planB = $this->proposalService($b)->plan($f['supplier'], $tx);
        self::assertSame($planA['preview']['preview_hash'], $planB['preview']['preview_hash']);
        $this->facade($b)->confirm($f['supplier'], $planB['request'], $planB['preview']['preview_hash'], 'proposal-b', $f['actor']);
        try {
            $this->facade($a)->confirm($f['supplier'], $planA['request'], $planA['preview']['preview_hash'], 'proposal-a', $f['actor']);
            self::fail('Second confirmation of the same bank transaction must be rejected.');
        } catch (PaymentException $e) {
            self::assertStringStartsWith('property_payment_', $e->errorCode);
        }
        $q = $b->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_sources WHERE bank_transaction_id = ?');
        $q->execute([$tx]);
        self::assertSame(1, (int) $q->fetchColumn());
        self::assertSame(0, $this->proposalService($a)->afterImport($f['supplier'], [$tx]));
    }

    /** @return array{0:int,1:int} počet řádků a verze návrhu */
    private function proposalRows(Connection $db, int $tx): array
    {
        $q = $db->pdo()->prepare('SELECT COUNT(*), MAX(row_version) FROM unit_payment_bank_proposals WHERE bank_transaction_id = ?');
        $q->execute([$tx]);
        return array_map('intval', $q->fetch(PDO::FETCH_NUM));
    }

    private function facade(Connection $db): PaymentWorkflowFacade
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        return $container->get(PaymentWorkflowFacade::class);
    }
}
