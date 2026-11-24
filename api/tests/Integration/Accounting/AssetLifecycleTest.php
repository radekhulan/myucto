<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy životního cyklu majetku (Epic F3, spec §6.2 I1–I5).
 *
 * I1 celý řetěz PF → karta → zařazení → book → vyřazení + rozvahové invarianty
 * (delta zůstatků 022/082 = 0 po vyřazení, každý zápis vyvážený), I2 idempotence
 * re-booku, I3 revert vyřazení (R24), I4 přerušení §26/8 (R14), I5 tenant izolace.
 *
 * Vzor PostingServiceTest: vše v jedné transakci s rollbackem v tearDown,
 * soft-skip bez cfg.php, seeder osnovy, roky 2098/2099.
 */
#[Group('integration')]
final class AssetLifecycleTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AssetService $service;
    private DepreciationPostingService $depPosting;
    private AssetRepository $assets;
    private DepreciationEntryRepository $entries;
    private JournalEntryRepository $journal;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->service    = $container->get(AssetService::class);
            $this->depPosting = $container->get(DepreciationPostingService::class);
            $this->assets     = $container->get(AssetRepository::class);
            $this->entries    = $container->get(DepreciationEntryRepository::class);
            $this->journal    = $container->get(JournalEntryRepository::class);
            $this->posting    = $container->get(PostingService::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── I1: celý řetěz PF → karta → zařazení → book → vyřazení ────────────────

    public function testI1FullChainFromPurchaseInvoiceToDisposal(): void
    {
        $before022 = $this->accountBalance('022');
        $before082 = $this->accountBalance('082');

        // PF 500 000 + 105 000 DPH s příznakem majetku → 042/343/321
        $vendor     = $this->client('Dodavatel majetku a.s.', false, true);
        $purchaseId = $this->purchase('PF-2098-M1', $vendor, 500000.00, 105000.00, 21.00, true);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $piEntry = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines,
            ['entry_date' => self::YEAR . '-03-10', 'posted_by' => $this->userId]);
        $byAcc = $this->linesByAccountCode($this->journal->find($piEntry, $this->supplierId)['lines']);
        self::assertEqualsWithDelta(500000.00, $byAcc['042']['debit'], 0.001, 'PF majetku → MD 042 (pořízení).');
        self::assertEqualsWithDelta(605000.00, $byAcc['321']['credit'], 0.001, 'D 321 = závazek.');

        // Karta: sk. 2 rovnoměrně, účetně 60 měsíců (022/082/042 mapou R18)
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-I1-001',
            'name' => 'Testovací stroj I1',
            'input_price' => 500000.00,
            'acquisition_date' => self::YEAR . '-03-10',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
            'purchase_invoice_id' => $purchaseId,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];
        self::assertSame('082', $created['asset']['accumulated_account_code'], 'Oprávky odvozené mapou R18 (022 → 082).');

        // Zařazení do užívání 20. 4. 2098 → ('asset', id) MD 022 / D 042
        $this->service->putIntoUse($this->supplierId, $assetId, self::YEAR . '-04-20', true,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $useEntry = $this->journal->findBySource($this->supplierId, 'asset', $assetId);
        self::assertNotNull($useEntry, 'Zařazení má zápis ("asset", id).');
        $useLines = $this->journal->linesForEntry((int) $useEntry['id'], $this->supplierId);
        $byAcc = $this->linesByAccountCode($useLines);
        self::assertEqualsWithDelta(500000.00, $byAcc['022']['debit'], 0.001, 'MD 022 = vstupní cena.');
        self::assertEqualsWithDelta(500000.00, $byAcc['042']['credit'], 0.001, 'D 042.');
        $this->assertBalanced($useLines);

        // Book 2098: účetní odpis 5–12/2098 = 8 × round(500 000/60) = 66 664 → MD 551 / D 082
        $result = $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        self::assertGreaterThanOrEqual(1, $result['booked']);
        $accEntry = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($accEntry);
        self::assertEqualsWithDelta(66664.00, $accEntry['amount'], 0.001, '8 měsíců à 8 333.');
        $depJournal = $this->journal->findBySource($this->supplierId, 'depreciation', (int) $accEntry['id']);
        self::assertNotNull($depJournal, 'Odpis má zápis ("depreciation", entryId).');
        $depLines = $this->journal->linesForEntry((int) $depJournal['id'], $this->supplierId);
        $byAcc = $this->linesByAccountCode($depLines);
        self::assertEqualsWithDelta(66664.00, $byAcc['551']['debit'], 0.001, 'MD 551 = účetní odpis.');
        self::assertEqualsWithDelta(66664.00, $byAcc['082']['credit'], 0.001, 'D 082 = oprávky.');
        $this->assertBalanced($depLines);

        $taxEntry = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertNotNull($taxEntry);
        self::assertEqualsWithDelta(55000.00, $taxEntry['amount'], 0.001, 'Daňový odpis 2098 = 11 % z 500 000.');

        // Vyřazení prodejem 15. 10. 2099: dopočet odpisu 1–10/2099 (83 330), půlodpis
        // daňově (55 625), disposal zápis MD 541 ZC / D 082 + MD 082 PC / D 022.
        $disposed = $this->service->dispose($this->supplierId, $assetId,
            ['date' => (self::YEAR + 1) . '-10-15', 'type' => 'sold', 'price' => 250000.00],
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertSame('disposed', $disposed['asset']['status']);

        $acc2099 = $this->entries->findYear($assetId, 'accounting', self::YEAR + 1);
        self::assertNotNull($acc2099);
        self::assertEqualsWithDelta(83330.00, $acc2099['amount'], 0.001, '10 měsíců à 8 333 do měsíce vyřazení včetně.');

        $tax2099 = $this->entries->findYear($assetId, 'tax', self::YEAR + 1);
        self::assertNotNull($tax2099);
        self::assertTrue($tax2099['is_half'], 'Rok vyřazení = půlodpis §26/7.');
        self::assertEqualsWithDelta(55625.00, $tax2099['amount'], 0.001, '½ z 111 250.');

        $dispEntry = $this->journal->findBySource($this->supplierId, 'asset_disposal', $assetId);
        self::assertNotNull($dispEntry, 'Vyřazení má zápis ("asset_disposal", id).');
        $dispLines = $this->journal->linesForEntry((int) $dispEntry['id'], $this->supplierId);
        $byAcc = $this->linesByAccountCode($dispLines);
        self::assertEqualsWithDelta(350006.00, $byAcc['541']['debit'], 0.001, 'MD 541 = účetní ZC (500 000 − 66 664 − 83 330).');
        self::assertEqualsWithDelta(500000.00, $byAcc['082']['debit'], 0.001, 'MD 082 = vyřazení z evidence v PC.');
        self::assertEqualsWithDelta(350006.00, $byAcc['082']['credit'], 0.001, 'D 082 = doodepsání ZC.');
        self::assertEqualsWithDelta(500000.00, $byAcc['022']['credit'], 0.001, 'D 022 = pořizovací cena.');
        $this->assertBalanced($dispLines);

        // Invarianty: delta zůstatků 022 a 082 po celém řetězu = 0 (haléře)
        self::assertEqualsWithDelta($before022, $this->accountBalance('022'), 0.001, 'Zůstatek 022 po vyřazení = 0.');
        self::assertEqualsWithDelta($before082, $this->accountBalance('082'), 0.001, 'Zůstatek 082 po vyřazení = 0.');
    }

    // ── I2: idempotence re-booku ───────────────────────────────────────────────

    public function testI2RebookSameYearIsIdempotent(): void
    {
        $assetId = $this->createAssetInUse('M-I2-001', self::YEAR . '-01-15');

        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $accEntry = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($accEntry);
        self::assertEqualsWithDelta(91663.00, $accEntry['amount'], 0.001, '11 měsíců à 8 333 (2–12/2098).');
        $firstJournal = $this->journal->findBySource($this->supplierId, 'depreciation', (int) $accEntry['id']);
        self::assertNotNull($firstJournal);

        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);

        $accEntry2 = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertSame($accEntry['id'], $accEntry2['id'], 'Re-book přepisuje TÝŽ řádek odpisů (upsert).');
        self::assertEqualsWithDelta($accEntry['amount'], $accEntry2['amount'], 0.001, 'Částka beze změny.');

        $secondJournal = $this->journal->findBySource($this->supplierId, 'depreciation', (int) $accEntry['id']);
        self::assertSame((int) $firstJournal['id'], (int) $secondJournal['id'], 'Týž journal entry id.');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'depreciation' AND source_id = {$accEntry['id']}"
        )->fetchColumn();
        self::assertSame(1, $count, 'Žádná duplicita zápisů.');

        $lineCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id = {$firstJournal['id']}"
        )->fetchColumn();
        self::assertSame(2, $lineCount, 'Řádky přepsány in-place, ne zdvojeny.');
    }

    // ── I3: revert vyřazení (R24) ──────────────────────────────────────────────

    public function testI3RevertDisposalRestoresAssetAndReversesEntries(): void
    {
        $assetId = $this->createAssetInUse('M-I3-001', self::YEAR . '-02-10');
        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $this->service->dispose($this->supplierId, $assetId,
            ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'],
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        $accEntry = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($accEntry);
        $dispJournalId = (int) $this->journal->findBySource($this->supplierId, 'asset_disposal', $assetId)['id'];
        $depJournalId  = (int) $this->journal->findBySource($this->supplierId, 'depreciation', (int) $accEntry['id'])['id'];

        $reverted = $this->service->revertDisposal($this->supplierId, $assetId,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        self::assertSame('in_use', $reverted['asset']['status'], 'Status zpět in_use.');
        self::assertNull($reverted['asset']['disposal_date']);
        self::assertNull($reverted['asset']['disposal_type']);

        self::assertNotNull($this->journal->find($dispJournalId, $this->supplierId)['reversed_by'],
            'Disposal zápis má storno pár.');
        self::assertNotNull($this->journal->find($depJournalId, $this->supplierId)['reversed_by'],
            'Odpis roku vyřazení má storno pár.');

        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR), 'Účetní řádek roku smazán.');
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR), 'Daňový řádek roku smazán.');

        // Regrese: stornovaný disposal zápis nesmí blokovat nové vyřazení
        // (uvolněný source klíč) — dispose po revertu musí projít.
        $again = $this->service->dispose($this->supplierId, $assetId,
            ['date' => self::YEAR . '-11-20', 'type' => 'sold', 'price' => 1000.00],
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertSame('disposed', $again['asset']['status'], 'Opakované vyřazení po revertu projde.');
        $newDisposal = $this->journal->findBySource($this->supplierId, 'asset_disposal', $assetId);
        self::assertNotNull($newDisposal, 'Nový disposal zápis drží source klíč.');
        self::assertNotSame($dispJournalId, (int) $newDisposal['id'], 'Vznikl NOVÝ zápis, starý zůstal jako storno pár.');
        self::assertNull($newDisposal['reversed_by']);
    }

    // ── I4: přerušení §26/8 (R14) ─────────────────────────────────────────────

    public function testI4PauseSkipsTaxBookingAndUnpauseAfterLaterYearRefused(): void
    {
        $assetId = $this->createAssetInUse('M-I4-001', self::YEAR . '-03-10', 90000.00, 1, 36);

        $paused = $this->service->pauseYear($this->supplierId, $assetId, self::YEAR);
        self::assertTrue($paused['entry']['is_paused']);
        self::assertEqualsWithDelta(0.0, $paused['entry']['amount'], 0.001);

        // Book roku s pauzou: tax krok se přeskočí (řádek zůstane pauza 0), acc se zaúčtuje
        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $tax = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertTrue($tax['is_paused'], 'Pauza přežije book (R14).');
        self::assertEqualsWithDelta(0.0, $tax['full_amount'], 0.001);
        $acc = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($acc, 'Účetní odpis pauzou dotčen není.');
        self::assertGreaterThan(0, $acc['amount']);

        // Potvrzení pozdějšího roku → unpause 2098 zamítnut
        $this->depPosting->bookYear($this->supplierId, self::YEAR + 1, ['posted_by' => $this->userId]);
        $taxNext = $this->entries->findYear($assetId, 'tax', self::YEAR + 1);
        self::assertNotNull($taxNext);
        self::assertGreaterThan(0, $taxNext['full_amount'], 'Po pauze odpisování pokračuje.');

        try {
            $this->service->unpauseYear($this->supplierId, $assetId, self::YEAR);
            self::fail('Unpause po potvrzení pozdějšího roku musí selhat (R14).');
        } catch (AssetException $e) {
            self::assertSame('later_year_confirmed', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testDonationReturnsVatOutputWarning(): void
    {
        $assetId = $this->createAssetInUse('M-EP4-DAR', self::YEAR . '-03-10', 90000.00, 1, 36);

        $disposed = $this->service->dispose($this->supplierId, $assetId,
            ['date' => self::YEAR . '-09-15', 'type' => 'donated'],
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        self::assertContains('donation_vat_output', array_column($disposed['warnings'], 'code'));
    }

    public function testRevertDisposalPreservesPausedTaxYear(): void
    {
        $assetId = $this->createAssetInUse('M-EP4-PAUSE', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->service->pauseYear($this->supplierId, $assetId, self::YEAR);
        $this->service->dispose($this->supplierId, $assetId,
            ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'],
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        $this->service->revertDisposal($this->supplierId, $assetId,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        $tax = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertNotNull($tax, 'Pauza založená před vyřazením zůstala zachována.');
        self::assertTrue($tax['is_paused']);
        self::assertEqualsWithDelta(0.0, $tax['amount'], 0.001);
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR));
    }

    /**
     * Historická karta z převodu (R23) s technickým zhodnocením z doby před převodem:
     * počáteční oprávky pokrývají i odpisy zhodnocení, proto smějí přesáhnout cenu zařazení
     * (ne cenu zvýšenou o zhodnocení) - při založení i při pozdější úpravě karty.
     */
    public function testHistoricalCardWithEarlierImprovement(): void
    {
        $card = [
            'inventory_number' => 'M-HIST-TZ',
            'name' => 'Historická linka',
            'input_price' => 90000.00,
            'acquisition_date' => (self::YEAR - 2) . '-01-10',
            'put_into_use_date' => (self::YEAR - 2) . '-01-10',
            'status' => 'in_use',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'opening_tax_years' => 2,
            'opening_tax_amount' => 100000.00,
            'opening_acc_months' => 23,
            'opening_acc_amount' => 100000.00,
            'acc_useful_life_months' => 60,
        ];
        try {
            $this->service->create($this->supplierId, $card, ['user_id' => $this->userId]);
            self::fail('Bez zhodnocení počáteční oprávky nad vstupní cenou neprojdou.');
        } catch (AssetException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }

        $created = $this->service->createWithImprovements($this->supplierId, $card,
            [['completed_on' => (self::YEAR - 1) . '-06-15', 'amount' => 60000, 'description' => 'Přístavba']], ['user_id' => $this->userId]);
        $asset = $created['asset'];
        self::assertSame('in_use', $asset['status']);
        self::assertEqualsWithDelta(60000.0, (float) $asset['improvements_total'], 0.001);
        self::assertSame([[(self::YEAR - 1) . '-06-15', 'Přístavba']], array_map(static fn (array $i): array => [(string) $i['completed_on'], $i['description']], $asset['improvements']));
        self::assertEqualsWithDelta(50000.0, (float) $asset['tax_residual'], 0.001);

        $updated = $this->service->update($this->supplierId, (int) $asset['id'], ['name' => 'Historická linka po úpravě'], ['user_id' => $this->userId]);
        self::assertSame('Historická linka po úpravě', $updated['asset']['name']);

        // Zhodnocení před zařazením karta nepřijme a nezaloží ani kartu.
        try {
            $this->service->createWithImprovements($this->supplierId, ['inventory_number' => 'M-HIST-TZ2'] + $card,
                [['completed_on' => (self::YEAR - 3) . '-06-15', 'amount' => 60000]], ['user_id' => $this->userId]);
            self::fail('Zhodnocení před zařazením musí být odmítnuto.');
        } catch (AssetException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND inventory_number = 'M-HIST-TZ2'");
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testPutIntoUseRefusesActivationEntryForCardWithOpeningState(): void
    {
        $first = (string) $this->db->pdo()->query('SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        $acquired = ((int) substr($first, 0, 4) - 4) . '-04-01';
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-OPEN-001',
            'name' => 'Převzatý byt',
            'input_price' => 6000000.00,
            'acquisition_date' => $acquired,
            'put_into_use_date' => $acquired,
            'tax_method' => 'straight',
            'tax_group' => 5,
            'opening_tax_years' => 4,
            'opening_tax_amount' => 696000.00,
            'opening_acc_months' => 56,
            'opening_acc_amount' => 900000.00,
            'acc_method' => 'by_tax',
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];

        try {
            $this->service->putIntoUse($this->supplierId, $assetId, $acquired, true, ['user_id' => $this->userId]);
            self::fail('Karta s počátečními oprávkami nesmí dostat zápis o zařazení.');
        } catch (AssetException $e) {
            self::assertSame('opening_state_entry', $e->errorCode);
        }
        $this->service->putIntoUse($this->supplierId, $assetId, $acquired, false, ['user_id' => $this->userId]);
        self::assertSame('in_use', $this->assets->find($this->supplierId, $assetId)['status']);
        self::assertSame(0, $this->activationEntries($assetId));
    }

    public function testPutIntoUseRefusesActivationEntryBeforeFirstPeriod(): void
    {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-OLD-001',
            'name' => 'Starý stroj',
            'input_price' => 100000.00,
            'acquisition_date' => '1990-06-01',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];

        try {
            $this->service->putIntoUse($this->supplierId, $assetId, '1990-06-01', true, ['user_id' => $this->userId]);
            self::fail('Zápis o zařazení před prvním obdobím nesmí založit dávné období.');
        } catch (AssetException $e) {
            self::assertSame('period_before_start', $e->errorCode);
        }
        self::assertNull($this->periods->findForDate($this->supplierId, '1990-06-01'));
    }

    public function testRevertPutIntoUseDeletesActivationEntryAndAllowsDateFix(): void
    {
        $assetId = $this->createAssetInUse('M-REV-001', self::YEAR . '-04-01');
        self::assertSame(1, $this->activationEntries($assetId));
        try {
            $this->service->update($this->supplierId, $assetId, ['put_into_use_date' => self::YEAR . '-05-01'], ['user_id' => $this->userId]);
            self::fail('Zařazená karta nemění datum zařazení.');
        } catch (AssetException $e) {
            self::assertSame('asset_locked', $e->errorCode);
        }

        $result = $this->service->revertPutIntoUse($this->supplierId, $assetId, ['user_id' => $this->userId]);
        self::assertSame('draft', $result['asset']['status']);
        self::assertNotNull($result['activation_entry_id']);
        self::assertSame(0, $this->activationEntries($assetId));
        self::assertEqualsWithDelta(0.0, $this->accountBalance('022'), 0.001);

        $this->service->update($this->supplierId, $assetId, ['put_into_use_date' => self::YEAR . '-05-01'], ['user_id' => $this->userId]);
        $this->service->putIntoUse($this->supplierId, $assetId, self::YEAR . '-05-01', true, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertSame(self::YEAR . '-05-01', (string) $this->assets->find($this->supplierId, $assetId)['put_into_use_date']);

        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        try {
            $this->service->revertPutIntoUse($this->supplierId, $assetId, ['user_id' => $this->userId]);
            self::fail('Karta se zaúčtovanými odpisy se do konceptu nevrací.');
        } catch (AssetException $e) {
            self::assertSame('asset_has_depreciation', $e->errorCode);
        }
    }

    public function testPutIntoUseAllowsActivationEntryForInYearCardWithOpeningState(): void
    {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-OPEN-IN-001',
            'name' => 'Převzatý stroj pořízený v roce',
            'input_price' => 240000.00,
            'acquisition_date' => (self::YEAR + 1) . '-03-01',
            'put_into_use_date' => (self::YEAR + 1) . '-03-01',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'opening_acc_months' => 2,
            'opening_acc_amount' => 8000.00,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];

        $this->service->putIntoUse($this->supplierId, $assetId, (self::YEAR + 1) . '-03-01', true,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertSame(1, $this->activationEntries($assetId));
    }

    public function testPutIntoUseRefusesSecondActivationWhenJournalAlreadyHasIt(): void
    {
        $price = 333333.33;
        $date = self::YEAR . '-06-10';
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '022', 'side' => 'debit', 'amount' => $price],
            ['account_code' => '042', 'side' => 'credit', 'amount' => $price],
        ], [
            'entry_date' => $date,
            'document_no' => 'ID-ZAR-1',
            'description' => 'Zařazení převzaté z jiného systému',
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-DUP-001',
            'name' => 'Stroj zařazený v deníku',
            'input_price' => $price,
            'acquisition_date' => $date,
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];

        try {
            $this->service->putIntoUse($this->supplierId, $assetId, $date, true, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            self::fail('Druhý zápis o zařazení se stejnou cenou nesmí vzniknout.');
        } catch (AssetException $e) {
            self::assertSame('activation_already_posted', $e->errorCode);
            self::assertStringContainsString('ID-ZAR-1', $e->getMessage());
        }
        $this->service->putIntoUse($this->supplierId, $assetId, $date, false, ['user_id' => $this->userId]);
        self::assertSame('in_use', $this->assets->find($this->supplierId, $assetId)['status']);
        self::assertSame(0, $this->activationEntries($assetId));
        self::assertEqualsWithDelta($price, $this->accountBalance('022'), 0.001);
    }

    private function activationEntries(int $assetId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'asset' AND source_id = ?");
        $stmt->execute([$this->supplierId, $assetId]);
        return (int) $stmt->fetchColumn();
    }

    // ── I5: tenant izolace ─────────────────────────────────────────────────────

    public function testI5TenantIsolation(): void
    {
        $assetId = $this->createAssetInUse('M-I5-001', self::YEAR . '-02-10');
        $otherSupplier = $this->supplierId + 99999;

        self::assertNull($this->assets->find($otherSupplier, $assetId), 'Cross-tenant find vrací null.');

        $list = $this->assets->list($otherSupplier, []);
        self::assertNotContains($assetId, array_column($list['items'], 'id'), 'Cross-tenant list kartu nevrátí.');

        try {
            $this->service->plan($otherSupplier, $assetId);
            self::fail('Cross-tenant plan musí skončit 404.');
        } catch (AssetException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createAssetInUse(
        string $inventoryNumber,
        string $useDate,
        float $price = 500000.00,
        int $taxGroup = 2,
        int $lifeMonths = 60,
    ): int {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => $price,
            'acquisition_date' => $useDate,
            'tax_method' => 'straight',
            'tax_group' => $taxGroup,
            'acc_useful_life_months' => $lifeMonths,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];
        $this->service->putIntoUse($this->supplierId, $assetId, $useDate, true,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        return $assetId;
    }

    /** Zůstatek syntetického účtu (MD − D) přes všechny zápisy firmy. */
    private function accountBalance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(array $lines): array
    {
        $codeById = [];
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codeById[(int) $r['id']] = (string) $r['account_code'];
        }
        $out = [];
        foreach ($lines as $l) {
            $code = $codeById[(int) $l['account_id']] ?? '?';
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, float $base, float $vat, float $rate, bool $fixedAsset): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, is_fixed_asset, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?, ?)'
        );
        $issue = self::YEAR . '-03-10';
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $fixedAsset ? 1 : 0, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, is_fixed_asset, order_index)
             VALUES (?, "Stroj", 1, "ks", ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with, $fixedAsset ? 1 : 0]);
        return $id;
    }
}
