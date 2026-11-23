<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TaxNeutralReclassification;
use MyInvoice\Service\Ai\AiPostingOverrideResolver;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use MyInvoice\Service\Vat\PurchaseFiledVatPeriod;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadná změna předkontace (`posting_rule_key`) na položkách vydaných a přijatých
 * faktur.
 *
 * Předkontace řádku doplňuje jen účty, které položka sama nemá ({@see \MyInvoice\Service\Accounting\ItemPostingAccounts}).
 * Aby nová předkontace opravdu platila, položka přijde o vlastní účet té strany, kterou
 * předkontace určuje (účet nákladu nebo výnosu, protiúčet). Strana, kterou předkontace
 * neurčuje, zůstane.
 *
 * Zaúčtovaný doklad se pak zaúčtuje znovu z dokladu toutéž cestou jako tlačítko
 * Přeúčtovat ({@see DocumentRepostService}): zápis se přepíše na místě, v zamčeném nebo
 * uzavřeném období se podle zvoleného způsobu přeskočí, nebo stornuje a zaúčtuje znovu.
 * Změna položek a přeúčtování běží v jedné transakci. Nezaúčtovaný doklad dostane jen
 * novou předkontaci položek.
 *
 * Podané období DPH (přiznání, kontrolní hlášení) se posuzuje výslovně
 * ({@see FiledVatPeriodLookup}, u přijaté faktury období odpočtu přes {@see PurchaseFiledVatPeriod}):
 * zápis v něm se přepíše na místě jen jako daňově neutrální přesun mezi účty, tedy
 * stejně, jako ho chrání zámek k datu, který podání posouvá, a jak to dělá přeúčtování
 * účtu (Přeúčtovat). Kdyby správce zámek posunul zpět přes podané období, přepis
 * s daňovým dopadem se přeskočí s důvodem `period_filed`.
 *
 * Vrácení obnoví předchozí předkontaci a účty položek a doklad znovu přeúčtuje.
 *
 * Stejným průběhem jde přeúčtování vybraných položek na jiný účet
 * ({@see ItemAccountOperation}, parametr `to_account`): položka dostane vlastní účet
 * a zaúčtovaný doklad se přeúčtuje.
 */
final class SetPostingRuleOperation implements BulkOperation
{
    public const KEY = 'set_posting_rule';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $rules,
        private readonly AccountingModeRepository $modes,
        private readonly DocumentRepostService $repost,
        private readonly PostingService $posting,
        private readonly DocumentLockService $locks,
        private readonly AiPostingOverrideResolver $aiOverrides,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly PurchaseFiledVatPeriod $purchaseFiledPeriods,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canBulkChange($request)) {
            return 'Hromadné změny smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.';
        }
        if ($target === 'journal_entry') {
            return 'Předkontaci jde měnit jen u vydaných a přijatých faktur.';
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return 'Firma nevede podvojné účetnictví.';
        }
        $module = $target === 'invoice' ? 'invoices' : 'purchase_invoices';
        if (!RequestAuthorization::allows($request, $module, AccessLevel::WRITE)) {
            return 'Chybí oprávnění upravovat doklady.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $key = trim((string) ($raw['rule_key'] ?? ''));
        if ($key === '' || preg_match('/^[a-z0-9_.\-]{1,64}$/D', $key) !== 1) {
            throw new BulkActionException('validation_failed', 'Zvolte předkontaci.', 422);
        }
        if ($this->rules->resolve($supplierId, $key) === null) {
            throw new BulkActionException('invalid_posting_rule', 'Předkontace „' . $key . '" ve firmě neexistuje.', 422);
        }
        $mode = (string) ($raw['mode'] ?? JournalRebookService::MODE_REWRITE);
        if (!in_array($mode, [JournalRebookService::MODE_REWRITE, JournalRebookService::MODE_REWRITE_OR_REVERSE], true)) {
            throw new BulkActionException('validation_failed', 'Neplatný způsob přeúčtování.', 422);
        }
        return ['rule_key' => $key, 'mode' => $mode];
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        $type = (string) $row['source_type'];
        $docId = (int) $row['source_id'];
        $blocked = $this->documentBlocker($ctx, $type, $docId);
        if ($blocked !== null) {
            return ['status' => 'skipped', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        try {
            return $this->transaction(function () use ($ctx, $type, $docId, $item): array {
                $state = $this->state($ctx->supplierId, $type, $docId);
                $item['before'] = ['items' => $state['items'], 'lines' => $state['lines']];
                $newItems = $this->changedItems($ctx, $type, $state['items']);
                if ($newItems === $state['items']) {
                    return ['status' => 'unchanged'] + $item;
                }
                $this->writeItems($type, $docId, $newItems);
                $after = ['items' => $newItems, 'lines' => $state['lines']];
                if ($state['plan'] !== null) {
                    $this->assertMayRepost($ctx, $type);
                    $lines = $this->build($ctx->supplierId, $type, $docId);
                    $after['lines'] = JournalRebookService::normalizeLines($lines);
                    $preview = $this->repost->previewPlan($ctx->supplierId, $type, $docId, $lines);
                    $item += ['strategy' => (string) $preview['strategy'], 'target_date' => $preview['target_date'],
                        'date_shifted' => (bool) $preview['date_shifted'], 'reason_code' => $preview['reason_code']];
                    $item['after'] = $after;
                    if ($preview['strategy'] === DocumentRepostService::STRATEGY_BLOCKED) {
                        return ['status' => 'skipped', 'strategy' => null] + $item;
                    }
                    if (($filed = $this->filedRewriteBlocker($ctx->supplierId, $type, $docId, $preview)) !== null) {
                        return ['status' => 'skipped', 'strategy' => null, 'reason_code' => 'period_filed', 'message' => $filed] + $item;
                    }
                    if ($preview['strategy'] === DocumentRepostService::STRATEGY_REVERSE && $ctx->params['mode'] === JournalRebookService::MODE_REWRITE) {
                        return ['status' => 'skipped', 'strategy' => null, 'reason_code' => $preview['reason_code'] ?? 'needs_reverse'] + $item;
                    }
                }
                $item['after'] = $after;
                return ['status' => 'would_change', 'fingerprint' => $state['fingerprint']] + $item;
            }, true);
        } catch (PostingException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        $type = (string) $dry['source_type'];
        $docId = (int) $dry['source_id'];
        $blocked = $this->documentBlocker($ctx, $type, $docId);
        if ($blocked !== null) {
            return ['status' => 'skipped', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        try {
            return $this->transaction(function () use ($ctx, $type, $docId, $dry, $item): array {
                $state = $this->state($ctx->supplierId, $type, $docId);
                if ($state['fingerprint'] !== $dry['fingerprint']) {
                    return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
                }
                $newItems = $this->changedItems($ctx, $type, $state['items']);
                return $this->write($ctx, $type, $docId, $state, $newItems, (string) $ctx->params['mode'], $item);
            });
        } catch (PostingException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        $type = (string) $applied['source_type'];
        $docId = (int) $applied['source_id'];
        $blocked = $this->documentBlocker($ctx, $type, $docId);
        if ($blocked !== null) {
            return ['status' => 'failed', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        try {
            return $this->transaction(function () use ($ctx, $type, $docId, $applied, $item): array {
                $state = $this->state($ctx->supplierId, $type, $docId);
                if ($state['fingerprint'] !== $applied['fingerprint']) {
                    return ['status' => 'failed', 'reason_code' => 'changed_after_apply'] + $item;
                }
                $previous = array_values((array) (($applied['before'] ?? [])['items'] ?? []));
                $byId = [];
                foreach ($previous as $row) $byId[(int) $row['item_id']] = $row;
                $restored = array_map(static fn (array $row): array => isset($byId[$row['item_id']])
                    ? array_intersect_key($byId[$row['item_id']], $row) + $row : $row, $state['items']);
                $mode = (string) ($ctx->params['mode'] ?? JournalRebookService::MODE_REWRITE);
                $result = $this->write($ctx, $type, $docId, $state, $restored, $mode, $item);
                return $result['status'] === 'applied' ? $result : ['status' => 'failed'] + $result;
            });
        } catch (PostingException $e) {
            return ['status' => 'failed', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * Zapíše položky a zaúčtovaný doklad přeúčtuje (v transakci volajícího).
     *
     * @param array<string,mixed> $state
     * @param list<array<string,mixed>> $newItems
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function write(BulkContext $ctx, string $type, int $docId, array $state, array $newItems, string $mode, array $item): array
    {
        $this->writeItems($type, $docId, $newItems);
        $result = ['strategy' => null, 'entry_date' => null, 'date_shifted' => false, 'entry_id' => null, 'items_synced' => 0];
        if ($state['plan'] !== null) {
            $this->assertMayRepost($ctx, $type);
            $meta = ['user_id' => $ctx->userId ?: null, 'posted_by' => $ctx->userId ?: null, 'bulk_rebook_job_id' => $ctx->jobId];
            if ($mode === JournalRebookService::MODE_REWRITE) {
                $meta['require_strategy'] = DocumentRepostService::STRATEGY_REPLACE;
            }
            if (($state['plan']['posting_entries'] ?? []) !== []) {
                $meta['expected_posting_entries'] = $state['plan']['posting_entries'];
            }
            $lines = $this->build($ctx->supplierId, $type, $docId);
            $filed = $this->filedRewriteBlocker($ctx->supplierId, $type, $docId, $this->repost->previewPlan($ctx->supplierId, $type, $docId, $lines));
            if ($filed !== null) {
                throw new PostingException('period_filed', $filed, 409);
            }
            $result = $this->repost->repost($ctx->supplierId, $type, $docId, $lines, $meta,
                $mode === JournalRebookService::MODE_REWRITE_OR_REVERSE);
        }
        $now = $this->state($ctx->supplierId, $type, $docId);
        return [
            'status'          => 'applied',
            'strategy'        => $result['strategy'],
            'target_date'     => $result['entry_date'],
            'date_shifted'    => (bool) $result['date_shifted'],
            'reason_code'     => null,
            'message'         => null,
            'after'           => ['items' => $now['items'], 'lines' => $now['lines']],
            'fingerprint'     => $now['fingerprint'],
            'result_entry_id' => $result['entry_id'],
            'items_synced'    => (int) $result['items_synced'],
        ] + $item;
    }

    /**
     * Položky po změně: nová předkontace (operace nad doklady), nebo vlastní účet vybraných
     * položek (přeúčtování položek z agendy Položky dokladů, {@see ItemAccountOperation}).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function changedItems(BulkContext $ctx, string $type, array $items): array
    {
        if (!isset($ctx->params['to_account'])) {
            return $this->withRule($ctx->supplierId, $type, $items, (string) $ctx->params['rule_key']);
        }
        $selected = array_flip(array_map('intval', (array) ($ctx->params['ids'] ?? [])));
        $to = (string) $ctx->params['to_account'];
        $from = (string) ($ctx->params['from_account'] ?? '');
        return array_map(static function (array $row) use ($selected, $to, $from): array {
            if ($row['standard'] && isset($selected[$row['item_id']])
                && ($from === '' || str_starts_with((string) $row['account_code'], $from))) {
                $row['account_code'] = $to;
            }
            return $row;
        }, $items);
    }

    /**
     * Položky dokladu po změně předkontace: nová předkontace a bez vlastního účtu té
     * strany, kterou předkontace určuje. Jen běžné položky (ne sleva ani text).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withRule(int $supplierId, string $type, array $items, string $ruleKey): array
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        if ($rule === null) {
            throw new PostingException('invalid_posting_rule', 'Předkontace „' . $ruleKey . '" ve firmě neexistuje.', 422);
        }
        $purchase = $type === 'purchase_invoice';
        $itemSide = trim((string) ($purchase ? $rule['debit_account_code'] : $rule['credit_account_code']));
        $counterSide = trim((string) ($purchase ? $rule['credit_account_code'] : $rule['debit_account_code']));
        return array_map(static function (array $row) use ($ruleKey, $itemSide, $counterSide): array {
            if (!$row['standard']) {
                return $row;
            }
            $row['posting_rule_key'] = $ruleKey;
            if ($itemSide !== '') $row['account_code'] = null;
            if ($counterSide !== '') $row['counter_account_code'] = null;
            return $row;
        }, $items);
    }

    /**
     * Stav dokladu: položky (předkontace, účty), zápis v deníku (NULL = nezaúčtovaný)
     * a otisk obojího.
     *
     * @return array{items:list<array<string,mixed>>, plan:?array<string,mixed>, lines:list<array<string,mixed>>, fingerprint:string}
     */
    private function state(int $supplierId, string $type, int $docId): array
    {
        $pdo = $this->db->pdo();
        $sql = $type === 'invoice'
            ? 'SELECT ii.id, ii.description, ii.item_kind, ii.revenue_account_code AS account_code, ii.counter_account_code, ii.posting_rule_key
                 FROM invoice_items ii JOIN invoices d ON d.id = ii.invoice_id AND d.supplier_id = ?
                WHERE ii.invoice_id = ? ORDER BY ii.order_index, ii.id FOR UPDATE'
            : 'SELECT ii.id, ii.description, NULL AS item_kind, ii.expense_account_code AS account_code, ii.counter_account_code, ii.posting_rule_key
                 FROM purchase_invoice_items ii JOIN purchase_invoices d ON d.id = ii.purchase_invoice_id AND d.supplier_id = ?
                WHERE ii.purchase_invoice_id = ? ORDER BY ii.order_index, ii.id FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $docId]);
        $items = array_map(static fn (array $r): array => [
            'item_id' => (int) $r['id'],
            'description' => $r['description'] === null ? null : mb_substr((string) $r['description'], 0, 120),
            'standard' => InvoiceItemKind::of($r) === InvoiceItemKind::STANDARD,
            'posting_rule_key' => $r['posting_rule_key'] === null || $r['posting_rule_key'] === '' ? null : (string) $r['posting_rule_key'],
            'account_code' => $r['account_code'] === null || $r['account_code'] === '' ? null : (string) $r['account_code'],
            'counter_account_code' => $r['counter_account_code'] === null || $r['counter_account_code'] === '' ? null : (string) $r['counter_account_code'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $plan = null;
        try {
            $plan = $this->repost->plan($supplierId, $type, $docId);
        } catch (PostingException $e) {
            if ($e->errorCode !== 'entry_not_found') throw $e;
        }
        $lines = $plan === null ? [] : JournalRebookService::normalizeLines($plan['lines']);
        $entries = [];
        if ($plan !== null) {
            $entries = array_map(static fn (array $m): array => [(int) $m['id'], (int) $m['row_version']], $plan['posting_entries'] ?? []);
            if ($entries === []) {
                $version = $pdo->prepare('SELECT row_version FROM journal_entries WHERE id = ? AND supplier_id = ?');
                $version->execute([(int) $plan['entry_id'], $supplierId]);
                $entries = [[(int) $plan['entry_id'], (int) $version->fetchColumn()]];
            }
            sort($entries);
        }
        return [
            'items' => $items,
            'plan' => $plan,
            'lines' => $lines,
            'fingerprint' => hash('sha256', json_encode([$items, $entries, $lines], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function writeItems(string $type, int $docId, array $items): void
    {
        $sql = $type === 'invoice'
            ? 'UPDATE invoice_items SET posting_rule_key = ?, revenue_account_code = ?, counter_account_code = ? WHERE id = ? AND invoice_id = ?'
            : 'UPDATE purchase_invoice_items SET posting_rule_key = ?, expense_account_code = ?, counter_account_code = ? WHERE id = ? AND purchase_invoice_id = ?';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($items as $row) {
            $stmt->execute([$row['posting_rule_key'], $row['account_code'], $row['counter_account_code'], $row['item_id'], $docId]);
        }
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function build(int $supplierId, string $type, int $docId): array
    {
        return $type === 'invoice'
            ? $this->posting->buildFromInvoice($supplierId, $docId)
            : $this->posting->buildFromPurchaseInvoice($supplierId, $docId, array_filter([
                'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId),
            ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Doklad, na který úprava předkontace nesmí: stornovaný, cizí, bez práva upravit
     * vystavený doklad, nebo nezaúčtovaný v uzavřeném období.
     *
     * @return array{0:string, 1:string}|null
     */
    private function documentBlocker(BulkContext $ctx, string $type, int $docId): ?array
    {
        $request = $ctx->request();
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$docId, $ctx->supplierId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc === false) {
            return ['not_found', 'Doklad nenalezen.'];
        }
        if ($doc['status'] === 'cancelled') {
            return ['document_cancelled', 'Stornovaný doklad se neupravuje.'];
        }
        if ($type === 'invoice' && $doc['status'] !== 'draft' && !RequestAuthorization::canEditIssuedInvoice($request)) {
            return ['forbidden', 'Chybí oprávnění upravit vystavenou fakturu.'];
        }
        $lock = $type === 'invoice' ? $this->locks->forInvoice($doc) : $this->locks->forPurchaseInvoice($doc);
        if (!$lock->posted && $lock->inClosedPeriod) {
            return ['period_not_open', 'Doklad leží v uzavřeném účetním období.'];
        }
        return null;
    }

    /**
     * Přepis zápisu na místě v podaném období DPH, který není daňově neutrální. Zamčené
     * datum to rozhodne samo (plán pak storno nebo odmítnutí); tady jde o podané období,
     * přes které správce zámek posunul zpět. NULL = smí se.
     *
     * @param array<string,mixed> $preview plán přeúčtování s opravenými řádky
     */
    private function filedRewriteBlocker(int $supplierId, string $type, int $docId, array $preview): ?string
    {
        if ($preview['strategy'] !== DocumentRepostService::STRATEGY_REPLACE || $preview['tax_neutral_violation'] === null
            || $preview['reason_code'] === 'tax_neutral_rewrite') {
            return null;
        }
        if ($type === 'invoice') {
            $stmt = $this->db->pdo()->prepare('SELECT effective_tax_date FROM invoices WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$docId, $supplierId]);
            $refDate = (string) $stmt->fetchColumn();
            $filed = $refDate === '' ? null : $this->filedPeriods->describe($supplierId, $refDate);
        } else {
            $filed = $this->purchaseFiledPeriods->describe($supplierId, $docId);
        }
        if ($filed === null) {
            return null;
        }
        return 'Období dokladu už je podané (' . $filed . '). Zápis v něm jde přepsat jen přesunem bez daňového dopadu, '
            . 'nová předkontace ale ' . TaxNeutralReclassification::describe((string) $preview['tax_neutral_violation']) . '.';
    }

    private function assertMayRepost(BulkContext $ctx, string $type): void
    {
        $request = $ctx->request();
        $allowed = $type === 'invoice'
            ? RequestAuthorization::canRepostIssuedInvoice($request)
            : RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE);
        if (!$allowed) {
            throw new PostingException('forbidden', 'Chybí oprávnění zaúčtovat doklad znovu.', 403);
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transaction(callable $work, bool $discard = false): mixed
    {
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo, static fn (): mixed => BulkTransaction::run($pdo, $work, $discard));
    }

    private static function reasonFor(PostingException $e): string
    {
        return match ($e->errorCode) {
            'repost_strategy_changed' => (string) ($e->context['reason_code'] ?? 'needs_reverse'),
            'version_conflict' => 'changed_after_dry_run',
            'repost_date_shift_confirmation_required' => 'needs_reverse',
            'period_not_open', 'date_locked', 'period_filed', 'entry_reversed', 'taken_over', 'forbidden', 'invalid_posting_rule',
            'mixed_counter_accounts', 'dimension_required' => $e->errorCode,
            default => 'repost_failed',
        };
    }
}
