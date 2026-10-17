<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Klientské bankovní akce sdílejí pravidla pro doklady i serializaci jejich vazeb. */
final class ClientBankAccess
{
    public function __construct(private readonly Connection $db) {}

    public static function canRead(Request $request, string $type): bool
    {
        $permission = match ($type) {
            'invoice', 'split' => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            default => null,
        };
        return $permission !== null && RequestAuthorization::allows($request, $permission, AccessLevel::READ);
    }

    /**
     * Import výpisu páruje a uhrazuje doklady automaticky, takže klient bez práva
     * měnit úhrady by importem obešel kontrolu ručního párování ({@see assertDocuments()}).
     */
    public static function canImportStatement(Request $request): bool
    {
        return !RequestAuthorization::isClientType($request)
            || (RequestAuthorization::allows($request, 'invoices.mark_paid', AccessLevel::WRITE)
                && RequestAuthorization::allows($request, 'purchase_invoices.transition', AccessLevel::WRITE));
    }

    /** Ověřuje každý doklad splitu; volající drží zámek pohybu v otevřené transakci. */
    public function assertDocuments(Request $request, string $type, array $ids, bool $changesPayment = true): void
    {
        if (!RequestAuthorization::isClientType($request)) return;
        if (!self::canRead($request, $type)) throw new \DomainException('Chybí oprávnění pro čtení dokladu.', 403);
        $paymentPermission = $type === 'purchase_invoice' ? 'purchase_invoices.transition' : 'invoices.mark_paid';
        if ($changesPayment && !RequestAuthorization::allows($request, $paymentPermission, AccessLevel::WRITE)) {
            throw new \DomainException('Chybí oprávnění pro změnu úhrady dokladu.', 403);
        }
        $table = $type === 'purchase_invoice' ? 'purchase_invoices' : 'invoices';
        $stmt = $this->db->pdo()->prepare("SELECT * FROM $table WHERE id = ? AND supplier_id = ? FOR UPDATE");
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $stmt->execute([$id, SupplierGuard::currentId($request)]);
            $document = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$document) throw new \DomainException('Doklad nebyl nalezen.', 404);
            // Párování úhrady nemění obsah dokladu. Účetní blokace platby a jejího
            // zrušení vynucují stávající payment/release služby podle skutečné operace.
        }
    }

    /** Volající drží zámek pohybu; stejná dostupnost platí pro ruční i navržené párování. */
    public function assertAvailable(Request $request, int $transactionId): void
    {
        if (!RequestAuthorization::isClientType($request)) return;
        $stmt = $this->db->pdo()->prepare('SELECT bt.id FROM bank_transactions bt WHERE bt.id = ? AND '
            . \MyInvoice\Service\Bank\BankPaymentCandidateScope::sql(SupplierGuard::currentId($request)));
        $stmt->execute([$transactionId]);
        if ($stmt->fetchColumn() === false) throw new \DomainException('Pohyb již uzavírá jiná evidence. Vyžaduje zásah účetní.', 409);
    }

    public function assertAvailableCandidate(Request $request, array $candidate, array $transaction): void
    {
        $this->assertAvailable($request, (int) $transaction['id']);
        $this->assertCandidate($request, $candidate, true);
    }

    public function assertCandidate(Request $request, array $candidate, bool $changesPayment = false): void
    {
        $type = (string) ($candidate['type'] ?? '');
        $ids = $type === 'purchase_invoice' ? [(int) ($candidate['purchase_invoice_id'] ?? 0)]
            : ($type === 'split' ? (array) ($candidate['invoice_ids'] ?? []) : [(int) ($candidate['invoice_id'] ?? 0)]);
        if ($ids === [] || min(array_map('intval', $ids)) <= 0) throw new \DomainException('Neplatný doklad návrhu.', 409);
        $this->assertDocuments($request, $type, $ids, $changesPayment);
    }

    /** Žádná vazba mimo klientovy agendy se nesmí při zrušení párování změnit. */
    public function assertTransactionDocuments(Request $request, array $tx): void
    {
        if (!RequestAuthorization::isClientType($request)) return;
        $invoiceIds = $tx['matched_invoice_id'] ? [(int) $tx['matched_invoice_id']] : [];
        $purchaseIds = [];
        $stmt = $this->db->pdo()->prepare('SELECT invoice_id, purchase_invoice_id FROM payment_matches WHERE bank_transaction_id = ? FOR UPDATE');
        $stmt->execute([(int) $tx['id']]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['invoice_id']) $invoiceIds[] = (int) $row['invoice_id'];
            if ($row['purchase_invoice_id']) $purchaseIds[] = (int) $row['purchase_invoice_id'];
            if (!$row['invoice_id'] && !$row['purchase_invoice_id']) throw new \DomainException('Pohyb vyžaduje zásah účetní.', 403);
        }
        $stmt = $this->db->pdo()->prepare('SELECT invoice_id FROM invoice_payments WHERE bank_transaction_id = ? FOR UPDATE');
        $stmt->execute([(int) $tx['id']]);
        $invoiceIds = array_merge($invoiceIds, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        if ($invoiceIds !== []) $this->assertDocuments($request, 'invoice', $invoiceIds);
        if ($purchaseIds !== []) $this->assertDocuments($request, 'purchase_invoice', $purchaseIds);
        if ($invoiceIds === [] && $purchaseIds === []) throw new \DomainException('Pohyb nemá dostupné párování s dokladem.', 409);
    }

    /** Jeden dotaz pro celou stránku; SSOT zohledňuje i platby obsazené mimo faktury. */
    public function redactTransactions(Request $request, array $rows): array
    {
        if (!RequestAuthorization::isClientType($request) || $rows === []) return $rows;
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT bt.id, ' . \MyInvoice\Service\Bank\BankPaymentCandidateScope::sql(SupplierGuard::currentId($request))
            . " AS available FROM bank_transactions bt WHERE bt.id IN ($placeholders)");
        $stmt->execute($ids);
        $available = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map(static function (array $row) use ($request, $available): array {
            $posting = $row['posting'] ?? [];
            $row['resolution'] = !empty($posting['payroll_matched']) ? 'payroll'
                : (!empty($posting['transfer']) ? 'transfer' : (!empty($posting['outside_saldo']) ? 'outside_saldo' : null));
            $readOnlyNotice = ($row['confirmation_state'] ?? null) === 'confirmed'
                || ($row['confirmation_reason'] ?? null) === 'authoritative_removed';
            $free = !$readOnlyNotice && (bool) ($available[(int) $row['id']] ?? false);
            $row['match_available'] = !$readOnlyNotice && ($free || in_array($row['match_status'], ['auto_exact', 'auto_partial', 'manual'], true));
            $row['ignore_available'] = !$readOnlyNotice && ($free || ($row['match_status'] === 'ignored' && ($row['ignore_origin'] ?? null) === 'manual'));
            return self::redactTransaction($request, $row);
        }, $rows);
    }

    public static function redactStatement(Request $request, array $statement): array
    {
        if (!RequestAuthorization::isClientType($request)) return $statement;
        unset($statement['confirmation_summary'], $statement['deletion_blockers'],
            $statement['can_delete_notices'], $statement['deleted_notice_confirmations']);
        return $statement;
    }

    public static function redactTransaction(Request $request, array $row): array
    {
        if (!RequestAuthorization::isClientType($request)) return $row;
        foreach (['invoice' => ['matched_invoice_id', 'matched_varsymbol', 'matched_invoice_amount', 'matched_client_name', 'client_id'],
                  'purchase_invoice' => ['matched_purchase_invoice_id', 'matched_purchase_ref', 'matched_vendor_name']] as $type => $keys) {
            if (!self::canRead($request, $type)) {
                foreach ($keys as $key) if (array_key_exists($key, $row)) $row[$key] = null;
                $row[$type === 'invoice' ? 'matched_invoices' : 'matched_purchase_invoices'] = [];
            }
        }
        // Účetní kontace, mzdové vazby, držitelé karet a interní dimenze nejsou bankovní agenda klienta.
        foreach (['posting', 'card'] as $key) if (array_key_exists($key, $row)) $row[$key] = null;
        unset($row['dimensions'], $row['match_reason'], $row['matched_by'], $row['candidates'], $row['blockers'],
            $row['authoritative_transaction_id'], $row['authoritative_statement_id'], $row['authoritative_statement_ids']);
        // Porovnání s výpisem a důvody potvrzení patří do interní účetní kontroly.
        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, 'confirmation_')) unset($row[$key]);
        }
        return $row;
    }
}
