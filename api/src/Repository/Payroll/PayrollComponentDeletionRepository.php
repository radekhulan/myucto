<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání nikdy nepoužité verze mzdové složky.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Peníze a výpočet: mzdový vstup, benefitní úhrn nebo pravidelný předpis, který
 * složku používá. Pro použitou složku zůstává to, co umí dnes — ukončení
 * platnosti (`valid_to`) a deaktivace (`is_active`). Mazání je jen pro překlep:
 * složku, kterou nikdo nikdy nepoužil, není před čím chránit.
 *
 * ── Systémové složky ──────────────────────────────────────────────────────────
 * Výchozí číselník si aplikace zakládá sama (`ensureDefaults()`) při každém
 * výpisu. Smazání systémové složky by proto bylo tiché nic: řádek by se hned
 * vrátil, jen bez úprav, které si u něj uživatel udělal. Blokujeme to výslovně
 * a nabízíme deaktivaci — jinak by šlo o nahlášenou chybu, ne o hotovou funkci.
 *
 * ── Mapování na JMHZ je konfigurace, ne pohyb ─────────────────────────────────
 * `payroll_component_jmhz_mappings` říká, do kterého pole hlášení složka patří.
 * Je to nastavení té složky, ne záznam o podání — u složky, ze které se nikdy
 * nic nespočítalo, se nemá co chránit, takže mizí spolu s ní.
 */
final class PayrollComponentDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function blockers(): array
    {
        return [
            'system_default' => [
                'code' => 'payroll_component_is_system_default',
                'message' => 'Tohle je systémová mzdová složka, kterou si aplikace zakládá '
                    . 'sama — po smazání by se hned vrátila. Místo mazání jí nastavte konec '
                    . 'platnosti nebo ji deaktivujte.',
                'sql' => 'component.code IN (' . self::defaultCodeList() . ')',
            ],
            /*
             * Zrušený vstup není pohyb — je to zahozený pokus. Blokoval ale
             * mazání natrvalo, takže jediný omylem založený a hned zrušený
             * vstup uměl složku uvěznit v číselníku navždy.
             */
            'input' => [
                'code' => 'payroll_component_used_in_inputs',
                'message' => 'Složka je použitá v mzdových vstupech. Jde o peníze, takže '
                    . 'smazat ji nelze — nastavte jí konec platnosti nebo ji deaktivujte.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_inputs input
                     WHERE input.supplier_id = component.supplier_id
                       AND input.component_id = component.id
                       AND input.status <> "cancelled"
                )',
            ],
            'recurring' => [
                'code' => 'payroll_component_used_in_recurring',
                'message' => 'Podle složky je u některého pracovního vztahu nastavená '
                    . 'pravidelná složka. Nejdřív smažte ten předpis, teprve pak půjde '
                    . 'složku smazat.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_recurring_components recurring
                     WHERE recurring.supplier_id = component.supplier_id
                       AND recurring.component_id = component.id
                )',
            ],
            'benefit' => [
                'code' => 'payroll_component_used_in_benefits',
                'message' => 'Ke složce jsou napočtené benefitní úhrny za zaměstnance. '
                    . 'Jde o peníze, takže smazat ji nelze.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_benefit_accumulators benefit
                     WHERE benefit.supplier_id = component.supplier_id
                       AND benefit.component_id = component.id
                )',
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [
            'jmhz_mapping' => 'SELECT COUNT(*)
                                 FROM payroll_component_jmhz_mappings mapping
                                WHERE mapping.supplier_id = component.supplier_id
                                  AND mapping.component_definition_id = component.id',
        ];
    }

    protected static function table(): string
    {
        return 'payroll_component_definitions';
    }

    protected static function rowAlias(): string
    {
        return 'component';
    }

    protected static function notFoundMessage(): string
    {
        return 'Mzdová složka nebyla nalezena.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.component.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_component_definition';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'code',
            'name',
            'component_kind',
            'valid_from',
            'valid_to',
            'is_active',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'component_kind' => (string) ($row['component_kind'] ?? ''),
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }

    /**
     * Mapování na JMHZ má FK RESTRICT — databáze ho sama nekaskáduje, musí zmizet
     * ručně a PŘED složkou.
     *
     * @param array<string,string|int|null> $row
     */
    protected function beforeGuardedDelete(int $supplierId, int $id, array $row): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ? AND component_definition_id = ?'
        )->execute([$supplierId, $id]);
    }

    private static function defaultCodeList(): string
    {
        $quoted = [];
        foreach (PayrollComponentRepository::defaultCodes() as $code) {
            if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/', $code) !== 1) {
                throw new \LogicException('Kód systémové mzdové složky má nečekaný tvar.');
            }
            $quoted[] = "'{$code}'";
        }

        return $quoted === [] ? "''" : implode(', ', $quoted);
    }
}
