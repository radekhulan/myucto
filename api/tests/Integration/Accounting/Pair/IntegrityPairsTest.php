<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalIntegrityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Noční kontrola dvojic jednoduchého zápisu: ručně rozbitá dvojice, neúplná dvojice,
 * dvojice na stejném účtu a zápis bez dvojic v období jednoduché formy. Firma bez
 * jednoduché formy kontroly nespouští ani neukládá (nepozná změnu).
 */
#[Group('integration')]
final class IntegrityPairsTest extends TestCase
{
    use PairConversionFixture;

    private JournalIntegrityService $service;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->service = $this->container->get(JournalIntegrityService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testCompositeSupplierGetsNoPairChecks(): void
    {
        $this->invoiceOneToMany('2098-03-01');
        $this->entry('2098-03-02', [['518', 'debit', '10.00', 1], ['518', 'credit', '10.00', 1]]);

        $findings = $this->service->checkAndStore($this->supplierId);

        self::assertSame(JournalIntegrityService::TYPES, array_keys($findings));
        $stored = $this->db->pdo()->prepare('SELECT finding_type FROM journal_integrity_findings WHERE supplier_id = ? ORDER BY finding_type');
        $stored->execute([$this->supplierId]);
        $types = $stored->fetchAll(\PDO::FETCH_COLUMN);
        sort($types);
        $expected = JournalIntegrityService::TYPES;
        sort($expected);
        self::assertSame($expected, $types);
    }

    public function testPairFindingsInSingleSupplier(): void
    {
        $this->setSingleFrom('2098-01-01');
        $pdo = $this->db->pdo();

        $valid = $this->entry('2098-02-01', [['518', 'debit', '10.00', 1], ['221', 'credit', '10.00', 1]]);
        $broken = $this->entry('2098-02-02', [['518', 'debit', '20.00', 1], ['221', 'credit', '20.00', 1], ['568', 'debit', '5.00', 2], ['221', 'credit', '5.00', 2]]);
        $pdo->prepare('UPDATE journal_entry_lines SET amount = 19.00 WHERE supplier_id = ? AND entry_id = ? AND line_no = 2')
            ->execute([$this->supplierId, $broken]);
        $sameSide = $this->entry('2098-02-03', [['518', 'debit', '7.00', 1], ['568', 'debit', '7.00', 1]]);
        $triple = $this->entry('2098-02-04', [['518', 'debit', '10.00', 1], ['221', 'credit', '5.00', 1], ['221', 'credit', '5.00', 1]]);
        $mixed = $this->entry('2098-02-05', [['518', 'debit', '10.00', 1], ['221', 'credit', '10.00', 1], ['311', 'debit', '5.00'], ['602', 'credit', '5.00']]);
        $same = $this->entry('2098-02-06', [['311', 'debit', '8.00', 1], ['311', 'credit', '8.00', 1]]);
        $unpaired = $this->invoiceOneToMany('2098-03-01');
        $marked = $this->manyToMany('2098-03-02');
        $pdo->prepare(
            "INSERT INTO journal_entry_pair_status (supplier_id, entry_id, status, reason, assessed_at)
             VALUES (?, ?, 'composite_mn', 'many_to_many', NOW())"
        )->execute([$this->supplierId, $marked]);
        $this->invoiceOneToMany('2097-06-01');
        $this->entry('2098-03-03', [['518', 'debit', '10.00'], ['221', 'credit', '10.00']], posted: false);

        $findings = $this->service->check($this->supplierId);

        self::assertSame([$broken, $sameSide], array_column($findings[JournalIntegrityService::TYPE_PAIR_UNBALANCED]['sample'], 'entry_id'));
        self::assertSame(2, $findings[JournalIntegrityService::TYPE_PAIR_UNBALANCED]['count']);
        self::assertSame([$triple, $mixed], array_column($findings[JournalIntegrityService::TYPE_PAIR_INCOMPLETE]['sample'], 'entry_id'));
        self::assertSame([$same], array_column($findings[JournalIntegrityService::TYPE_PAIR_SAME_ACCOUNT]['sample'], 'entry_id'));
        self::assertSame([$unpaired], array_column($findings[JournalIntegrityService::TYPE_SINGLE_UNPAIRED]['sample'], 'entry_id'));
        foreach (JournalIntegrityService::PAIR_TYPES as $type) {
            self::assertNotContains($valid, array_column($findings[$type]['sample'], 'entry_id'), $type);
        }

        $this->service->checkAndStore($this->supplierId);
        $summary = $this->service->latestSummary($this->supplierId);
        self::assertSame(2, $summary['breakdown'][JournalIntegrityService::TYPE_PAIR_UNBALANCED] ?? null);
        self::assertSame(1, $summary['breakdown'][JournalIntegrityService::TYPE_SINGLE_UNPAIRED] ?? null);
    }

    public function testSwitchBackToCompositeEndsSingleWindow(): void
    {
        $this->setSingleFrom('2098-01-01');
        $this->db->pdo()->prepare(
            "INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, '2098-06-01', 'composite')"
        )->execute([$this->supplierId]);
        \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($this->supplierId);
        $inside = $this->invoiceOneToMany('2098-03-01');
        $this->invoiceOneToMany('2098-07-01');

        $findings = $this->service->check($this->supplierId);

        self::assertSame([$inside], array_column($findings[JournalIntegrityService::TYPE_SINGLE_UNPAIRED]['sample'], 'entry_id'));
    }
}
