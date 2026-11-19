<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné nastavení dimenzí (Středisko, Zakázka, Činnost a další typy firmy).
 *
 * Víc typů v jednom běhu, každý v režimu `set` (přepsat), `fill` (jen kde je prázdné)
 * nebo `clear` (vymazat). Faktury a přijaté faktury se mění na dokladu
 * ({@see DimensionService::bulkPatchDocument()}): hlavička (`scope=document`, platí pro
 * všechny řádky zápisu jako panel Dimenze na detailu) nebo jen položky
 * (`scope=items`, do deníku se promítnou jen na nákladové a výnosové řádky). Zápisy
 * vybrané v deníku se mění na řádcích ({@see DimensionService::bulkPatchEntryLines()}),
 * volitelně jen na účtech s danými prefixy.
 *
 * Vrácení obnoví přesně předchozí hodnoty včetně „bez hodnoty" a rozpadů.
 */
final class SetDimensionsOperation implements BulkOperation
{
    public const KEY = 'set_dimensions';
    private const MODES = ['set', 'fill', 'clear'];

    public function __construct(
        private readonly DimensionService $dimensions,
        private readonly DimensionRepository $repo,
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
        // Dimenze dokladu i řádků deníku ukládá panel Dimenze, který vyžaduje zápis do účetnictví.
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return 'Chybí oprávnění k zápisu do účetnictví, které úprava dimenzí vyžaduje.';
        }
        if (!$this->dimensions->enabled($supplierId)) {
            return 'Firma nemá zapnuté dimenze.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $list = $raw['dimensions'] ?? null;
        if (!is_array($list) || !array_is_list($list) || $list === []) {
            throw new BulkActionException('validation_failed', 'Zvolte alespoň jednu dimenzi, kterou chcete nastavit nebo vymazat.', 422);
        }
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $type) {
            $types[(int) $type['id']] = $type;
        }
        $changes = [];
        foreach ($list as $change) {
            $typeId = is_array($change) ? (int) ($change['type_id'] ?? 0) : 0;
            $mode = is_array($change) ? (string) ($change['mode'] ?? '') : '';
            if (!isset($types[$typeId]) || !in_array($mode, self::MODES, true)) {
                throw new BulkActionException('validation_failed', 'Neplatný typ dimenze nebo režim změny.', 422);
            }
            if (isset($changes[$typeId])) {
                throw new BulkActionException('validation_failed', 'Každý typ dimenze zadejte jen jednou.', 422);
            }
            $valueId = null;
            if ($mode !== 'clear') {
                $valueId = (int) ($change['value_id'] ?? 0);
                try {
                    $norm = $this->dimensions->normalize($supplierId, [$typeId => $valueId]);
                } catch (DimensionException $e) {
                    throw new BulkActionException('validation_failed', $e->getMessage(), 422);
                }
                if (($norm[$typeId] ?? null) !== $valueId) {
                    throw new BulkActionException('validation_failed', 'Zvolte hodnotu dimenze „' . $types[$typeId]['name'] . '".', 422);
                }
            }
            $changes[$typeId] = ['type_id' => $typeId, 'mode' => $mode, 'value_id' => $valueId];
        }
        $params = ['dimensions' => array_values($changes)];
        if ($target === 'journal_entry') {
            $prefixes = $raw['account_prefixes'] ?? [];
            if (is_string($prefixes)) {
                $prefixes = explode(',', $prefixes);
            }
            if (!is_array($prefixes)) {
                throw new BulkActionException('validation_failed', 'Neplatný seznam účtů.', 422);
            }
            $clean = [];
            foreach ($prefixes as $prefix) {
                $prefix = trim((string) $prefix);
                if ($prefix === '') continue;
                if (preg_match('/^[0-9][0-9A-Za-z.]{0,9}$/D', $prefix) !== 1) {
                    throw new BulkActionException('validation_failed', 'Účet nebo začátek účtu „' . mb_substr($prefix, 0, 20) . '" není platný.', 422);
                }
                $clean[$prefix] = $prefix;
            }
            $params['account_prefixes'] = array_values($clean);
        } else {
            $scope = (string) ($raw['scope'] ?? 'document');
            if (!in_array($scope, ['document', 'items'], true)) {
                throw new BulkActionException('validation_failed', 'Rozsah změny musí být doklad nebo položky.', 422);
            }
            $params['scope'] = $scope;
        }
        return $params;
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        try {
            $result = $this->patch($ctx, $row, true, null);
        } catch (DimensionException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reason($e), 'message' => $e->getMessage()] + $item;
        }
        $item['before'] = $result['before'];
        $item['after'] = $result['after'];
        if (!$result['changed'] || $result['fingerprint_before'] === $result['fingerprint_after']) {
            $noLines = ($result['matched'] ?? 1) === 0;
            return ['status' => 'unchanged', 'reason_code' => $noLines ? 'no_matching_lines' : null] + $item;
        }
        return ['status' => 'would_change', 'strategy' => 'replace', 'fingerprint' => $result['fingerprint_before']] + $item;
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        try {
            $result = $this->patch($ctx, $dry, false, (string) $dry['fingerprint']);
        } catch (DimensionException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reason($e), 'message' => $e->getMessage()] + $item;
        }
        return [
            'status' => 'applied', 'strategy' => 'replace', 'reason_code' => null, 'message' => null,
            'before' => $result['before'], 'after' => $result['after'], 'fingerprint' => $result['fingerprint_after'],
        ] + $item;
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        try {
            $result = $applied['source_type'] === 'journal_entry'
                ? $this->dimensions->bulkRestoreEntryLines($ctx->supplierId, (int) $applied['source_id'], (array) $applied['before'], (string) $applied['fingerprint'])
                : $this->dimensions->bulkRestoreDocument($ctx->supplierId, (string) $applied['source_type'], (int) $applied['source_id'], (array) $applied['before'], (string) $applied['fingerprint']);
        } catch (DimensionException $e) {
            return ['status' => 'failed', 'reason_code' => self::reason($e), 'message' => $e->getMessage()] + $item;
        }
        return [
            'status' => 'applied', 'strategy' => 'replace', 'reason_code' => null, 'message' => null,
            'before' => $result['before'], 'after' => $result['after'], 'fingerprint' => $result['fingerprint_after'],
        ] + $item;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function patch(BulkContext $ctx, array $row, bool $dryRun, ?string $expected): array
    {
        $changes = (array) ($ctx->params['dimensions'] ?? []);
        if ($row['source_type'] === 'journal_entry') {
            return $this->dimensions->bulkPatchEntryLines($ctx->supplierId, (int) $row['source_id'], $changes,
                (array) ($ctx->params['account_prefixes'] ?? []), $dryRun, $expected);
        }
        return $this->dimensions->bulkPatchDocument($ctx->supplierId, (string) $row['source_type'], (int) $row['source_id'], $changes,
            (string) ($ctx->params['scope'] ?? 'document'), $dryRun, $expected);
    }

    private static function reason(DimensionException $e): string
    {
        return match ($e->errorCode) {
            'not_found', 'period_not_open', 'dimensions_frozen', 'dimension_required', 'split_in_locked_period',
            'account_change_in_locked_period', 'account_change_needs_repost', 'changed_after_dry_run',
            'changed_after_apply', 'entry_reversed', 'dimensions_disabled' => $e->errorCode,
            default => $e->errorCode !== '' ? mb_substr($e->errorCode, 0, 80) : 'failed',
        };
    }
}
