<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentScheduleRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Oss\OssItemCarryOver;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

/**
 * Interní doklady splátek vystaveného kalendáře.
 *
 * Kalendář je jeden daňový doklad pro odběratele, ale pohledávka, daň i úhrada žijí po
 * splátkách. Každá splátka proto dostane vlastní řádek v `invoices` a dál jde cestou
 * běžného dokladu: párování s bankou, upomínky, evidence DPH, zaúčtování i dobropis.
 * Přijatá strana eviduje platební kalendář stejně, jako řadu dokladů.
 *
 * Do evidence DPH hlásí splátka číslo a součet kalendáře: odběratel zná jen kalendář
 * a limit kontrolního hlášení se posuzuje podle celého dokladu. PDF se tiskne z kalendáře
 * ({@see \MyInvoice\Service\Pdf\InvoicePdfRenderer::render()}).
 */
final class CalendarInstalmentService
{
    public const NUMBER_MAX_LENGTH = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentScheduleRepository $schedule,
        private readonly OssItemCarryOver $ossCarry,
        private readonly FiledVatPeriodLookup $filedPeriods,
    ) {}

    /**
     * Důvod, proč kalendář nejde vystavit, nebo null.
     *
     * @param array<string,mixed> $invoice včetně `items`
     * @return array{code:string, message:string}|null
     */
    public function issueRejection(array $invoice): ?array
    {
        $supplierId = (int) $invoice['supplier_id'];
        $rows = $this->schedule->forInvoice($supplierId, (int) $invoice['id']);
        if ($rows === []) {
            return ['code' => 'payment_schedule_missing', 'message' => 'Kalendář nelze vystavit bez rozpisu plateb (§ 31 a § 31a ZDPH).'];
        }
        if (strtoupper((string) ($invoice['currency'] ?? 'CZK')) !== 'CZK') {
            return ['code' => 'calendar_foreign_currency', 'message' => 'Kalendář v cizí měně zatím nejde vystavit: kurz se váže ke dni každé splátky.'];
        }
        foreach (['base_amount' => 'total_without_vat', 'vat_amount' => 'total_vat', 'total_amount' => 'total_with_vat'] as $rowColumn => $header) {
            $sum = array_sum(array_map(static fn (array $r): int => (int) round($r[$rowColumn] * 100), $rows));
            if ($sum !== (int) round((float) ($invoice[$header] ?? 0) * 100)) {
                return [
                    'code'    => 'payment_schedule_mismatch',
                    'message' => sprintf(
                        'Součet rozpisu plateb (%s) nesedí na doklad (%s). Základ, DPH i celkovou částku splátek srovnejte s dokladem.',
                        number_format($sum / 100, 2, ',', ' '),
                        number_format((float) ($invoice[$header] ?? 0), 2, ',', ' '),
                    ),
                ];
            }
        }
        if ($invoice['invoice_type'] === InvoiceCalendar::RENT) {
            if (trim((string) ($invoice['calendar_contract_ref'] ?? '')) === '') {
                return ['code' => 'calendar_contract_required', 'message' => 'Splátkový kalendář musí být součástí nájemní smlouvy nebo na něj smlouva musí odkazovat (§ 31 ZDPH). Doplňte odkaz na smlouvu.'];
            }
            foreach ($rows as $row) {
                $taxDate = $row['tax_date'] ?? $row['due_on'];
                $filed = $this->filedPeriods->describe($supplierId, $taxDate, false);
                if ($filed !== null) {
                    return ['code' => 'calendar_filed_period', 'message' => sprintf('DUZP splátky %s spadá do podaného období (%s).', $taxDate, $filed)];
                }
                try {
                    VatRateValidityGuard::assertValidOn($this->db->pdo(), array_column($invoice['items'] ?? [], 'vat_rate_id'), $taxDate);
                } catch (\DomainException $e) {
                    return ['code' => 'calendar_vat_rate_invalid', 'message' => $e->getMessage()];
                }
            }
        }

        return null;
    }

    /**
     * Založí doklady splátek. Volá se v transakci vystavení, až má kalendář číslo.
     *
     * @param array<string,mixed> $calendar kalendář včetně `items`
     */
    public function createInstalments(array $calendar, string $varsymbol): void
    {
        $supplierId = (int) $calendar['supplier_id'];
        $calendarId = (int) $calendar['id'];
        $type = (string) $calendar['invoice_type'];
        $rows = $this->schedule->forInvoice($supplierId, $calendarId);
        $count = count($rows);
        $width = max(2, strlen((string) $count));
        $items = array_values(array_filter($calendar['items'] ?? [], static fn (array $i): bool => !InvoiceItemKind::isText($i)));
        $allocation = InvoiceCalendar::allocate($items, $rows);
        $paymentVs = trim((string) ($calendar['payment_variable_symbol'] ?? '')) ?: VariableSymbolNormalizer::forPayment($varsymbol);
        $isEn = ($calendar['language'] ?? 'cs') === 'en';

        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            "INSERT INTO invoices
                (supplier_id, branding_profile_id, varsymbol, payment_variable_symbol, supplier_order_number,
                 invoice_type, calendar_id, client_id, project_id, issue_date, tax_date, due_date, currency_id,
                 reverse_charge, auto_send_reminders, language, note_above_items,
                 client_snapshot, supplier_snapshot, bank_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, rounding_mode, status, payment_method,
                 vat_classification_code, revenue_category_id, revenue_rule_key, prices_include_vat,
                 income_tax_exempt, income_tax_exempt_reason, created_by, issued_by, issued_by_name)
             SELECT supplier_id, branding_profile_id, ?, ?, supplier_order_number,
                 ?, id, client_id, project_id, ?, ?, ?, currency_id,
                 reverse_charge, auto_send_reminders, language, ?,
                 client_snapshot, supplier_snapshot, bank_snapshot,
                 ?, ?, ?, ?, 'none', 'issued', payment_method,
                 vat_classification_code, revenue_category_id, revenue_rule_key, prices_include_vat,
                 income_tax_exempt, income_tax_exempt_reason, created_by, issued_by, issued_by_name
               FROM invoices WHERE id = ?"
        );
        $ossColumns = $this->ossCarry->columns();
        $insertItem = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, item_kind,
                 vat_classification_code, revenue_account_code'
            . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
            . ') VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . $this->ossCarry->placeholders() . ')'
        );
        $link = $pdo->prepare('UPDATE invoice_payment_schedule SET instalment_invoice_id = ? WHERE id = ? AND supplier_id = ?');

        foreach ($rows as $n => $row) {
            $number = sprintf('%s/%0' . $width . 'd', $varsymbol, $n + 1);
            if (strlen($number) > self::NUMBER_MAX_LENGTH) {
                throw new \DomainException(sprintf('Číslo splátky %s je delší než %d znaků.', $number, self::NUMBER_MAX_LENGTH));
            }
            $taxDate = $type === InvoiceCalendar::RENT ? ($row['tax_date'] ?? $row['due_on']) : null;
            $label = $isEn
                ? sprintf('Instalment %d/%d of calendar %s', $n + 1, $count, $varsymbol)
                : sprintf('Splátka %d/%d kalendáře %s', $n + 1, $count, $varsymbol);
            $insert->execute([
                $number,
                $paymentVs !== '' ? $paymentVs : null,
                InvoiceCalendar::instalmentType($type),
                $taxDate ?? $row['due_on'],
                $taxDate,
                $row['due_on'],
                $label,
                $row['base_amount'],
                $row['vat_amount'],
                $row['total_amount'],
                round($row['total_amount'] - $row['base_amount'] - $row['vat_amount'], 2),
                $calendarId,
            ]);
            $instalmentId = (int) $pdo->lastInsertId();

            foreach ($items as $i => $item) {
                $base = $allocation[$n][$i]['base'];
                $vat = $allocation[$n][$i]['vat'];
                $gross = round($base + $vat, 2);
                $insertItem->execute([
                    $instalmentId,
                    (string) $item['description'] . ' - ' . $label,
                    (string) ($item['unit'] ?? ''),
                    // Ceny s DPH: jednotková cena nese brutto, jinak by dobropis splátky daň navýšil.
                    !empty($calendar['prices_include_vat']) ? $gross : $base,
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $base,
                    $vat,
                    $gross,
                    $i,
                    InvoiceItemKind::of($item),
                    $item['vat_classification_code'] ?? null,
                    $item['revenue_account_code'] ?? null,
                    ...$this->ossCarry->values($item),
                ]);
            }
            $link->execute([$instalmentId, $row['id'], $supplierId]);
        }
    }

    /**
     * Stav kalendáře podle splátek: zaplacený je, až když žádná nezbývá. Volá se v transakci
     * zápisu platby, z dokladu splátky i z dokladu bez kalendáře (pak nic nedělá).
     */
    public static function syncCalendarStatus(PDO $pdo, int $instalmentId): void
    {
        $stmt = $pdo->prepare('SELECT calendar_id FROM invoices WHERE id = ?');
        $stmt->execute([$instalmentId]);
        $calendarId = (int) ($stmt->fetchColumn() ?: 0);
        if ($calendarId <= 0) {
            return;
        }
        $sum = $pdo->prepare(
            "SELECT COALESCE(SUM(paid_total), 0) AS paid,
                    SUM(status IN ('issued', 'sent', 'reminded')) AS open,
                    MAX(paid_at) AS last_paid
               FROM invoices
              WHERE calendar_id = ? AND invoice_type IN ('invoice', 'proforma')"
        );
        $sum->execute([$calendarId]);
        $state = $sum->fetch(PDO::FETCH_ASSOC) ?: ['paid' => 0, 'open' => 1, 'last_paid' => null];

        $pdo->prepare(
            "UPDATE invoices
                SET paid_total = ?,
                    status = CASE WHEN status = 'cancelled' THEN status
                                  WHEN ? = 0 THEN 'paid'
                                  WHEN status = 'paid' THEN IF(sent_at IS NOT NULL, 'sent', 'issued')
                                  ELSE status END,
                    paid_at = IF(? = 0, ?, NULL)
              WHERE id = ?"
        )->execute([(float) $state['paid'], (int) $state['open'], (int) $state['open'], $state['last_paid'], $calendarId]);
    }
}
