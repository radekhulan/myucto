<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * Hromadná změna poznámky vydaných a přijatých faktur: text nad položkami
 * (`note_above_items`) nebo pod položkami (`note_below_items`). Jiné poznámky
 * (interní) faktury nemají. Režim `replace` (přepsat), `append` (připojit na nový
 * řádek) nebo `clear` (vymazat).
 *
 * Každý doklad se ukládá PŘESNĚ cestou tlačítka Uložit v editoru: částečnou úpravou
 * ({@see UpdateInvoiceAction}, {@see UpdatePurchaseInvoiceAction}) jen s poznámkou, za
 * uživatele, který úlohu spustil. Koncept upraví každý, kdo smí doklad upravit. Doklad,
 * který už koncept není, jen s volbou `include_issued` a stejně jako „Upravit vystavený
 * doklad" (`?force=1`): vystavenou fakturu jen správce nebo role s právem upravit
 * vystavenou fakturu, přijatou jen správce, storno nikdy. Úprava vystavené faktury
 * přepíše její snapshoty z aktuálních údajů a vygeneruje nové PDF, jako v editoru.
 * Doklad v uzavřeném účetním období se přeskočí: přebití uzavřeného období hromadná
 * akce nenabízí. Co akce odmítne, se přeskočí s jejím důvodem.
 *
 * Vrácení vrátí původní text, jen když je v poznámce pořád text z hromadné změny.
 */
final class SetNoteOperation implements BulkOperation
{
    public const KEY = 'set_note';
    public const FIELDS = ['note_above_items', 'note_below_items'];
    private const MODES = ['replace', 'append', 'clear'];
    private const MAX_TEXT = 2000;
    /** Sloupec TEXT (64 KB) se znaky až po 4 bajtech. */
    private const MAX_NOTE = 16000;

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly UpdateInvoiceAction $updateInvoice,
        private readonly UpdatePurchaseInvoiceAction $updatePurchase,
        private readonly BulkRecordCall $call,
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
        if (!RequestAuthorization::allows($request, $target === 'invoice' ? 'invoices' : 'purchase_invoices', AccessLevel::WRITE)) {
            return 'Chybí oprávnění upravovat doklady.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $field = (string) ($raw['note_field'] ?? '');
        $mode = (string) ($raw['note_mode'] ?? '');
        if (!in_array($field, self::FIELDS, true) || !in_array($mode, self::MODES, true)) {
            throw new BulkActionException('validation_failed', 'Zvolte poznámku a způsob změny.', 422);
        }
        $text = null;
        if ($mode !== 'clear') {
            $text = is_string($raw['note_text'] ?? null) ? self::clean($raw['note_text']) : '';
            if ($text === '') {
                throw new BulkActionException('validation_failed', 'Zadejte text poznámky.', 422);
            }
            if (mb_strlen($text) > self::MAX_TEXT) {
                throw new BulkActionException('validation_failed', 'Text poznámky může mít nejvýš ' . self::MAX_TEXT . ' znaků.', 422);
            }
        }
        return [
            'note_field' => $field,
            'note_mode' => $mode,
            'note_text' => $text,
            'include_issued' => filter_var($raw['include_issued'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        $type = (string) $row['source_type'];
        $state = $this->state($ctx->supplierId, $type, (int) $row['source_id'], (string) $ctx->params['note_field']);
        if ($state === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        $field = (string) $ctx->params['note_field'];
        $new = $this->newValue($ctx, $state['note']);
        $item['before'] = [$field => $state['note']];
        $item['after'] = [$field => $new];
        if ($new === $state['note']) {
            return ['status' => 'unchanged'] + $item;
        }
        if ($new !== null && mb_strlen($new) > self::MAX_NOTE) {
            return ['status' => 'skipped', 'reason_code' => 'note_too_long', 'message' => 'Poznámka by byla delší než ' . self::MAX_NOTE . ' znaků.'] + $item;
        }
        if (($blocked = $this->blocker($ctx, $state)) !== null) {
            return ['status' => 'skipped', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        $outcome = $this->save($ctx, $type, (int) $row['source_id'], $state, $new, true);
        if (!$outcome['ok']) {
            return ['status' => 'skipped', 'reason_code' => $outcome['code'], 'message' => $outcome['message']] + $item;
        }
        return ['status' => 'would_change', 'strategy' => $state['status'] === 'draft' ? 'draft' : 'issued',
            'fingerprint' => $state['fingerprint']] + $item;
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        $type = (string) $dry['source_type'];
        $id = (int) $dry['source_id'];
        $state = $this->state($ctx->supplierId, $type, $id, (string) $ctx->params['note_field']);
        if ($state === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        if ($state['fingerprint'] !== $dry['fingerprint']) {
            return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
        }
        if (($blocked = $this->blocker($ctx, $state)) !== null) {
            return ['status' => 'skipped', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        $field = (string) $ctx->params['note_field'];
        $outcome = $this->save($ctx, $type, $id, $state, $this->newValue($ctx, $state['note']), false);
        if (!$outcome['ok']) {
            return ['status' => 'skipped', 'reason_code' => $outcome['code'], 'message' => $outcome['message']] + $item;
        }
        $now = $this->state($ctx->supplierId, $type, $id, $field);
        return ['status' => 'applied', 'reason_code' => null, 'message' => null,
            'before' => [$field => $state['note']], 'after' => [$field => $now['note'] ?? null],
            'fingerprint' => $now['fingerprint'] ?? null] + $item;
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        $type = (string) $applied['source_type'];
        $id = (int) $applied['source_id'];
        $field = (string) $ctx->params['note_field'];
        $state = $this->state($ctx->supplierId, $type, $id, (string) $ctx->params['note_field']);
        if ($state === null) {
            return ['status' => 'failed', 'reason_code' => 'not_found'] + $item;
        }
        if ($state['fingerprint'] !== $applied['fingerprint']) {
            return ['status' => 'failed', 'reason_code' => 'changed_after_apply',
                'message' => 'Poznámka nebo stav dokladu se od hromadné změny znovu změnily.'] + $item;
        }
        if (($blocked = $this->blocker($ctx, $state)) !== null) {
            return ['status' => 'failed', 'reason_code' => $blocked[0], 'message' => $blocked[1]] + $item;
        }
        $previous = ((array) $applied['before'])[$field] ?? null;
        $outcome = $this->save($ctx, $type, $id, $state, $previous === null ? null : (string) $previous, false);
        if (!$outcome['ok']) {
            return ['status' => 'failed', 'reason_code' => $outcome['code'], 'message' => $outcome['message']] + $item;
        }
        $now = $this->state($ctx->supplierId, $type, $id, $field);
        return ['status' => 'applied', 'reason_code' => null, 'message' => null,
            'after' => [$field => $now['note'] ?? null], 'fingerprint' => $now['fingerprint'] ?? null] + $item;
    }

    /**
     * Doklad, na který hromadná úprava poznámky nesmí, ještě než se zeptá akce.
     *
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string}|null
     */
    private function blocker(BulkContext $ctx, array $state): ?array
    {
        if ($state['status'] === 'draft') {
            return null;
        }
        if (empty($ctx->params['include_issued'])) {
            return ['not_draft', 'Doklad už není koncept. Poznámku vystaveného dokladu změní jen volba „Upravit i vystavené doklady".'];
        }
        if (($ctx->params['origin'] ?? null) !== 'session') {
            return ['session_required', 'Vystavený doklad jde upravit jen z úlohy spuštěné v aplikaci.'];
        }
        if ($state['closed']) {
            return ['period_closed', 'Doklad spadá do uzavřeného účetního období.'];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{ok:bool, code:?string, message:?string}
     */
    private function save(BulkContext $ctx, string $type, int $id, array $state, ?string $value, bool $dryRun): array
    {
        $request = BulkRecordCall::request($ctx)->withParsedBody([(string) $ctx->params['note_field'] => $value]);
        if ($state['status'] !== 'draft') {
            $request = $request->withQueryParams(['force' => '1']);
        }
        $args = ['id' => (string) $id];
        return $this->call->run($request, fn (Request $r): Response => $type === 'invoice'
            ? ($this->updateInvoice)($r, new SlimResponse(), $args)
            : ($this->updatePurchase)($r, new SlimResponse(), $args), $dryRun);
    }

    private function newValue(BulkContext $ctx, ?string $current): ?string
    {
        $text = $ctx->params['note_text'] ?? null;
        return match ((string) $ctx->params['note_mode']) {
            'clear' => null,
            'append' => $current === null || trim($current) === '' ? (string) $text : rtrim($current) . "\n" . $text,
            default => (string) $text,
        };
    }

    private static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
    }

    /**
     * Poznámka dokladu, jeho stav, uzavřené období a otisk (stav a obě poznámky), podle
     * kterého ostrý běh i vrácení poznají změnu.
     *
     * @return array{status:string, note:?string, closed:bool, fingerprint:string}|null
     */
    private function state(int $supplierId, string $type, int $id, string $field): ?array
    {
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $supplierId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc === false) {
            return null;
        }
        // Dávkový dotaz zámků si nic nepamatuje: ostrý běh i vrácení vidí aktuální stav období.
        $lock = $this->locks->lockedMapForSources($supplierId, $type, [$id])[$id] ?? null;
        $notes = [];
        foreach (self::FIELDS as $name) {
            $notes[$name] = $doc[$name] === null ? null : (string) $doc[$name];
        }
        return [
            'status' => (string) $doc['status'],
            'note' => $notes[$field],
            'closed' => $lock !== null && $lock->inClosedPeriod,
            'fingerprint' => hash('sha256', json_encode([$type, $id, $doc['status'], $notes], JSON_THROW_ON_ERROR)),
        ];
    }
}
