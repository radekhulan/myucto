<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\ExtractionReviewSync;
use MyInvoice\Service\Vat\PurchaseFiledVatPeriod;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}/expense-kinds — druh nákladu po položkách.
 *
 * Body: { items: [{ id: number, expense_kind?: string|null, expense_account_code?: string|null,
 *                  vat_classification_code?: string }] }
 *
 * `vat_classification_code` mění členění DPH položky (issue #150): AI určí povahu plnění
 * jednou za celý doklad, smíšený doklad se zbožím i službou se opravuje tady. Prvek, který
 * mění jen členění, nemusí nést `expense_kind`.
 *
 * Kontrolní okno po AI importu potvrzuje návrhy druhu nákladu faktura po faktuře.
 * Plný PUT by musel poslat celý doklad (a u zaplaceného dokladu z importu je navíc
 * dostupný jen přes vynucenou úpravu), proto úzký endpoint jen na tuhle klasifikaci.
 *
 * Pojistky jsou TYTÉŽ jako u vynucené úpravy ({@see UpdatePurchaseInvoiceAction}):
 *   - zámek dokladu (uzavřené období, podané přiznání) se kontroluje první;
 *   - smí každý, kdo smí upravovat přijaté faktury, v jakémkoli stavu kromě storna
 *     (druh nákladu je klasifikace, ne částka); klient z portálu jen u konceptu;
 *   - storno je neměnné;
 *   - zaúčtovaný doklad v otevřeném období se přeúčtuje, v uzavřeném se odmítne
 *     (oprava tam vyžaduje reconcile v editoru);
 *   - evidence drobného majetku se srovná s novou klasifikací (mimo koncept).
 *
 * Volba je ruční rozhodnutí účetní: provenience automatu (pravidlo, zdroj) se smaže a účet,
 * který na řádek dosadil automat, s ní — jinak by starý účet přebil nově zvolený druh.
 * Účet zvolený ručně zůstává, ten je silnější než druh (viz PostingService).
 */
final class SetPurchaseInvoiceExpenseKindsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalSync $journalSync,
        private readonly SmallAssetService $smallAssets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExtractionReviewSync $reviewSync,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly PurchaseFiledVatPeriod $filedPeriods,
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

        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
            return $deny;
        }

        $status = (string) ($existing['status'] ?? '');
        if ($status === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze upravit.', 409);
        }
        // Druh nákladu je klasifikace, ne částka: účetní ho musí umět doplnit i u dokladu,
        // který import rovnou označil jako zaplacený. Klient (portál) jen u konceptu.
        if ($status !== 'draft' && RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'not_editable',
                "Druh nákladu u dokladu ve stavu '{$status}' doplní vaše účetní.", 409);
        }
        if ($lock->posted && $lock->inClosedPeriod) {
            return Json::error($response, 'posted_in_closed_period',
                'Doklad je zaúčtovaný v uzavřeném období. Druh nákladu opravte v editoru (vynucená úprava s dorovnáním).',
                409);
        }
        // Zaúčtovaný doklad se níž přeúčtuje; nezaúčtovaný (kontrola po importu) token klasifikovat smí.
        if ($lock->posted
            && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Změna druhu nákladu u zaúčtovaného dokladu'))) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $requested = self::parseItems($body['items'] ?? null);
        if ($requested === null) {
            return Json::error($response, 'validation_failed', 'Neplatný seznam položek.', 400, [
                'fields' => ['items' => 'Očekává se pole {id, expense_kind} s platným druhem nákladu.'],
            ]);
        }

        $current = [];
        foreach ((array) ($existing['items'] ?? []) as $item) {
            $current[(int) $item['id']] = $item;
        }
        $sources = $this->db->pdo()->prepare(
            'SELECT id, expense_classification_source FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $sources->execute([$id]);
        foreach ($sources->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            if (isset($current[(int) $item['id']])) {
                $current[(int) $item['id']]['expense_classification_source'] = $item['expense_classification_source'];
            }
        }
        $changes = [];
        $vatChanges = [];
        foreach ($requested as $itemId => $classification) {
            if (!isset($current[$itemId])) {
                return Json::error($response, 'invalid_reference', 'Položka nepatří k tomuto dokladu.', 400);
            }
            if (array_key_exists('vat_classification_code', $classification)) {
                $vatTo = $classification['vat_classification_code'];
                if (!$this->isPurchaseClassification($supplierId, $vatTo)) {
                    return Json::error($response, 'validation_failed', 'Neznámé členění DPH přijatého plnění.', 400, [
                        'fields' => ['items' => "Členění DPH '{$vatTo}' není v číselníku přijatých plnění firmy."],
                    ]);
                }
                $vatFrom = ($current[$itemId]['vat_classification_code'] ?? null) ?: null;
                if ($vatFrom !== $vatTo) {
                    $vatChanges[$itemId] = ['from' => $vatFrom, 'to' => $vatTo];
                }
            }
            if (!array_key_exists('expense_kind', $classification)) {
                continue;
            }
            $from = ($current[$itemId]['expense_kind'] ?? null) ?: null;
            $kind = $classification['expense_kind'];
            $accountFrom = ($current[$itemId]['expense_account_code'] ?? null) ?: null;
            $explicitAccount = array_key_exists('expense_account_code', $classification);
            $account = $explicitAccount ? $classification['expense_account_code']
                : (($current[$itemId]['expense_classification_source'] ?? null) === null || $from === $kind ? $accountFrom : null);
            if ($explicitAccount && $account !== null) {
                // Stejné omezení jako pravidla; výběr konkrétní analytiky až při účtování zůstává v PostingService.
                $record = $this->accounts->findByCode($supplierId, $account);
                if (\MyInvoice\Service\Accounting\SettlementAccounts::isSettlement((string) $account)
                    || $record === null || !($record['is_active'] ?? false)) {
                    return Json::error($response, 'invalid_target_account', 'Účet není aktivním nákladovým účtem firmy.', 400);
                }
            }
            if ($from !== $kind || $accountFrom !== $account
                || ($explicitAccount && ($current[$itemId]['expense_classification_source'] ?? null) !== null)) {
                $changes[$itemId] = ['from' => $from, 'to' => $kind, 'account_from' => $accountFrom, 'account_to' => $account];
            }
        }
        if ($changes === [] && $vatChanges === []) {
            $this->reviewSync->afterItemsChanged($supplierId, $id);
            return Json::ok($response, $this->repo->find($id, $supplierId) ?? $existing);
        }
        // Členění DPH položky mění evidenci DPH. V podaném období ho delegovaná role nezmění,
        // stejně jako vynucenou úpravou nebo opravou DPH zařazení ({@see PurchaseFiledVatPeriod}).
        if ($vatChanges !== [] && $status !== 'draft'
            && !RequestAuthorization::canChangeFiledVatPeriod($request)
            && ($filed = $this->filedPeriods->describe($supplierId, $id)) !== null) {
            return Json::error($response, 'period_filed', PurchaseFiledVatPeriod::message($filed), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE purchase_invoice_items
                    SET expense_kind = ?,
                        is_fixed_asset = ?,
                        expense_account_code = ?,
                        expense_rule_id = NULL,
                        expense_classification_source = NULL
                  WHERE id = ? AND purchase_invoice_id = ?'
            );
            foreach ($changes as $itemId => $change) {
                $stmt->execute([$change['to'], $change['to'] === ExpenseKind::FixedAsset->value ? 1 : 0,
                    $change['account_to'], $itemId, $id]);
            }
            // Členění DPH je samostatná volba: provenienci druhu nákladu nemaže.
            $vatStmt = $pdo->prepare(
                'UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ? AND purchase_invoice_id = ?'
            );
            foreach ($vatChanges as $itemId => $change) {
                $vatStmt->execute([$change['to'], $itemId, $id]);
            }
            // Odrážky hlášení u řádků, které teď druh mají, zmizí (týž háček jako replaceItems).
            $this->reviewSync->afterItemsChanged($supplierId, $id);
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $repostedEntryId = null;
        if ($lock->posted) {
            $updated = $this->repo->find($id, $supplierId) ?? $existing;
            try {
                $repostedEntryId = $this->journalSync->repostForceEdit($supplierId, 'purchase_invoice', $id, [
                    'entry_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_no' => (string) ($updated['varsymbol'] ?? ''),
                    'user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
                    'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $httpStatus = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error($response, $code,
                    'Druh nákladu je uložený, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně.',
                    $httpStatus);
            }
        }

        if ($status !== 'draft') {
            try {
                $this->smallAssets->syncFromPurchaseInvoice($supplierId, $id, $user['id'] ?? null);
            } catch (\Throwable $e) {
                $this->logger->log('purchase_invoice.small_asset_sync_failed', $user['id'] ?? null,
                    'purchase_invoice', $id, ['error' => $e->getMessage()],
                    $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        $this->logger->log('purchase_invoice.expense_kinds_set', $user['id'] ?? null, 'purchase_invoice', $id, [
            'changes'          => $changes,
            'vat_changes'      => $vatChanges,
            'reposted_entry_id' => $repostedEntryId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $repostedEntryId !== null) {
            $invoice['_repost'] = ['entry_id' => $repostedEntryId];
        }
        return Json::ok($response, $invoice);
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

    /** @return array<int, array{expense_kind?:?string, expense_account_code?:?string, vat_classification_code?:string}>|null */
    private static function parseItems(mixed $items): ?array
    {
        if (!is_array($items) || $items === []) {
            return null;
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || (int) $item['id'] <= 0) {
                return null;
            }
            // Členění DPH položky (issue #150) je volitelné; vymazat ho nejde, výkazy ho potřebují.
            $vat = null;
            if (array_key_exists('vat_classification_code', $item)) {
                $vat = is_string($item['vat_classification_code']) ? trim($item['vat_classification_code']) : '';
                if ($vat === '') {
                    return null;
                }
            }
            // Bez klíče expense_kind by se druh tiše smazal — vymazání je explicitní null.
            // Prvek, který mění jen členění DPH, druh nákladu nese nemusí.
            if (!array_key_exists('expense_kind', $item)) {
                if ($vat === null) {
                    return null;
                }
                $out[(int) $item['id']] = ['vat_classification_code' => $vat];
                continue;
            }
            $raw = $item['expense_kind'] ?? null;
            $kind = ($raw === null || $raw === '') ? null : ExpenseKind::tryFrom((string) $raw);
            if ($raw !== null && $raw !== '' && $kind === null) {
                return null;
            }
            $classification = ['expense_kind' => $kind?->value];
            if (array_key_exists('expense_account_code', $item)) {
                $account = $item['expense_account_code'];
                if ($account !== null && !is_string($account)) {
                    return null;
                }
                $classification['expense_account_code'] = $account === null ? null : (trim($account) ?: null);
            }
            if ($vat !== null) {
                $classification['vat_classification_code'] = $vat;
            }
            $out[(int) $item['id']] = $classification;
        }
        return $out;
    }
}
