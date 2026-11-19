<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Http\BulkDryRun;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * Hromadné smazání. Každý záznam jde PŘESNĚ cestou tlačítka Smazat na jednom záznamu:
 * operace volá tutéž akci s rolí uživatele, který úlohu spustil, a bez přebití
 * (`force`, potvrzení zamčeného období, retenční lhůty). Co by jednotlivě neprošlo,
 * se přeskočí s důvodem, který akce vrátila.
 *
 *   • vydaná faktura ({@see DeleteInvoiceAction}): koncept se smaže; vystavený doklad
 *     jen správce firmy a jen mimo retenční lhůtu, zaúčtovaný zápis se přitom stornuje,
 *   • přijatá faktura ({@see DeletePurchaseInvoiceAction}): jen koncept,
 *   • zápis v deníku ({@see JournalAction::delete()}): zápis v otevřeném a nezamčeném
 *     období; zápis, který smazat nejde, se podle volby `journal_fallback` přeskočí,
 *     nebo stornuje ({@see JournalAction::reverse()}).
 *
 * Běh nanečisto volá tytéž akce: faktury s příznakem {@see BulkDryRun} (akce odvolá
 * transakci před mazáním souborů), zápisy v deníku v transakci, která se odvolá.
 * Smazání vrátit nejde.
 */
final class DeleteOperation implements BulkOperation
{
    public const KEY = 'delete';

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
        private readonly DeleteInvoiceAction $deleteInvoice,
        private readonly DeletePurchaseInvoiceAction $deletePurchase,
        private readonly JournalAction $journal,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function supportsRollback(): bool
    {
        return false;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canBulkChange($request)) {
            return 'Hromadné změny smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.';
        }
        [$permission, $label] = match ($target) {
            'invoice' => ['invoices.delete', 'mazat vydané faktury'],
            'purchase_invoice' => ['purchase_invoices.delete', 'mazat přijaté faktury'],
            default => ['accounting.journal.write', 'měnit zápisy v deníku'],
        };
        if (!RequestAuthorization::allows($request, $permission, AccessLevel::WRITE)) {
            return 'Chybí oprávnění ' . $label . '.';
        }
        if ($target === 'journal_entry' && !$this->modes->hasDoubleEntry($supplierId)) {
            return 'Firma nevede podvojné účetnictví.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        if ($target !== 'journal_entry') {
            return [];
        }
        $fallback = (string) ($raw['journal_fallback'] ?? 'skip');
        if (!in_array($fallback, ['skip', 'reverse'], true)) {
            throw new BulkActionException('validation_failed', 'Neplatná volba pro zápisy, které smazat nejde.', 422);
        }
        return ['journal_fallback' => $fallback];
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        $state = $this->state($ctx->supplierId, (string) $row['source_type'], (int) $row['source_id']);
        if ($state === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        $item['before'] = ['status' => $state['status'], 'posted' => $state['posted']];
        $outcome = $this->run($ctx, (string) $row['source_type'], (int) $row['source_id'], true);
        return $this->toItem($outcome, $item, 'would_change') + ['fingerprint' => $state['fingerprint']];
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        $state = $this->state($ctx->supplierId, (string) $dry['source_type'], (int) $dry['source_id']);
        if ($state === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        if ($state['fingerprint'] !== $dry['fingerprint']) {
            return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
        }
        $outcome = $this->run($ctx, (string) $dry['source_type'], (int) $dry['source_id'], false);
        return $this->toItem($outcome, $item, 'applied');
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        return ['status' => 'failed', 'reason_code' => 'rollback_not_supported', 'message' => 'Smazání nejde vrátit.']
            + BulkSelection::copyItem($applied);
    }

    /**
     * @param array{ok:bool, action:string, code:?string, message:?string, result_entry_id:?int} $outcome
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function toItem(array $outcome, array $item, string $okStatus): array
    {
        if (!$outcome['ok']) {
            return ['status' => 'skipped', 'reason_code' => $outcome['code'], 'message' => $outcome['message']] + $item;
        }
        return [
            'status' => $okStatus,
            'strategy' => $outcome['action'],
            'reason_code' => $outcome['action'] === 'reverse' ? 'reversed_instead' : null,
            'message' => $outcome['action'] === 'reverse' ? $outcome['message'] : null,
            'after' => ['action' => $outcome['action']],
            'result_entry_id' => $outcome['result_entry_id'],
        ] + $item;
    }

    /**
     * Zavolá akci jednoho záznamu za uživatele úlohy.
     *
     * @return array{ok:bool, action:string, code:?string, message:?string, result_entry_id:?int}
     */
    private function run(BulkContext $ctx, string $type, int $id, bool $dryRun): array
    {
        $request = $ctx->request();
        $args = ['id' => (string) $id];
        if ($type !== 'journal_entry') {
            if ($dryRun) {
                $request = $request->withAttribute(BulkDryRun::ATTR, true);
            }
            $response = $this->call(fn (): Response => $type === 'invoice'
                ? ($this->deleteInvoice)($request, new SlimResponse(), $args)
                : ($this->deletePurchase)($request, new SlimResponse(), $args), $dryRun);
            return self::outcome($response, 'delete');
        }
        $deleted = self::outcome($this->call(fn (): Response => $this->journal->delete($request, new SlimResponse(), $args), $dryRun), 'delete');
        if ($deleted['ok'] || ($ctx->params['journal_fallback'] ?? 'skip') !== 'reverse') {
            return $deleted;
        }
        $reversed = self::outcome($this->call(fn (): Response => $this->journal->reverse($request, new SlimResponse(), $args), $dryRun), 'reverse');
        if (!$reversed['ok']) {
            return $reversed;
        }
        return ['message' => 'Zápis nejde smazat (' . $deleted['message'] . '), stornuje se.'] + $reversed;
    }

    /**
     * Běh nanečisto obalí akci transakcí, kterou pak odvolá: akce uvnitř cizí transakce
     * necommituje a soubory příloh neuklízí, faktura se navíc zastaví před mazáním
     * souborů ({@see BulkDryRun}). Ostrý běh volá akci přímo, transakci si drží sama.
     *
     * @param callable():Response $call
     */
    private function call(callable $call, bool $dryRun): Response
    {
        return $dryRun ? BulkTransaction::run($this->db->pdo(), $call, true) : $call();
    }

    /** @return array{ok:bool, action:string, code:?string, message:?string, result_entry_id:?int} */
    private static function outcome(Response $response, string $action): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        if ($response->getStatusCode() < 300) {
            return ['ok' => true, 'action' => $action, 'code' => null, 'message' => null,
                'result_entry_id' => $action === 'reverse' && isset($body['id']) ? (int) $body['id'] : null];
        }
        $code = (string) ($body['error']['code'] ?? 'delete_blocked');
        return [
            'ok' => false,
            'action' => $action,
            'code' => mb_substr($code, 0, 80),
            'message' => isset($body['error']['message']) ? mb_substr((string) $body['error']['message'], 0, 500) : null,
            'result_entry_id' => null,
        ];
    }

    /**
     * Stav záznamu před smazáním a jeho otisk: ostrý běh smaže jen záznam, který se od
     * běhu nanečisto nezměnil.
     *
     * @return array{status:?string, posted:bool, fingerprint:string}|null
     */
    private function state(int $supplierId, string $type, int $id): ?array
    {
        $sql = match ($type) {
            'invoice' => "SELECT d.status, d.updated_at, EXISTS(SELECT 1 FROM journal_entries je WHERE je.supplier_id = d.supplier_id
                              AND je.source_type = 'invoice' AND je.active_source_id = d.id AND je.posted_at IS NOT NULL) AS posted
                            FROM invoices d WHERE d.id = ? AND d.supplier_id = ?",
            'purchase_invoice' => "SELECT d.status, d.updated_at, EXISTS(SELECT 1 FROM journal_entries je WHERE je.supplier_id = d.supplier_id
                              AND je.source_type = 'purchase_invoice' AND je.active_source_id = d.id AND je.posted_at IS NOT NULL) AS posted
                            FROM purchase_invoices d WHERE d.id = ? AND d.supplier_id = ?",
            default => "SELECT IF(je.reversed_by IS NOT NULL, 'reversed', IF(je.posted_at IS NULL, 'draft', 'posted')) AS status,
                               je.row_version AS updated_at, je.posted_at IS NOT NULL AS posted
                          FROM journal_entries je WHERE je.id = ? AND je.supplier_id = ?",
        };
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'status' => $row['status'] === null ? null : (string) $row['status'],
            'posted' => (bool) $row['posted'],
            'fingerprint' => hash('sha256', $type . '#' . $id . '#' . $row['status'] . '#' . $row['updated_at'] . '#' . $row['posted']),
        ];
    }
}
