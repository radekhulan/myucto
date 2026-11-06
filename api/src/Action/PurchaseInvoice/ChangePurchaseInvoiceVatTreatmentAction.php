<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseVatTreatment;
use MyInvoice\Service\PurchaseInvoice\PurchaseVatTreatmentConflict;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Vat\PurchaseFiledVatPeriod;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/vat-treatment — oprava DPH zařazení přijaté faktury.
 *
 * Body (všechny klíče volitelné, mění se jen poslané):
 *   { vat_deduction, vat_deduction_percent, vat_classification_code,
 *     items: [{ id, vat_classification_code }],
 *     vat_allocations: [{ id, vat_deduction, vat_deduction_percent?, vat_classification_code? }],
 *     confirm_filed_period?: bool, force_mode?: 'reconcile' }
 *
 * Úzká akce místo vynucené úpravy celého dokladu: částky, data ani dodavatel se nemění,
 * jen nárok na odpočet a členění DPH. Smí ji role s právem
 * `purchase_invoices.vat_treatment` (výchozí účetní), i u přijatého, zaplaceného
 * a zaúčtovaného dokladu, bez storna.
 *
 * Pojistky ve stejném pořadí jako vynucená úprava ({@see UpdatePurchaseInvoiceAction}):
 *   - storno je neměnné;
 *   - uzavřené účetní období přebije jen správce s `?force=1` ({@see GuardsDocumentLock});
 *     zaúčtovaný doklad v něm, nebo k datu uzamčení účetnictví (`locked_until`), se opraví
 *     jen v režimu `force_mode=reconcile` (storno a nový zápis v běžném období);
 *   - zaúčtovaný doklad se přeúčtuje, takže je to účetní úkon jen z relace a neadmin
 *     k němu potřebuje i právo zaúčtovat;
 *   - změnu, která pohne evidencí DPH v podaném období, provede jen správce a jen
 *     s výslovným potvrzením `confirm_filed_period` (opravné / dodatečné tvrzení je na něm);
 *   - změna nároku v hlavičce u dokladu s rozpisem DPH jde přes týž SSOT jako editor
 *     ({@see PurchaseVatTreatment::syncAllocationsWithHeader()}).
 */
final class ChangePurchaseInvoiceVatTreatmentAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalSync $journalSync,
        private readonly VatLedgerService $ledger,
        private readonly PurchaseFiledVatPeriod $filedPeriods,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (!RequestAuthorization::canChangePurchaseVatTreatment($request)) {
            return Json::error($response, 'forbidden', 'K opravě DPH zařazení chybí oprávnění „Změnit DPH zařazení přijaté faktury“.', 403);
        }
        if (($existing['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze upravit. Nejdřív ho vraťte ze storna.', 409);
        }

        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        [$changes, $errors] = $this->parse($body, $existing);
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        if ($changes['header'] === [] && $changes['items'] === [] && $changes['allocations'] === []) {
            return Json::ok($response, $existing);
        }

        // Zaúčtovaný doklad v uzavřeném období nebo za datem uzamčení účetnictví nejde přepsat
        // na místě — jen stornem a novým zápisem v běžném období (táž volba jako vynucená úprava).
        $forceMode = null;
        if ($lock->posted && ($lock->inClosedPeriod || $lock->dateLocked)) {
            $forceMode = trim((string) ($request->getQueryParams()['force_mode'] ?? $body['force_mode'] ?? ''));
            if ($forceMode !== 'reconcile') {
                return Json::error(
                    $response,
                    'force_mode_required',
                    'Doklad je zaúčtovaný v uzavřeném období nebo k datu, ke kterému je účetnictví uzamčené. '
                        . 'Opravu DPH zařazení lze provést jen s force_mode="reconcile": původní zápis se stornuje '
                        . 'a doklad se přeúčtuje do aktuálního otevřeného období.',
                    422,
                );
            }
        }
        if ($lock->posted) {
            if ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Oprava DPH zařazení zaúčtovaného dokladu')) {
                return $deny;
            }
            if (!RequestAuthorization::canRepostPurchaseVatTreatment($request)) {
                return Json::error(
                    $response,
                    'journal_post_forbidden',
                    'Oprava DPH zařazení zaúčtovaného dokladu přeúčtuje deník. K tomu je potřeba i oprávnění „Zaúčtovat doklad“.',
                    403,
                );
            }
        }

        $claimDate = $this->filedPeriods->claimDate($supplierId, $id) ?? (string) ($existing['tax_date'] ?? $existing['issue_date']);
        $periodStart = substr($claimDate, 0, 7) . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $before = $this->ledger->documentRows($supplierId, 'purchase', $id, $periodStart, $periodEnd, true);
        $filed = null;
        $vatChanged = false;

        // Dopad na evidenci DPH se zjistí zápisem a porovnáním; odmítnutá změna se vrátí.
        // Uvnitř cizí transakce (volající ji drží otevřenou) jen po savepoint.
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        $savepoint = 'purchase_vat_treatment';
        $ownTransaction ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . $savepoint);
        $undo = static function () use ($pdo, $ownTransaction, $savepoint): void {
            if (!$pdo->inTransaction()) {
                return;
            }
            if ($ownTransaction) {
                $pdo->rollBack();
                return;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        };
        try {
            $this->apply($supplierId, $id, $changes);
            $after = $this->ledger->documentRows($supplierId, 'purchase', $id, $periodStart, $periodEnd, true);
            $vatChanged = self::rowsSignature($before) !== self::rowsSignature($after);
            if ($vatChanged && ($existing['status'] ?? '') !== 'draft'
                && ($filed = $this->filedPeriods->describe($supplierId, $id)) !== null) {
                if (!RequestAuthorization::canChangeFiledVatPeriod($request)) {
                    $undo();
                    return Json::error($response, 'period_filed', PurchaseFiledVatPeriod::message($filed), 409);
                }
                if (!filter_var($body['confirm_filed_period'] ?? false, FILTER_VALIDATE_BOOL)) {
                    $undo();
                    return Json::error(
                        $response,
                        'period_filed_confirm_required',
                        'Období odpočtu dokladu už bylo podáno (' . $filed . '). Změna DPH zařazení se promítne do '
                            . 'evidence DPH a bude ji potřeba vykázat opravným nebo dodatečným tvrzením. Potvrďte ji výslovně.',
                        409,
                        ['filed_period' => $filed, 'can_confirm' => true],
                    );
                }
            }
            $ownTransaction ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e)) {
                $undo();
            }
            throw $e;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $meta = [
            'user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
            'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent'),
        ];
        $reconcile = null;
        $repostedEntryId = null;
        $journalError = null;
        try {
            if ($forceMode === 'reconcile') {
                $reconcile = $this->journalSync->reconcileForceEdit($supplierId, 'purchase_invoice', $id, $meta);
            } elseif ($lock->posted) {
                $updated = $this->repo->find($id, $supplierId) ?? $existing;
                $repostedEntryId = $this->journalSync->repostForceEdit($supplierId, 'purchase_invoice', $id, $meta + [
                    'entry_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_no' => (string) ($updated['varsymbol'] ?? ''),
                ]);
            }
        } catch (PostingException | UnbalancedEntryException $e) {
            $journalError = $e;
        }

        $this->logger->log('purchase_invoice.vat_treatment_changed', $user['id'] ?? null, 'purchase_invoice', $id, [
            'before' => $changes['before'],
            'after' => $changes['after'],
            'vat_ledger_changed' => $vatChanged,

            'filed_period' => $filed,
            'force_mode' => $forceMode,
            'reposted_entry_id' => $repostedEntryId,
            'reconcile' => $reconcile,
            'journal_error' => $journalError?->getMessage(),
        ], $ip, $request->getHeaderLine('User-Agent'));

        if ($journalError !== null) {
            $code = $journalError instanceof PostingException ? $journalError->errorCode : 'unbalanced_entry';
            $status = $journalError instanceof PostingException ? $journalError->httpStatus : 422;
            return Json::error($response, $code,
                'DPH zařazení je uložené, ale přeúčtování deníku selhalo: ' . $journalError->getMessage() . ' Deník dorovnejte ručně.',
                $status);
        }

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $repostedEntryId !== null) {
            $invoice['_repost'] = ['entry_id' => $repostedEntryId];
        }
        if ($invoice !== null && $reconcile !== null) {
            $invoice['_reconcile'] = $reconcile;
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Rozdíl požadavku proti uloženému dokladu. Hlavička: jen skutečně změněné klíče.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $existing
     * @return array{0: array{header: array<string,mixed>, items: array<int,string>, allocations: array<int, array<string,mixed>>,
     *                        before: array<string,mixed>, after: array<string,mixed>}, 1: array<string,string>}
     */
    private function parse(array $body, array $existing): array
    {
        $errors = [];
        $header = [];
        $isPurchaseCode = fn (string $code): bool => $this->isPurchaseClassification((int) $existing['supplier_id'], $code);

        $oldMode = (string) ($existing['vat_deduction'] ?? 'full');
        $oldPercent = (float) ($existing['vat_deduction_percent'] ?? 100);
        $newMode = $oldMode;
        $newPercent = $oldPercent;
        if (array_key_exists('vat_deduction', $body)) {
            $mode = $body['vat_deduction'];
            if (!is_string($mode) || !in_array($mode, PurchaseVatTreatment::MODES, true)) {
                $errors['vat_deduction'] = 'Neznámý režim odpočtu.';
            } else {
                $newMode = $mode;
            }
        }
        if ($newMode === 'proportional') {
            $raw = $body['vat_deduction_percent'] ?? ($oldMode === 'proportional' ? $oldPercent : null);
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                $errors['vat_deduction_percent'] = 'Poměrný odpočet potřebuje procento 0 až 100.';
            } else {
                $newPercent = round((float) $raw, 2);
            }
        } elseif ($newMode !== $oldMode) {
            $newPercent = $newMode === 'none' ? 0.0 : 100.0;
        }
        if (PurchaseVatTreatment::deductionKey($newMode, $newPercent) !== PurchaseVatTreatment::deductionKey($oldMode, $oldPercent)) {
            $header['vat_deduction'] = $newMode;
            $header['vat_deduction_percent'] = $newPercent;
        }

        if (array_key_exists('vat_classification_code', $body)) {
            $code = $body['vat_classification_code'];
            $code = is_string($code) ? (trim($code) ?: null) : $code;
            if ($code !== null && (!is_string($code) || !$isPurchaseCode($code))) {
                $errors['vat_classification_code'] = 'Neznámé členění DPH přijatého plnění.';
            } elseif ($code !== (($existing['vat_classification_code'] ?? null) ?: null)) {
                $header['vat_classification_code'] = $code;
            }
        }

        $items = [];
        $storedItems = [];
        foreach ((array) ($existing['items'] ?? []) as $it) {
            $storedItems[(int) $it['id']] = ($it['vat_classification_code'] ?? null) ?: null;
        }
        if (array_key_exists('items', $body)) {
            if (!is_array($body['items'])) {
                $errors['items'] = 'Očekává se pole {id, vat_classification_code}.';
            } else {
                foreach ($body['items'] as $row) {
                    $itemId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                    $code = is_array($row) && is_string($row['vat_classification_code'] ?? null) ? trim($row['vat_classification_code']) : '';
                    if (!array_key_exists($itemId, $storedItems)) {
                        $errors['items'] = 'Položka nepatří k tomuto dokladu.';
                        break;
                    }
                    if ($code === '' || !$isPurchaseCode($code)) {
                        $errors['items'] = "Členění DPH položky musí být kód z číselníku přijatých plnění firmy.";
                        break;
                    }
                    if ($code !== $storedItems[$itemId]) {
                        $items[$itemId] = $code;
                    }
                }
            }
        }

        $storedAllocations = array_values((array) ($existing['vat_allocations'] ?? []));
        $byId = [];
        foreach ($storedAllocations as $i => $a) {
            $byId[(int) $a['id']] = $i;
        }
        $sentAllocations = null;
        if (array_key_exists('vat_allocations', $body)) {
            if (!is_array($body['vat_allocations'])) {
                $errors['vat_allocations'] = 'Očekává se pole {id, vat_deduction}.';
            } else {
                $sentAllocations = $storedAllocations;
                foreach ($body['vat_allocations'] as $row) {
                    $allocationId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                    if (!isset($byId[$allocationId])) {
                        $errors['vat_allocations'] = 'Část rozpisu nepatří k tomuto dokladu.';
                        break;
                    }
                    $target = &$sentAllocations[$byId[$allocationId]];
                    if (array_key_exists('vat_deduction', $row)) {
                        $mode = $row['vat_deduction'];
                        if (!is_string($mode) || !in_array($mode, PurchaseVatTreatment::MODES, true)) {
                            $errors['vat_allocations'] = 'Neznámý režim odpočtu v rozpisu.';
                            break;
                        }
                        $target['vat_deduction'] = $mode;
                    }
                    if ($target['vat_deduction'] === 'proportional') {
                        $raw = $row['vat_deduction_percent'] ?? $target['vat_deduction_percent'];
                        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                            $errors['vat_allocations'] = 'Poměrný odpočet v rozpisu potřebuje procento 0 až 100.';
                            break;
                        }
                        $target['vat_deduction_percent'] = round((float) $raw, 2);
                    } else {
                        $target['vat_deduction_percent'] = $target['vat_deduction'] === 'none' ? 0.0 : 100.0;
                    }
                    if (array_key_exists('vat_classification_code', $row)) {
                        $code = is_string($row['vat_classification_code']) ? (trim($row['vat_classification_code']) ?: null) : null;
                        if ($code !== null && !$isPurchaseCode($code)) {
                            $errors['vat_allocations'] = 'Neznámé členění DPH v rozpisu.';
                            break;
                        }
                        $target['vat_classification_code'] = $code;
                    }
                    $usage = (string) ($target['usage_type'] ?? 'business');
                    if (($usage === 'personal' && $target['vat_deduction'] !== 'none')
                        || ($usage === 'mixed' && $target['vat_deduction'] !== 'proportional')) {
                        $errors['vat_allocations'] = 'Osobní část rozpisu musí být bez nároku a smíšená s poměrným odpočtem.';
                        break;
                    }
                    unset($target);
                }
                unset($target);
            }
        }

        $allocations = [];
        if ($errors === []) {
            try {
                $synced = PurchaseVatTreatment::syncAllocationsWithHeader(
                    ['vat_deduction' => $oldMode, 'vat_deduction_percent' => $oldPercent],
                    ['vat_deduction' => $newMode, 'vat_deduction_percent' => $newPercent],
                    $storedAllocations,
                    $sentAllocations,
                );
            } catch (PurchaseVatTreatmentConflict $e) {
                $errors['vat_allocations'] = $e->getMessage();
                $synced = null;
            }
            foreach ($synced ?? $sentAllocations ?? [] as $i => $row) {
                $old = $storedAllocations[$i];
                if (PurchaseVatTreatment::deductionKey($row['vat_deduction'], $row['vat_deduction_percent'])
                        !== PurchaseVatTreatment::deductionKey($old['vat_deduction'], $old['vat_deduction_percent'])
                    || (($row['vat_classification_code'] ?? null) ?: null) !== (($old['vat_classification_code'] ?? null) ?: null)) {
                    $allocations[(int) $old['id']] = [
                        'vat_deduction' => (string) $row['vat_deduction'],
                        'vat_deduction_percent' => (float) $row['vat_deduction_percent'],
                        'vat_classification_code' => ($row['vat_classification_code'] ?? null) ?: null,
                    ];
                }
            }
        }

        $before = [
            'vat_deduction' => $oldMode, 'vat_deduction_percent' => $oldPercent,
            'vat_classification_code' => ($existing['vat_classification_code'] ?? null) ?: null,
            'items' => array_intersect_key($storedItems, $items),
            'vat_allocations' => array_map(static fn (array $a): array => [
                'vat_deduction' => $a['vat_deduction'], 'vat_deduction_percent' => (float) $a['vat_deduction_percent'],
                'vat_classification_code' => ($a['vat_classification_code'] ?? null) ?: null,
            ], array_intersect_key(array_column($storedAllocations, null, 'id'), $allocations)),
        ];
        $after = $header + ['items' => $items, 'vat_allocations' => $allocations];

        return [[
            'header' => $header, 'items' => $items, 'allocations' => $allocations,
            'before' => $before, 'after' => $after,
        ], $errors];
    }

    /** @param array{header: array<string,mixed>, items: array<int,string>, allocations: array<int, array<string,mixed>>} $changes */
    private function apply(int $supplierId, int $id, array $changes): void
    {
        $pdo = $this->db->pdo();
        if ($changes['header'] !== []) {
            $sets = implode(', ', array_map(static fn (string $col): string => "{$col} = ?", array_keys($changes['header'])));
            $pdo->prepare("UPDATE purchase_invoices SET {$sets} WHERE id = ? AND supplier_id = ?")
                ->execute([...array_values($changes['header']), $id, $supplierId]);
        }
        $itemStmt = $pdo->prepare('UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ? AND purchase_invoice_id = ?');
        foreach ($changes['items'] as $itemId => $code) {
            $itemStmt->execute([$code, $itemId, $id]);
        }
        $allocationStmt = $pdo->prepare(
            'UPDATE purchase_invoice_vat_allocations
                SET vat_deduction = ?, vat_deduction_percent = ?, vat_classification_code = ?
              WHERE id = ? AND purchase_invoice_id = ? AND supplier_id = ?'
        );
        foreach ($changes['allocations'] as $allocationId => $row) {
            $allocationStmt->execute([
                $row['vat_deduction'], $row['vat_deduction_percent'], $row['vat_classification_code'],
                $allocationId, $id, $supplierId,
            ]);
        }
        // Prefix interního čísla nese daňový typ (PF/NU/KU …) — stejně jako vynucená úprava.
        $this->repo->reprefixVarsymbol($id, $supplierId);
        $pdo->prepare('UPDATE purchase_invoices SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    private function isPurchaseClassification(int $supplierId, string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM vat_classifications
              WHERE code = ? AND archived = 0 AND direction IN ('purchase','both')
                AND (supplier_id IS NULL OR supplier_id = ?)
              LIMIT 1"
        );
        $stmt->execute([$code, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function rowsSignature(array $rows): string
    {
        return (string) json_encode($rows, JSON_PRESERVE_ZERO_FRACTION);
    }
}
