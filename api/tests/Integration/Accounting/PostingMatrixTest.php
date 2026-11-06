<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\TaxEvidence\TaxExpenseAllocationCalculator;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Matice TYP DOKLADU × ÚČETNÍ REŽIM → očekávané zaúčtování (fáze F2).
 *
 * Vznik: chyba #2 auditu (`JournalIntegrityService:341`) žila přesně v prázdné buňce
 * takové tabulky — pravidlo „u tax_document je očekávaná částka total_vat" existovalo
 * jen pro vydanou větev, protože nikdo neměl seznam, který by řekl, že přijatá větev
 * tu buňku má taky. Chybějící pravidlo není v kódu vidět: každá existující cesta je
 * lokálně správná. Vidět je až v tabulce, kde chybí řádek.
 *
 * Test proto dělá dvě věci a ta první je důležitější:
 *
 *   1. ÚPLNOST — čte skutečné hodnoty `ENUM` z `information_schema` a trvá na tom,
 *      že KAŽDÝ typ dokladu má v matici řádek pro OBA účetní režimy. Nový druh
 *      dokladu (nebo nová hodnota enumu z migrace) tenhle test shodí dřív, než se
 *      stihne tiše zaúčtovat nějak. Prázdná buňka = díra, přesně dle PLAN.md §L3.
 *
 *   2. CHOVÁNÍ — pro každou buňku ověří, že se doklad opravdu zaúčtuje deklarovanými
 *      účty a stranami, nebo že skončí deklarovanou chybou.
 *
 * Matice je zároveň ČITELNÝ SOUHRN účetní sémantiky celého systému — to je její
 * druhá funkce. Když se někdo ptá „jak se účtuje přijatý dobropis?", odpověď je tady,
 * ne rozptýlená v 1 800 řádcích `PostingService`.
 *
 * Režim `tax_evidence` nemá deník (kasová báze § 7b ZDP), takže se u něj ověřuje
 * jiná veličina: jestli doklad při úhradě zakládá daňový výdaj.
 */
#[Group('integration')]
final class PostingMatrixTest extends BankPostingTestCase
{
    private const MODES = ['double_entry', 'tax_evidence'];

    /** Účetní režim → sloupec matice, který ho popisuje. */
    private const MODE_COLUMN = [
        'double_entry' => 'journal',
        'tax_evidence' => 'cash_basis',
    ];

    /**
     * Vydaná větev — `invoices.invoice_type`.
     *
     * `journal`: `debit`/`credit` = množina účtů, které zápis MUSÍ obsahovat na dané
     * straně; `error` = doklad se zaúčtovat nemá a hlásí tento kód.
     * `cash_basis`: `income` = zakládá zdanitelný příjem v okamžiku úhrady.
     *
     * @var array<string, array{journal: array<string,mixed>, cash_basis: array<string,mixed>, note: string}>
     */
    private const ISSUED = [
        'invoice' => [
            'journal'    => ['debit' => ['311'], 'credit' => ['602', '343.200']],
            'cash_basis' => ['income' => true],
            'note'       => 'Běžná vydaná faktura: pohledávka proti výnosu a dani na výstupu.',
        ],
        'proforma' => [
            'journal'    => ['error' => 'document_not_postable'],
            'cash_basis' => ['income' => true],
            'note'       => 'Zálohová výzva není daňový doklad — do deníku jde až její INKASO (221/324). '
                . 'V kasové bázi je přijatá záloha příjmem v okamžiku úhrady.',
        ],
        'payment_calendar' => [
            'journal'    => ['error' => 'document_not_postable'],
            'cash_basis' => ['income' => true],
            'note'       => 'Splátkový a platební kalendář (§ 31 a § 31a ZDPH) JE daňovým dokladem, ale '
                . 'jeho vystavení není účetním případem — nese ROZPIS BUDOUCÍCH PLATEB, ne uskutečněné '
                . 'plnění. Do deníku jde až každá jednotlivá úhrada (u platebního kalendáře 221/324 '
                . 'jako přijatá záloha, § 20a); zaúčtovat kalendář jako celek by vykázalo výnos i daň '
                . 'dřív, než vznikly. Stejný důvod jako u proformy. V kasové bázi je příjmem až platba.',
        ],
        'rent_calendar' => [
            'journal'    => ['error' => 'document_not_postable'],
            'cash_basis' => ['income' => true],
            'note'       => 'Splátkový kalendář (§ 31) se neúčtuje, předpis 311/602/343 nesou doklady jeho splátek.',
        ],
        'advance_calendar' => [
            'journal'    => ['error' => 'document_not_postable'],
            'cash_basis' => ['income' => true],
            'note'       => 'Platební kalendář (§ 31a) se neúčtuje, inkaso i daň nesou splátky a daňové doklady k jejich úhradě.',
        ],
        'credit_note' => [
            'journal'    => ['debit' => ['602', '343.200'], 'credit' => ['311']],
            'cash_basis' => ['income' => true],
            'note'       => 'Dobropis obrací obě strany běžné faktury (vratka výnosu i daně).',
        ],
        'cancellation' => [
            'journal'    => ['error' => 'document_not_postable'],
            'cash_basis' => ['income' => false],
            'note'       => 'Stornovací doklad se neúčtuje; oprava zápisu jde protizápisem (§ 12 ZoÚ).',
        ],
        'tax_document' => [
            'journal'    => ['debit' => ['324'], 'credit' => ['343.200'], 'forbidden' => ['311', '602']],
            'cash_basis' => ['income' => false],
            'note'       => 'DDKP (§ 28) přiznává jen DPH ze zálohy: 324/343. ŽÁDNÁ pohledávka, ŽÁDNÝ výnos — '
                . 'peníze i příjem už proběhly na proformě.',
        ],
        'penalty' => [
            'journal'    => ['debit' => ['311'], 'credit' => ['644'], 'forbidden' => ['343.200']],
            'cash_basis' => ['income' => true],
            'note'       => 'Úrok z prodlení je mimo předmět DPH (§ 2 ZDPH) → žádná noha 343.',
        ],
    ];

    /**
     * Přijatá větev — `purchase_invoices.document_kind`.
     *
     * `cash_basis`: `expense` = úhrada zakládá daňový výdaj § 7b.
     *
     * @var array<string, array{journal: array<string,mixed>, cash_basis: array<string,mixed>, note: string}>
     */
    private const RECEIVED = [
        'invoice' => [
            'journal'    => ['debit' => ['518', '343.100'], 'credit' => ['321']],
            'cash_basis' => ['expense' => true],
            'note'       => 'Běžná přijatá faktura: náklad a odpočet daně proti závazku.',
        ],
        'receipt' => [
            'journal'    => ['debit' => ['518', '343.100'], 'credit' => ['321']],
            'cash_basis' => ['expense' => true],
            'note'       => 'Účtenka se účtuje shodně s fakturou; liší se jen průkazností dokladu, ne kontací.',
        ],
        'credit_note' => [
            'journal'    => ['debit' => ['321'], 'credit' => ['518', '343.100']],
            'cash_basis' => ['expense' => true],
            'note'       => 'Přijatý dobropis obrací obě strany. V kasové bázi vratka SNIŽUJE výdaj (N-010).',
        ],
        'advance' => [
            'journal'    => ['error' => 'advance_payment_only'],
            'cash_basis' => ['expense' => true],
            'note'       => 'Poskytnutá záloha se jako předpis neúčtuje — do deníku jde její ÚHRADA (314/221). '
                . 'V kasové bázi je ale zaplacená záloha výdajem hned (§ 7b), na rozdíl od podvojného účetnictví. '
                . 'Tahle asymetrie je ZÁMĚRNÁ; hlídá ji CashJournalScenariosTest.',
        ],
        'tax_document' => [
            'journal'    => ['debit' => ['343.100'], 'credit' => ['314'], 'forbidden' => ['518', '321']],
            'cash_basis' => ['expense' => false],
            'note'       => 'DDKP k POSKYTNUTÉ záloze (§ 28): jen odpočet DPH 343/314. NIKDY náklad — ten nese '
                . 'vyúčtovací faktura v plné výši. Přesně tady žila chyba #2 (N-001) i N-011.',
        ],
    ];

    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    // ── 1. ÚPLNOST ─────────────────────────────────────────────────────────────

    /**
     * Každá hodnota enumu × každý režim musí mít buňku. Enum se čte z DB, ne
     * z konstanty v testu — jinak by nová hodnota z migrace prošla bez povšimnutí,
     * což je právě způsob, jakým `tax_document` (migrace 1138) do systému přibyl.
     */
    public function testMatrixCoversEveryDocumentTypeAndMode(): void
    {
        $holes = [];

        foreach ([
            ['invoices', 'invoice_type', self::ISSUED],
            ['purchase_invoices', 'document_kind', self::RECEIVED],
        ] as [$table, $column, $matrix]) {
            $enumValues = $this->enumValues($table, $column);
            self::assertNotEmpty($enumValues, "Nepodařilo se přečíst ENUM {$table}.{$column} — guard by nekontroloval nic.");

            foreach ($enumValues as $value) {
                if (!isset($matrix[$value])) {
                    $holes[] = "{$table}.{$column} = '{$value}' — chybí CELÝ řádek matice";
                    continue;
                }
                foreach (self::MODES as $mode) {
                    $col = self::MODE_COLUMN[$mode];
                    if (!isset($matrix[$value][$col]) || $matrix[$value][$col] === []) {
                        $holes[] = "{$table}.{$column} = '{$value}' × {$mode} — prázdná buňka";
                    }
                }
                if (trim((string) ($matrix[$value]['note'] ?? '')) === '') {
                    $holes[] = "{$table}.{$column} = '{$value}' — buňka bez odůvodnění";
                }
            }

            // Opačný směr: řádek matice na neexistující hodnotu enumu je zastaralý
            // a maskuje, že se pravidlo už na nic nevztahuje.
            foreach (array_keys($matrix) as $declared) {
                if (!in_array($declared, $enumValues, true)) {
                    $holes[] = "{$table}.{$column}: matice popisuje '{$declared}', který v ENUM není";
                }
            }
        }

        self::assertSame([], $holes, sprintf(
            "Matice zaúčtování má díry:\n  %s\n\n"
                . "Každý typ dokladu musí mít v OBOU režimech definované očekávané chování.\n"
                . 'Prázdná buňka znamená, že se doklad zaúčtuje nějak, aniž by kdokoli řekl jak — '
                . 'a přesně v takové buňce žila chyba #2 tohoto auditu.',
            implode("\n  ", $holes),
        ));
    }

    // ── 2. CHOVÁNÍ — podvojné účetnictví ───────────────────────────────────────

    public function testIssuedPostingMatchesMatrix(): void
    {
        $client = $this->client('Odběratel matice s.r.o.');
        $proforma = $this->saleWithItem('MX-PRO', $client, 1000.00, 210.00, 'proforma');

        foreach (self::ISSUED as $type => $cell) {
            $parent = $type === 'tax_document' ? $proforma : null;
            $id = $this->saleWithItem('MX-' . strtoupper($type), $client, 1000.00, 210.00, $type, $parent);

            $this->assertCellBehaviour(
                "vydaná/{$type}",
                $cell['journal'],
                fn (): array => $this->posting->buildFromInvoice($this->supplierId, $id),
                'invoice',
                $id,
            );
        }
    }

    public function testReceivedPostingMatchesMatrix(): void
    {
        $vendor = $this->client('Dodavatel matice s.r.o.');

        foreach (self::RECEIVED as $kind => $cell) {
            $id = $this->purchaseWithItem('MX-P-' . strtoupper($kind), $vendor, 1000.00, 210.00, $kind);

            $this->assertCellBehaviour(
                "přijatá/{$kind}",
                $cell['journal'],
                fn (): array => $this->posting->buildFromPurchaseInvoice($this->supplierId, $id),
                'purchase_invoice',
                $id,
            );
        }
    }

    // ── 3. CHOVÁNÍ — daňová evidence (kasová báze § 7b) ────────────────────────

    /**
     * V daňové evidenci není deník; ověřovanou veličinou je, jestli úhrada dokladu
     * zakládá daňový výdaj. Právě tahle buňka byla u DDKP prázdná (N-011): kalkulátor
     * `document_kind` načítal a nikdy nevyhodnotil, takže DDKP vyrobil DRUHÝ výdaj
     * v plné výši.
     */
    public function testReceivedTaxEvidenceMatchesMatrix(): void
    {
        $calculator = $this->container->get(TaxExpenseAllocationCalculator::class);
        $vendor = $this->client('Dodavatel kasa s.r.o.');
        $paid = 1210.00;

        foreach (self::RECEIVED as $kind => $cell) {
            $id = $this->purchaseWithItem('MX-C-' . strtoupper($kind), $vendor, 1000.00, 210.00, $kind);

            $expense = $calculator->forPurchaseInvoice(
                $this->supplierId,
                $id,
                $paid,
                false,   // neplátce DPH → daňový výdaj je brutto
                self::YEAR,
                80000.0,
            );

            if ($cell['cash_basis']['expense'] === true) {
                self::assertGreaterThan(
                    0.0,
                    $expense,
                    "přijatá/{$kind}: v kasové bázi MÁ vzniknout daňový výdaj. " . $cell['note'],
                );
            } else {
                self::assertSame(
                    0.0,
                    $expense,
                    "přijatá/{$kind}: v kasové bázi NESMÍ vzniknout daňový výdaj. " . $cell['note'],
                );
            }
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $expected
     * @param \Closure():array<int,array<string,mixed>> $build
     */
    private function assertCellBehaviour(string $label, array $expected, \Closure $build, string $sourceType, int $sourceId): void
    {
        if (isset($expected['error'])) {
            try {
                $build();
                self::fail("{$label}: očekávána chyba '{$expected['error']}', doklad se ale zaúčtoval.");
            } catch (PostingException $e) {
                self::assertSame($expected['error'], $e->errorCode, "{$label}: jiný kód chyby.");
            }
            return;
        }

        $lines = $build();
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            $sourceType,
            $sourceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-16'],
        );
        $byAcc = $this->linesByAccountCode($entryId);

        foreach (['debit', 'credit'] as $side) {
            foreach ($expected[$side] ?? [] as $account) {
                self::assertGreaterThan(
                    0.0,
                    $byAcc[$account][$side] ?? 0.0,
                    "{$label}: chybí částka na {$account} ({$side}).",
                );
            }
        }
        foreach ($expected['forbidden'] ?? [] as $account) {
            self::assertArrayNotHasKey($account, $byAcc, "{$label}: účet {$account} tu nemá co dělat.");
        }

        $this->assertEntryBalanced($entryId, $label);
    }

    private function assertEntryBalanced(int $entryId, string $label): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ROUND(SUM(CASE WHEN side = 'debit' THEN amount ELSE -amount END), 2) AS diff
               FROM journal_entry_lines WHERE entry_id = ?"
        );
        $stmt->execute([$entryId]);
        self::assertEqualsWithDelta(0.0, (float) $stmt->fetchColumn(), 0.001, "{$label}: Σ MD ≠ Σ D (ČÚS 001).");
    }

    /**
     * @return list<string>
     */
    private function enumValues(string $table, string $column): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $type = (string) ($stmt->fetchColumn() ?: '');
        if (preg_match("/^enum\((.*)\)$/i", $type, $m) !== 1) {
            return [];
        }
        return array_map(
            static fn (string $v): string => trim($v, " '"),
            explode(',', $m[1]),
        );
    }

    private function saleWithItem(string $vs, int $clientId, float $base, float $vat, string $type, ?int $parentId = null): int
    {
        $with = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $vs, $type, $parentId, $clientId, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }

    private function purchaseWithItem(string $number, int $vendorId, float $base, float $vat, string $kind): int
    {
        $with = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }
}
