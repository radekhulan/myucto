<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use Psr\Container\ContainerInterface as Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormInstallationDefault;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Supplier\SupplierCreator;
use MyInvoice\Service\Supplier\SupplierInitializer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí forma účetního zápisu instalace (`accounting.default_entry_form`, ENV
 * MYINVOICE_ACCOUNTING_DEFAULT_ENTRY_FORM): výchozí složená forma nové firmě nic
 * nezapíše, jednoduchá jí zapíše formu `single` od začátku (1900-01-01) jen tehdy,
 * když firma vede podvojné účetnictví, nemá formu ani zápis v deníku a jednoduchý
 * zápis smí mít.
 *
 * Vše běží v transakci, kterou test na konci vrátí.
 */
#[Group('integration')]
final class EntryFormInstallationDefaultTest extends TestCase
{
    private const ENV = 'MYINVOICE_ACCOUNTING_DEFAULT_ENTRY_FORM';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        putenv(self::ENV);
        EntryFormService::forgetShared();
    }

    protected function tearDown(): void
    {
        putenv(self::ENV);
        EntryFormService::forgetShared();
    }

    public function testDefaultCompositeInstallationLeavesNewFirmWithoutHistory(): void
    {
        $container = Bootstrap::buildContainer();
        self::assertSame(EntryForm::Composite, $container->get(EntryFormInstallationDefault::class)->form());
        $this->inTransaction($container, function (\PDO $pdo, int $userId) use ($container): void {
            $supplierId = $this->createFirm($container, $userId, 'po');

            self::assertSame([], $this->history($pdo, $supplierId));
            self::assertSame(EntryForm::Composite, $container->get(EntryFormService::class)->formAt($supplierId, '2026-06-30', true));
        });
    }

    public function testSingleInstallationFromEnvSeedsSingleFormFromBeginningForNewFirm(): void
    {
        putenv(self::ENV . '=single');
        $container = Bootstrap::buildContainer();
        self::assertSame('single', $container->get(Config::class)->get('accounting.default_entry_form'));
        $this->inTransaction($container, function (\PDO $pdo, int $userId) use ($container): void {
            $supplierId = $this->createFirm($container, $userId, 'po');

            self::assertSame([[EntryFormService::INSTALLATION_DEFAULT_FROM, 'single']], $this->history($pdo, $supplierId));
            $forms = $container->get(EntryFormService::class);
            self::assertSame(EntryForm::Single, $forms->formAt($supplierId, '2001-01-01', true));
            self::assertSame(EntryForm::Single, $forms->formAt($supplierId, date('Y-m-d'), true));

            $audit = $pdo->prepare("SELECT payload FROM activity_log WHERE action = ? AND entity_type = 'supplier' AND entity_id = ?");
            $audit->execute([EntryFormService::AUDIT_ACTION, $supplierId]);
            $payload = json_decode((string) $audit->fetchColumn(), true);
            self::assertSame(['valid_from' => '1900-01-01', 'from' => 'composite', 'to' => 'single', 'conversion_preview_id' => null, 'installation_default' => true], $payload);

            // Opakované dokončení profilu (převod, aktivace) nic nepřidá.
            $container->get(SupplierInitializer::class)->finalizeProfile($supplierId, $userId);
            self::assertCount(1, $this->history($pdo, $supplierId));
        });
    }

    public function testSingleInstallationSkipsTaxEvidenceUntilActivationAndFirmsWithJournal(): void
    {
        putenv(self::ENV . '=single');
        $container = Bootstrap::buildContainer();
        $this->inTransaction($container, function (\PDO $pdo, int $userId) use ($container): void {
            $default = $container->get(EntryFormInstallationDefault::class);

            $taxEvidence = $this->createFirm($container, $userId, 'fo');
            self::assertSame([], $this->history($pdo, $taxEvidence));
            self::assertNull($default->apply($taxEvidence, $userId));
            self::assertNotNull($default->apply($taxEvidence, $userId, activating: true));
            self::assertSame([['1900-01-01', 'single']], $this->history($pdo, $taxEvidence));

            $withJournal = $this->createFirm($container, $userId, 'po');
            $pdo->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')->execute([$withJournal]);
            EntryFormService::forgetShared();
            $period = $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on LIMIT 1');
            $period->execute([$withJournal]);
            $pdo->prepare(
                "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, description)
                 VALUES (?, ?, ?, 'manual', 'Starší zápis')"
            )->execute([$withJournal, (int) $period->fetchColumn(), date('Y-m-d')]);
            self::assertNull($default->apply($withJournal, $userId));
            self::assertSame([], $this->history($pdo, $withJournal));
        });
    }

    public function testSingleInstallationSkipsNonprofitFirm(): void
    {
        putenv(self::ENV . '=single');
        $container = Bootstrap::buildContainer();
        $this->inTransaction($container, function (\PDO $pdo, int $userId) use ($container): void {
            $supplierId = $this->createFirm($container, $userId, 'po', ['finalize' => false]);
            $pdo->prepare("UPDATE supplier SET legal_form = '706' WHERE id = ?")->execute([$supplierId]);

            self::assertNull($container->get(EntryFormInstallationDefault::class)->apply($supplierId, $userId));
            self::assertSame([], $this->history($pdo, $supplierId));
        });
    }

    /** @param array{finalize?:bool} $options */
    private function createFirm(Container $container, int $userId, string $taxpayerType, array $options = []): int
    {
        $supplierId = $container->get(SupplierCreator::class)->create([
            'company_name' => 'Syntetická firma formy zápisu', 'street' => 'Testovací 1',
            'city' => 'Testov', 'zip' => '10000', 'email' => 'forma@example.test',
            'taxpayer_type' => $taxpayerType,
        ], $userId, false);
        if ($options['finalize'] ?? true) {
            $container->get(SupplierInitializer::class)->finalizeProfile($supplierId, $userId);
        }
        return $supplierId;
    }

    /** @param callable(\PDO, int): void $test */
    private function inTransaction(Container $container, callable $test): void
    {
        $db = $container->get(Connection::class);
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')->execute([
                'entry-form-' . bin2hex(random_bytes(6)) . '@example.test',
                password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'Forma zápisu', 'admin',
            ]);
            $test($pdo, (int) $pdo->lastInsertId());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $db->close();
        }
    }

    /** @return list<array{string,string}> */
    private function history(\PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare('SELECT valid_from, entry_form FROM accounting_entry_form_history WHERE supplier_id = ? ORDER BY valid_from');
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [(string) $r['valid_from'], (string) $r['entry_form']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
