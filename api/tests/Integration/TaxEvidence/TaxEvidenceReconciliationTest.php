<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic DE (A4, §8) — reconciliation wiring: IncomeTaxBuilder kasová báze (§8.2, R5) a
 * TaxOptimizer skutečné výdaje z deníku (§8.1, R12, bez write-backu).
 *
 * Mode-gating: cesty se aktivují JEN pro accounting_mode='tax_evidence' AND
 * taxpayer_type='fo'. double_entry i 'po' zůstávají na akruální cestě (regresní guard).
 * Vše v rollbackované transakci na throwaway supplieru (CashJournalTestCase), supplier 1
 * se nikdy nemění.
 */
#[Group('integration')]
final class TaxEvidenceReconciliationTest extends CashJournalTestCase
{
    /**
     * §8.1 (R12): flag `use_evidence_expenses=1` předá deníkový daňový výdaj do
     * computeRegular přes use_actual_expenses/actual_expenses — a NEZAPÍŠE ho do
     * tax_profiles. Bez flagu chování beze změny (paušál).
     */
    public function testOptimizerUsesEvidenceExpensesWithoutWriteBack(): void
    {
        $year = 2024; // uzavřený rok → retrospektiva (compare)
        $this->setVatPayer($this->supplierId, false);
        $this->setTaxpayerType($this->supplierId, 'fo');

        // Příjem 500 000 (annualIncome) + deníkový výdaj 200 000 (ručně zaplacená PF, noha C).
        $inv = $this->saleInvoice($this->supplierId, [
            'without' => 500000.0, 'with' => 500000.0, 'status' => 'paid',
            'paid_at' => "$year-06-15", 'issue_date' => "$year-06-10",
        ]);
        $this->invoicePayment($this->supplierId, $inv, 500000.0, 'mark_paid', null, "$year-06-15");
        $this->purchaseInvoice($this->supplierId, [
            'without' => 200000.0, 'with' => 200000.0, 'status' => 'paid',
            'paid_at' => "$year-06-20", 'issue_date' => "$year-06-10",
        ]);

        // Uložený profil: paušál (use_actual_expenses=0, actual_expenses=0) — ruční override.
        $this->db->pdo()->prepare(
            'INSERT INTO tax_profiles (supplier_id, year, activity_rate, use_actual_expenses, actual_expenses, flat_tax_band)
             VALUES (?, ?, "60", 0, 0, "none")'
        )->execute([$this->supplierId, $year]);

        $action = $this->container->get(TaxAction::class);

        // Bez flagu → paušál (use_actual=false), výdaj ≠ 200 000.
        $noFlag = $this->callAnalysis($action, $this->supplierId, $year, false);
        self::assertFalse($noFlag['compare']['regular']['use_actual'], 'Bez flagu zůstává paušál.');
        self::assertArrayNotHasKey('evidence_expenses', $noFlag);

        // S flagem → skutečné výdaje z deníku (200 000).
        $flag = $this->callAnalysis($action, $this->supplierId, $year, true);
        self::assertTrue($flag['compare']['regular']['use_actual'], 'S flagem = skutečné výdaje z evidence.');
        self::assertEqualsWithDelta(200000.0, $flag['compare']['regular']['expenses'], 0.01);
        self::assertEqualsWithDelta(200000.0, $flag['evidence_expenses']['denik_vydaj_danovy'], 0.01);
        self::assertEqualsWithDelta(500000.0, $flag['evidence_expenses']['denik_prijem_danovy'], 0.01);
        // Příjem (a tím i paušál kandidát) zůstává na annualIncome.
        self::assertEqualsWithDelta(500000.0, (float) $flag['income'], 0.01);

        // R12: NO write-back — tax_profiles zůstává na ručním override (0/0).
        $row = $this->db->pdo()->query(
            "SELECT use_actual_expenses, actual_expenses FROM tax_profiles
              WHERE supplier_id = {$this->supplierId} AND year = {$year}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['use_actual_expenses'], 'use_actual_expenses NESMÍ být přepsán.');
        self::assertEqualsWithDelta(0.0, (float) $row['actual_expenses'], 0.01, 'actual_expenses NESMÍ být přepsán.');
    }

    /**
     * #52: projekce běžícího roku stojí na KASOVÉM, ale ZDANITELNÉM příjmu. Dřív tenhle
     * test pinoval opačné chování (osvobozený příjem se do ytd_income sčítal) — bylo to
     * ale jen zafixované chování kódu, ne záměr: osvobozený příjem nepatří ani do základu
     * daně a pojistného, ani do rozhodných příjmů pro pásmo paušálního režimu (§ 2a odst. 5
     * ZDP definuje rozhodné příjmy jako příjmy ze samostatné činnosti, § 7a odst. 1 písm. b)
     * bod 1 uvádí příjmy od daně osvobozené jako kategorii VEDLE rozhodných příjmů).
     * Fakturační větev (TaxProfileRepository::monthlyIncome) je vylučovala odjakživa.
     */
    public function testCurrentYearForecastExcludesExemptCashReceipts(): void
    {
        $year = (int) date('Y');
        $paidOn = sprintf('%04d-01-15', $year);
        $this->setVatPayer($this->supplierId, false);
        $this->setTaxpayerType($this->supplierId, 'fo');
        $this->setAccountingMode($this->supplierId, 'tax_evidence');

        $taxable = $this->saleInvoice($this->supplierId, [
            'without' => 10000.0, 'with' => 10000.0, 'status' => 'paid',
            'paid_at' => $paidOn, 'issue_date' => $paidOn,
        ]);
        $this->invoicePayment($this->supplierId, $taxable, 10000.0, 'mark_paid', null, $paidOn);
        $exempt = $this->saleInvoice($this->supplierId, [
            'without' => 5000.0, 'with' => 5000.0, 'status' => 'paid',
            'paid_at' => $paidOn, 'issue_date' => $paidOn, 'income_tax_exempt' => 1,
        ]);
        $this->invoicePayment($this->supplierId, $exempt, 5000.0, 'mark_paid', null, $paidOn);

        $body = $this->callAnalysis($this->container->get(TaxAction::class), $this->supplierId, $year, false);

        self::assertSame('forecast', $body['mode']);
        self::assertEqualsWithDelta(
            10000.0,
            (float) $body['ytd_income'],
            0.01,
            'Do projekce vstupuje jen zdanitelný příjem, osvobozený ne (#52).',
        );
        self::assertEqualsWithDelta(
            5000.0,
            (float) $body['exempt_income'],
            0.01,
            'Osvobozený příjem se ukazuje jen jako „z toho vyloučeno".',
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setTaxpayerType(int $supplierId, string $type): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET taxpayer_type = ? WHERE id = ?')
            ->execute([$type, $supplierId]);
    }

    private function setAccountingMode(int $supplierId, string $mode): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute([$mode, $supplierId]);
    }

    /**
     * Zavolá TaxAction::analysis a vrátí dekódované JSON tělo (Json::ok píše data napřímo).
     * @return array<string,mixed>
     */
    private function callAnalysis(TaxAction $action, int $supplierId, int $year, bool $useEvidence): array
    {
        $query = ['year' => (string) $year];
        if ($useEvidence) {
            $query['use_evidence_expenses'] = '1';
        }
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax/analysis')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withQueryParams($query);
        $resp = $action->analysis($req, new Psr7Response());
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
