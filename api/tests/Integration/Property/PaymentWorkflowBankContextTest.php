<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaymentWorkflowBankContextTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testCurrentAnalyticSeesNewAccountDespiteOldEmptySnapshot(): void
    {
        $this->checkCurrent(true);
    }

    public function testCurrentAnalyticSeesChangedSuffixDespiteOldSnapshotAndCache(): void
    {
        $this->checkCurrent(false);
    }

    private function checkCurrent(bool $initiallyMissing): void
    {
        $config = Bootstrap::buildContainer()->get(Config::class);
        $source = (string) $config->get('db.name');
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $owned = 'myucto_workflow_bank_' . bin2hex(random_bytes(5)) . '_test';
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $owned, static function (): void {});
        $cloner->finalize($plan, $owned);
        $connections = [];
        try {
            $data = $config->all(); $data['db']['name'] = $owned;
            for ($i = 0; $i < 2; ++$i) {
                $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
                $db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
                $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $connections[] = $db;
            }
            [$a, $b] = $connections;
            $supplier = $this->createIsolatedSupplier($b->pdo(), (int) $b->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
            $container = Bootstrap::buildContainer(); $container->set(Connection::class, $a);
            $resolver = $container->get(BankAnalyticResolver::class);
            $repo = $container->get(SupplierBankAccountRepository::class);
            $tx = ['recipient_account' => '1000000005', 'recipient_bank' => '0100'];

            if (!$initiallyMissing) {
                $b->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
            }
            $a->pdo()->beginTransaction();
            $legacy = $repo->matchCounterparty($supplier, '1000000005', '0100');
            self::assertSame($initiallyMissing ? null : '221.100', $resolver->existingAnalyticCodeFor($supplier, $tx));
            if ($initiallyMissing) {
                $b->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic account','1000000005','0100','0100','CZK','1000000005','200')")->execute([$supplier]);
            } else {
                $b->pdo()->prepare("UPDATE supplier_bank_accounts SET analytic_suffix='200' WHERE supplier_id=?")->execute([$supplier]);
            }
            (new PaymentLocks($a))->acquire($supplier);
            self::assertSame('221.200', $resolver->existingAnalyticCodeFor($supplier, $tx, true));
            self::assertSame($legacy, $repo->matchCounterparty($supplier, '1000000005', '0100'));
            self::assertSame($initiallyMissing ? null : '221.100', $resolver->existingAnalyticCodeFor($supplier, $tx), 'Default false keeps legacy request cache.');
            self::assertSame('200', $repo->matchCounterparty($supplier, '1000000005', '0100', false, true)['analytic_suffix']);
            $a->pdo()->rollBack();
        } finally {
            foreach ($connections as $db) { if ($db->pdo()->inTransaction()) $db->pdo()->rollBack(); $db->close(); }
            self::assertMatchesRegularExpression('/^myucto_workflow_bank_[a-f0-9]{10}_test$/D', $owned);
            $server->exec('DROP DATABASE ' . DatabaseCloner::quote($owned));
        }
    }
}
