<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

/**
 * Společný průběh hromadné změny položek vydaných a přijatých faktur přes úpravu jednoho
 * dokladu ({@see DocumentItemEditor}), dnes členění DPH a sazba ({@see SetVatClassificationOperation}).
 *
 * Záznamem hromadné akce je vždy DOKLAD: nad vybranými doklady (`invoice`,
 * `purchase_invoice`) se mění všechny běžné položky, nad vybranými položkami
 * (`invoice_item`, `purchase_invoice_item`, agenda Položky dokladů) jen ty vybrané,
 * ostatní položky téhož dokladu zůstanou. Doklad se uloží jednou se všemi vybranými
 * položkami, takže se jednou přepočte i přeúčtuje.
 *
 * Stav před a po nese u každé měněné položky pořadí, popis, kód členění, sazbu, účet
 * a částky, u dokladu součty. Ostrý běh zpracuje jen doklad, jehož otisk se od běhu
 * nanečisto nezměnil; vrácení jen doklad, který od ostrého běhu nikdo nezměnil, a jen
 * když uložení obnoví předchozí hodnoty přesně (jinak se neuloží nic).
 */
abstract class DocumentItemOperation implements BulkOperation, BulkCandidateSource
{
    public function __construct(
        protected readonly DocumentItemEditor $editor,
        protected readonly DocumentItemSearch $search,
        protected readonly BulkSelection $selection,
    ) {}

    /**
     * Požadované hodnoty polí položky (jen ta, která operace mění). Prázdné pole =
     * položky se operace netýká.
     *
     * @param array<string,mixed> $item položka ze {@see DocumentItemEditor::stateOf()}
     * @return array<string,mixed>
     */
    abstract protected function desired(BulkContext $ctx, string $type, array $item): array;

    /** Promítne se změna do evidence DPH (podané období se pak přeskočí)? */
    abstract protected function vatRelevant(): bool;

    /**
     * Proč doklad přeskočit ještě před uložením (NULL = nic).
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $items měněné položky
     * @return array{0:string, 1:string}|null
     */
    protected function documentReason(BulkContext $ctx, string $type, array $doc, array $items): ?array
    {
        return null;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function candidates(BulkContext $ctx): array
    {
        $ids = array_map('intval', (array) ($ctx->params['ids'] ?? []));
        return DocumentItemSearch::isItemTarget($ctx->target)
            ? $this->search->rows($ctx->supplierId, $ctx->target, $ids)
            : $this->selection->rows($ctx->supplierId, $ctx->target, $ids);
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        $type = (string) $row['source_type'];
        $docId = (int) $row['source_id'];
        $doc = $this->editor->document($ctx->supplierId, $type, $docId);
        if ($doc === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        $state = $this->editor->stateOf($ctx->supplierId, $type, $doc);
        $targets = $this->targets($state, isset($row['item_ids']) ? array_map('intval', (array) $row['item_ids']) : null);
        if ($targets === null) {
            return ['status' => 'skipped', 'reason_code' => 'items_not_found',
                'message' => 'Vybrané položky na dokladu už nejsou (doklad se mezitím uložil znovu). Obnovte seznam položek.'] + $item;
        }
        $item['amount'] = round(array_sum(array_column($targets, 'total_with_vat')), 2);
        $item['before'] = self::snapshot($state, $targets);
        $changes = $this->changes($ctx, $type, $targets);
        if ($changes === []) {
            return ['status' => 'unchanged', 'after' => $item['before']] + $item;
        }
        $reason = $this->editor->blocker($ctx->supplierId, $type, $doc, $this->vatRelevant())
            ?? $this->documentReason($ctx, $type, $doc, array_values(array_intersect_key($targets, $changes)));
        if ($reason !== null) {
            return ['status' => 'skipped', 'reason_code' => $reason[0], 'message' => $reason[1]] + $item;
        }
        $result = $this->editor->save($ctx, $type, $docId, $changes, true);
        if (!$result['ok']) {
            return ['status' => 'skipped', 'reason_code' => $result['code'], 'message' => $result['message']] + $item;
        }
        $item['after'] = self::snapshot($result['after'], self::atPositions($result['after'], array_keys($targets)));
        if (self::comparable($item['after']['items']) === self::comparable($item['before']['items'])) {
            return ['status' => 'unchanged'] + $item;
        }
        return ['status' => 'would_change', 'strategy' => $state['entry'] !== null ? 'replace' : null,
            'fingerprint' => $state['fingerprint']] + $item;
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        $type = (string) $dry['source_type'];
        $docId = (int) $dry['source_id'];
        $doc = $this->editor->document($ctx->supplierId, $type, $docId);
        if ($doc === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        $state = $this->editor->stateOf($ctx->supplierId, $type, $doc);
        if ($state['fingerprint'] !== $dry['fingerprint']) {
            return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
        }
        $reason = $this->editor->blocker($ctx->supplierId, $type, $doc, $this->vatRelevant());
        if ($reason !== null) {
            return ['status' => 'skipped', 'reason_code' => $reason[0], 'message' => $reason[1]] + $item;
        }
        $positions = array_column((array) (($dry['before'] ?? [])['items'] ?? []), 'position');
        $changes = $this->changes($ctx, $type, self::atPositions($state, array_map('intval', $positions)));
        $result = $this->editor->save($ctx, $type, $docId, $changes, false);
        if (!$result['ok']) {
            return ['status' => 'skipped', 'reason_code' => $result['code'], 'message' => $result['message']] + $item;
        }
        return [
            'status' => 'applied', 'reason_code' => null, 'message' => null,
            'after' => self::snapshot($result['after'], self::atPositions($result['after'], array_map('intval', $positions))),
            'fingerprint' => $result['after']['fingerprint'],
            'result_entry_id' => $result['after']['entry']['id'] ?? null,
        ] + $item;
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        $type = (string) $applied['source_type'];
        $docId = (int) $applied['source_id'];
        $doc = $this->editor->document($ctx->supplierId, $type, $docId);
        if ($doc === null) {
            return ['status' => 'failed', 'reason_code' => 'not_found'] + $item;
        }
        $state = $this->editor->stateOf($ctx->supplierId, $type, $doc);
        if ($state['fingerprint'] !== $applied['fingerprint']) {
            return ['status' => 'failed', 'reason_code' => 'changed_after_apply'] + $item;
        }
        $reason = $this->editor->blocker($ctx->supplierId, $type, $doc, $this->vatRelevant());
        if ($reason !== null) {
            return ['status' => 'failed', 'reason_code' => $reason[0], 'message' => $reason[1]] + $item;
        }
        $previous = [];
        foreach ((array) (($applied['before'] ?? [])['items'] ?? []) as $old) {
            $previous[(int) $old['position']] = (array) $old;
        }
        $changes = [];
        foreach (self::atPositions($state, array_keys($previous)) as $position => $now) {
            foreach (DocumentItemEditor::FIELDS as $field) {
                if (array_key_exists($field, $previous[$position]) && $previous[$position][$field] !== $now[$field]) {
                    $changes[$position][$field] = $previous[$position][$field];
                }
            }
        }
        $expected = (array) (($applied['before'] ?? [])['items'] ?? []);
        $verify = static function (array $after) use ($expected): ?string {
            $restored = self::snapshot($after, self::atPositions($after, array_map(static fn (array $i): int => (int) $i['position'], $expected)))['items'];
            return self::comparable($restored) === self::comparable($expected)
                ? null
                : 'Uložení dokladu předchozí stav položek přesně neobnoví, doklad zůstává beze změny.';
        };
        $result = $this->editor->save($ctx, $type, $docId, $changes, false, $verify);
        if (!$result['ok']) {
            return ['status' => 'failed', 'reason_code' => $result['code'], 'message' => $result['message']] + $item;
        }
        return [
            'status' => 'applied', 'reason_code' => null, 'message' => null,
            'after' => self::snapshot($result['after'], self::atPositions($result['after'], array_keys($previous))),
            'fingerprint' => $result['after']['fingerprint'],
            'result_entry_id' => $result['after']['entry']['id'] ?? null,
        ] + $item;
    }

    /**
     * Měněné položky dokladu podle pořadí: všechny běžné (výběr dokladů), nebo vybrané
     * podle id (výběr položek). NULL = žádná vybraná položka už na dokladu není.
     *
     * @param array<string,mixed> $state
     * @param list<int>|null $itemIds
     * @return array<int, array<string,mixed>>|null
     */
    private function targets(array $state, ?array $itemIds): ?array
    {
        $out = [];
        foreach ($state['items'] as $line) {
            if ($line['standard'] && ($itemIds === null || in_array($line['item_id'], $itemIds, true))) {
                $out[(int) $line['position']] = $line;
            }
        }
        return $out === [] && $itemIds !== null ? null : $out;
    }

    /**
     * @param array<int, array<string,mixed>> $targets
     * @return array<int, array<string,mixed>>
     */
    private function changes(BulkContext $ctx, string $type, array $targets): array
    {
        $changes = [];
        foreach ($targets as $position => $line) {
            foreach ($this->desired($ctx, $type, $line) as $field => $value) {
                if ($line[$field] !== $value) {
                    $changes[$position][$field] = $value;
                }
            }
        }
        return $changes;
    }

    /**
     * @param array<string,mixed> $state
     * @param list<int> $positions
     * @return array<int, array<string,mixed>>
     */
    private static function atPositions(array $state, array $positions): array
    {
        $out = [];
        foreach ($state['items'] as $line) {
            if (in_array((int) $line['position'], $positions, true)) {
                $out[(int) $line['position']] = $line;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<int, array<string,mixed>> $items
     * @return array{items:list<array<string,mixed>>, document:array<string,mixed>, posted:bool}
     */
    private static function snapshot(array $state, array $items): array
    {
        ksort($items);
        return [
            'items' => array_values(array_map(static fn (array $i): array => array_diff_key($i, ['standard' => true]), $items)),
            'document' => array_intersect_key($state['header'], array_flip(['vat_classification_code', 'total_without_vat', 'total_vat', 'total_with_vat'])),
            'posted' => $state['entry'] !== null,
        ];
    }

    /**
     * Položky bez id (vydaná faktura je při uložení zapisuje znovu) a s typy hodnot
     * nezávislými na tom, zda prošly uložením do JSON (1000.0 vs 1000).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function comparable(array $items): array
    {
        return array_map(static function (array $i): array {
            unset($i['item_id']);
            foreach (['vat_rate', 'total_without_vat', 'total_vat', 'total_with_vat'] as $key) {
                $i[$key] = round((float) ($i[$key] ?? 0), 2);
            }
            $i['position'] = (int) ($i['position'] ?? 0);
            $i['vat_rate_id'] = (int) ($i['vat_rate_id'] ?? 0);
            $i['oss'] = (bool) ($i['oss'] ?? false);
            ksort($i);
            return $i;
        }, array_values($items));
    }
}
