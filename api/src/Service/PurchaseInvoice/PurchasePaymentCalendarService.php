<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;

/**
 * Platební kalendář přijatých dokladů (issue #140), typicky zálohy na energie.
 *
 * Platební kalendář je daňovým dokladem na úplaty před uskutečněním plnění (§ 31a ZDPH):
 * daň a nárok na odpočet vznikají k jednotlivým platbám, ne k datu kalendáře. Proto se
 * kalendář eviduje jako řada samostatných přijatých dokladů se stejným dodavatelem
 * a číslem ({@see VendorNumberDuplicates}), každý se svou splatností a DUZP = den
 * splatnosti platby. Každý doklad jde do evidence DPH, párování s bankou i peněžního
 * deníku stejnou cestou jako ručně zadaný.
 *
 * Vzorem je první platba zadaná v editoru. Z ní se zkopíruje hlavička (dodavatel, měna,
 * DPH režim, `prices_include_vat`, platební údaje) a položky se přepočtou na částku platby.
 * Ruční rekapitulace DPH se nekopíruje: po přepočtu by neodpovídala.
 */
final class PurchasePaymentCalendarService
{
    public const MAX_INSTALLMENTS = 60;

    /** Hlavičkové sloupce, které platby kalendáře přebírají ze vzoru. */
    private const HEADER_COLUMNS = [
        'vendor_id', 'vendor_is_vat_payer', 'vendor_invoice_number', 'document_kind', 'issue_date',
        'received_at', 'received_at_source', 'currency_id', 'exchange_rate', 'exchange_rate_date',
        'exchange_rate_source', 'reverse_charge', 'prices_include_vat', 'language', 'note_above_items',
        'note_below_items', 'vendor_snapshot', 'own_snapshot', 'payment_account_number', 'payment_bank_code',
        'payment_iban', 'payment_bic', 'payment_variable_symbol', 'payment_constant_symbol',
        'payment_account_source', 'payment_method', 'payment_method_source', 'cash_register_id',
        'vat_classification_code', 'vat_deduction', 'vat_deduction_percent', 'tax_deductible',
        'is_fixed_asset', 'expense_category_id', 'project_id',
    ];

    /** Položkové sloupce, které se kopírují beze změny (cena se přepočte zvlášť). */
    private const ITEM_COLUMNS = [
        'description', 'quantity', 'unit', 'vat_rate_id', 'vat_rate_snapshot', 'vat_classification_code',
        'order_index', 'expense_kind', 'expense_category_id', 'expense_account_code', 'is_fixed_asset',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly PurchaseInvoiceReceiver $receiver,
    ) {}

    /**
     * Validace rozpisu. Vrací chyby (prázdné = v pořádku) a normalizované platby.
     *
     * @return array{errors: list<string>, installments: list<array{due_date: string, amount: float}>}
     */
    public static function validate(mixed $installments): array
    {
        if (!is_array($installments) || $installments === []) {
            return ['errors' => ['Zadejte aspoň jednu další platbu.'], 'installments' => []];
        }
        if (count($installments) > self::MAX_INSTALLMENTS) {
            return ['errors' => ['Najednou lze založit nejvýš ' . self::MAX_INSTALLMENTS . ' plateb.'], 'installments' => []];
        }
        $errors = [];
        $out = [];
        foreach (array_values($installments) as $i => $row) {
            $date = is_array($row) ? (string) ($row['due_date'] ?? '') : '';
            $amount = is_array($row) && is_numeric($row['amount'] ?? null) ? round((float) $row['amount'], 2) : 0.0;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                $errors[] = 'Platba ' . ($i + 1) . ': neplatné datum splatnosti.';
                continue;
            }
            if ($amount <= 0) {
                $errors[] = 'Platba ' . ($i + 1) . ': částka musí být kladná.';
                continue;
            }
            $out[] = ['due_date' => $date, 'amount' => $amount];
        }
        return ['errors' => $errors, 'installments' => $out];
    }

    /**
     * Založí další platby kalendáře podle vzoru. Vrací ID nových dokladů.
     *
     * @param list<array{due_date: string, amount: float}> $installments validované {@see validate()}
     * @return list<int>
     * @throws \RuntimeException vzor nejde použít (zpráva pro UI)
     */
    public function createInstallments(int $supplierId, int $templateId, array $installments, ?int $userId): array
    {
        $template = $this->repo->find($templateId, $supplierId);
        if ($template === null) {
            throw new \RuntimeException('Přijatý doklad nenalezen.');
        }
        if (!in_array((string) $template['document_kind'], ['invoice', 'receipt', 'advance'], true)) {
            throw new \RuntimeException('Platební kalendář lze založit jen z faktury, účtenky nebo zálohy.');
        }
        if ((string) $template['status'] === 'cancelled') {
            throw new \RuntimeException('Ze stornovaného dokladu nelze založit platební kalendář.');
        }
        $templateTotal = round((float) $template['total_with_vat'], 2);
        if ($templateTotal <= 0) {
            throw new \RuntimeException('Vzor platebního kalendáře musí mít kladnou částku.');
        }

        $pdo = $this->db->pdo();
        $ids = [];
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($installments as $installment) {
                $ids[] = $this->cloneInstallment($pdo, $supplierId, $template, $installment, $templateTotal, $userId);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Přijetí až po commitu: háčky přijetí (pravidla nákladů, karta, pokladna) si
        // drží vlastní transakce a jsou měkké. Koncept zůstává konceptem jako vzor.
        if ((string) $template['status'] !== 'draft') {
            foreach ($ids as $id) {
                $this->receiver->receiveDraft($supplierId, $id, $userId, null, null, ['trigger' => 'payment_calendar']);
            }
        }
        return $ids;
    }

    /**
     * @param array<string,mixed> $template
     * @param array{due_date: string, amount: float} $installment
     */
    private function cloneInstallment(PDO $pdo, int $supplierId, array $template, array $installment, float $templateTotal, ?int $userId): int
    {
        $seq = VendorNumberDuplicates::nextSeq(
            $pdo,
            $supplierId,
            (int) $template['vendor_id'],
            (string) $template['vendor_invoice_number'],
            (string) $template['issue_date'],
        );
        $cols = self::HEADER_COLUMNS;
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, ' . implode(', ', $cols) . ', due_date, tax_date, vendor_number_seq, status, created_by)
             SELECT supplier_id, ' . implode(', ', $cols) . ", ?, ?, ?, 'draft', ?
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?"
        )->execute([
            $installment['due_date'],
            $installment['due_date'],
            $seq,
            $userId !== null && $userId > 0 ? $userId : (int) $template['created_by'],
            (int) $template['id'],
            $supplierId,
        ]);
        $newId = (int) $pdo->lastInsertId();

        $factor = $installment['amount'] / $templateTotal;
        $itemCols = self::ITEM_COLUMNS;
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, ' . implode(', ', $itemCols) . ', unit_price_without_vat)
             SELECT ?, ' . implode(', ', $itemCols) . ', ROUND(unit_price_without_vat * ?, 6)
               FROM purchase_invoice_items WHERE purchase_invoice_id = ?
              ORDER BY order_index, id'
        )->execute([$newId, $factor, (int) $template['id']]);

        $this->calc->recompute($newId);
        $this->repo->syncHeaderClassificationFromItems($newId, $supplierId);
        return $newId;
    }
}
