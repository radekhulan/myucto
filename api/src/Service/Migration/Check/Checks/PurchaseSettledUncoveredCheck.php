<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MoneyS3RepairPlan;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Kontrola 6: přijaté doklady uhrazené podle původního programu, které evidované úhrady
 * nepokrývají.
 *
 * Párování banky bere takový doklad jako nekrytý a nabídne k němu platbu se stejným
 * symbolem znovu. Převod úhradu, kterou nemá kam navázat (zápočet, úhrada v nepřevedeném
 * roce), nese v `settled_outside_amount` ({@see PurchaseSettledExpr}).
 *  - Money S3: výběr a doplnění jsou {@see MoneyS3SettledPayments::purchaseCandidates()}
 *    a {@see MoneyS3SettledPayments::restorePurchaseSettlements()} (zápočet interním dokladem,
 *    zbytek mimo evidenci). Doplnění je jedno pro všechny doklady firmy, nález v chráněném
 *    období ho zastaví celé ({@see MigrationCheckLocks::allOrNothing()}).
 *  - Helios: doklad z mapy převodu, který Helios vede jako uhrazený v plné výši v měně dokladu
 *    (`paid_amount_invoice_ccy`), doplní se zbytek do částky k úhradě ({@see heliosItems()}).
 *  - POHODA: doklad z mapy převodu ve stavu uhrazený, s datem úhrady do konce posledního
 *    převedeného roku a v Kč. Úhrady POHODY už nejsou k dispozici; uhrazený stav POHODY ale
 *    znamená nulový zůstatek, takže chybějící krytí je celý zbytek do částky k úhradě, stejně
 *    jako u převodu (`Pohoda\DocumentLinker::restorePurchaseSettlements`). U dokladu v cizí
 *    měně se doplní zbytek v měně dokladu: úhrady POHODY jsou v Kč, uhrazený stav ale znamená
 *    nulový zůstatek v Kč a rozdíl (kurzový rozdíl, zápočet) vyrovnala POHODA mimo evidenci.
 * Deník, DPH ani částka k úhradě se nemění, doplní se jen evidence úhrady. Uzavřené účetní
 * období proto opravu neblokuje, jen zámek účtování k datu a podané období DPH
 * ({@see MigrationCheckLocks::paymentRecordReason()}).
 */
final class PurchaseSettledUncoveredCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'purchase_settled_uncovered';
    public const AUDIT_ACTION_POHODA = 'purchase_invoice.pohoda_settled_outside_restored';
    public const AUDIT_ACTION_HELIOS = 'purchase_invoice.helios_settled_outside_restored';

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly MoneyS3SettledPayments $money,
        private readonly MigrationCheckLocks $locks,
        private readonly ActivityLogger $activity,
        private readonly MoneyS3RepairPlan $moneyPlan,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Přijaté doklady uhrazené bez krytí úhradou';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1268-prijate-doklady-uhrazene-bez-kryti';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3, MigrationSources::HELIOS];
    }

    public function options(): array
    {
        return [];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        if ($this->sources->has($supplierId, MigrationSources::POHODA)) {
            $items = array_merge($items, $this->pohodaItems($supplierId, false));
        }
        if ($this->sources->has($supplierId, MigrationSources::HELIOS)) {
            $items = array_merge($items, $this->heliosItems($supplierId, false));
        }
        $money = $this->sources->has($supplierId, MigrationSources::MONEY_S3) ? $this->moneyItems($supplierId) : [];
        return new CheckResult(array_merge($items, MigrationCheckLocks::allOrNothing(new CheckResult($money))->items));
    }

    /**
     * Náhled ukazuje všechny skutečné změny: u Money S3 plán z provedení krytí v savepointu,
     * který se vrátí ({@see MoneyS3RepairPlan::purchases()}), včetně dokladů, kterým se jen
     * mění skladba krytí. Volat uvnitř transakce.
     *
     * @param array<string,bool> $options
     */
    public function preview(int $supplierId, array $options = []): FixPreview
    {
        return $this->plan($supplierId, false)['preview'];
    }

    /** @param array<string,bool> $options */
    public function fix(int $supplierId, ?int $userId, array $options = [], ?string $fingerprint = null): FixResult
    {
        $pdo = $this->db->pdo();
        $plan = $this->plan($supplierId, true);
        $plan['preview']->assertConfirmed($fingerprint);
        $fixed = [];
        $notes = [];
        $update = $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = settled_outside_amount + ? WHERE id = ? AND supplier_id = ?');
        foreach ($plan['preview']->changes as $item) {
            $source = $item->details['source'] ?? '';
            if (!in_array($source, [MigrationSources::POHODA, MigrationSources::HELIOS], true)) {
                continue;
            }
            $update->execute([number_format((float) $item->amount, 2, '.', ''), $item->entityId, $supplierId]);
            $this->activity->log($source === MigrationSources::HELIOS ? self::AUDIT_ACTION_HELIOS : self::AUDIT_ACTION_POHODA, $userId, 'purchase_invoice', $item->entityId, [
                'source' => 'migration_data_check',
                'check' => self::KEY,
                'document_no' => $item->label,
                'paid_at' => $item->date,
                'settled_outside_added' => $item->amount,
                'settled_outside_before' => $item->details['outside'] ?? null,
            ], null, null, $supplierId);
            $fixed[] = $item;
        }
        if ($plan['money'] !== null) {
            $money = $this->moneyPlan->purchases($supplierId, $userId, true);
            if (!$money['kept'] || FixPreview::fromResult(new CheckResult($money['items']))->fingerprint() !== $plan['money']) {
                throw new MigrationDataCheckException('stale_preview', 'Oprava Money S3 by změnila jiné doklady, než ukázal náhled. Otevřete náhled opravy znovu.', 409);
            }
            $moneyFixed = array_values(array_filter($money['items'], static fn (CheckItem $i): bool => $i->state === CheckItem::FIXABLE));
            $fixed = array_merge($fixed, $moneyFixed);
            $notes[] = sprintf('Money S3: doplněno krytí %d přijatých dokladů.', count($moneyFixed));
        }
        return new FixResult($fixed, $plan['preview']->skipped, $notes);
    }

    /**
     * Plán opravy: POHODA z doplnění do částky k úhradě, Money S3 provedením krytí
     * v savepointu, který se vrátí. `money` je otisk změn Money S3 (null = nic).
     *
     * @return array{preview:FixPreview,money:?string}
     */
    private function plan(int $supplierId, bool $forUpdate): array
    {
        $items = $this->sources->has($supplierId, MigrationSources::POHODA) ? $this->pohodaItems($supplierId, $forUpdate) : [];
        if ($this->sources->has($supplierId, MigrationSources::HELIOS)) {
            $items = array_merge($items, $this->heliosItems($supplierId, $forUpdate));
        }
        $money = null;
        if ($this->sources->has($supplierId, MigrationSources::MONEY_S3)) {
            $moneyItems = $this->moneyPlan->purchases($supplierId, null, false)['items'];
            $items = array_merge($items, $moneyItems);
            $moneyResult = new CheckResult($moneyItems);
            $money = $moneyResult->countState(CheckItem::FIXABLE) > 0 ? FixPreview::fromResult($moneyResult)->fingerprint() : null;
        }
        return ['preview' => FixPreview::fromResult(new CheckResult($items)), 'money' => $money];
    }

    /** @return list<CheckItem> */
    private function pohodaItems(int $supplierId, bool $forUpdate): array
    {
        $end = $this->sources->agendaEnd($supplierId, MigrationSources::POHODA);
        if ($end === null) {
            return [];
        }
        $settled = PurchaseSettledExpr::settled('pi', currentRead: $forUpdate);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT pi.id, COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS document_no,
                       cur.code AS currency, pi.exchange_rate, pi.paid_at, pi.amount_to_pay, pi.settled_outside_amount AS outside,
                       ({$settled}) AS settled
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ? AND pi.status = 'paid' AND pi.amount_to_pay > 0
                   AND pi.paid_at IS NOT NULL AND pi.paid_at <= ?
                   AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = pi.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = pi.id)
             ) d
             WHERE d.amount_to_pay - d.settled >= 0.01
             ORDER BY d.paid_at, d.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $end . ' 23:59:59']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gap = round((float) $r['amount_to_pay'] - (float) $r['settled'], 2);
            $rate = (string) $r['currency'] === 'CZK' ? 1.0 : ((float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : 1.0);
            $paidAt = substr((string) $r['paid_at'], 0, 10);
            if (($lock = $this->locks->paymentRecordReason($supplierId, $paidAt)) !== null) {
                [$state, $reason] = [CheckItem::LOCKED, $lock];
            } elseif ((string) $r['currency'] !== 'CZK') {
                [$state, $reason] = [CheckItem::FIXABLE, 'Uhrazeno podle POHODY (nulový zůstatek v Kč). Zbytek v měně dokladu, který evidované úhrady nepokrývají (kurzový rozdíl, zápočet), se doplní jako úhrada mimo evidenci.'];
            } else {
                [$state, $reason] = [CheckItem::FIXABLE, 'Uhrazeno podle POHODY, evidované úhrady doklad nepokrývají. Zbytek se doplní jako úhrada mimo evidenci.'];
            }
            $out[] = new CheckItem('purchase_invoice', (int) $r['id'], (string) $r['document_no'], $paidAt, round($gap * $rate, 2), $state, $reason,
                $gap, (string) $r['currency'], ['source' => MigrationSources::POHODA, 'outside' => (float) $r['outside']]);
        }
        return $out;
    }

    /**
     * Helios: doklad z mapy převodu ve stavu uhrazený, který Helios vede jako uhrazený v plné
     * výši (`paid_amount_invoice_ccy` v měně dokladu kryje částku k úhradě). Převod úhradu nese
     * jen touto částkou a stavem, evidence úhrad ji nepokrývá. Chybějící krytí je zbytek do
     * částky k úhradě v měně dokladu. Doklad uhrazený podle Heliosu jen zčásti se jen ohlásí.
     *
     * @return list<CheckItem>
     */
    private function heliosItems(int $supplierId, bool $forUpdate): array
    {
        $settled = PurchaseSettledExpr::settled('pi', currentRead: $forUpdate);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT pi.id, COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS document_no,
                       cur.code AS currency, pi.exchange_rate, COALESCE(pi.paid_at, pi.tax_date, pi.issue_date) AS paid_at, pi.amount_to_pay,
                       pi.settled_outside_amount AS outside, COALESCE(pi.paid_amount_invoice_ccy, 0) AS source_paid, ({$settled}) AS settled
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ? AND pi.status = 'paid' AND pi.amount_to_pay > 0
                   AND EXISTS (SELECT 1 FROM helios_import_map m WHERE m.supplier_id = pi.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = pi.id)
             ) d
             WHERE d.amount_to_pay - d.settled >= 0.01
             ORDER BY d.paid_at, d.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gap = round((float) $r['amount_to_pay'] - (float) $r['settled'], 2);
            $rate = (string) $r['currency'] === 'CZK' ? 1.0 : ((float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : 1.0);
            $paidAt = substr((string) $r['paid_at'], 0, 10);
            if ((float) $r['source_paid'] < (float) $r['amount_to_pay'] - 0.01) {
                [$state, $reason] = [CheckItem::REVIEW, 'Helios vede uhrazenou jen část dokladu, krytí doplňte ručně (Uhrazeno mimo evidenci v detailu dokladu).'];
            } elseif (($lock = $this->locks->paymentRecordReason($supplierId, $paidAt)) !== null) {
                [$state, $reason] = [CheckItem::LOCKED, $lock];
            } else {
                [$state, $reason] = [CheckItem::FIXABLE, 'Uhrazeno podle Heliosu v plné výši, evidované úhrady doklad nepokrývají. Zbytek se doplní jako úhrada mimo evidenci.'];
            }
            $out[] = new CheckItem('purchase_invoice', (int) $r['id'], (string) $r['document_no'], $paidAt, round($gap * $rate, 2), $state, $reason,
                $gap, (string) $r['currency'], ['source' => MigrationSources::HELIOS, 'outside' => (float) $r['outside']]);
        }
        return $out;
    }

    /** @return list<CheckItem> */
    private function moneyItems(int $supplierId): array
    {
        $lastEnd = $this->money->lastTransferredPeriodEnd($supplierId);
        if ($lastEnd === null) {
            return [];
        }
        $out = [];
        foreach ($this->money->purchaseCandidates($supplierId, $lastEnd) as $p) {
            $gap = round($p['amount_to_pay'] - $p['covered'] - $p['outside'], 2);
            if ($gap < 0.01) {
                continue;
            }
            $rate = $p['currency'] === 'CZK' ? 1.0 : ($p['rate'] ?? 1.0);
            $lock = $this->locks->paymentRecordReason($supplierId, $p['paid_at']);
            $out[] = new CheckItem('purchase_invoice', $p['id'], $p['document_no'], $p['paid_at'], round($gap * $rate, 2),
                $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED,
                $lock ?? 'Uhrazeno podle Money S3, evidované úhrady doklad nepokrývají. Doplní se zápočet interním dokladem, zbytek jako úhrada mimo evidenci.',
                $gap, $p['currency'], ['source' => MigrationSources::MONEY_S3]);
        }
        return $out;
    }
}
