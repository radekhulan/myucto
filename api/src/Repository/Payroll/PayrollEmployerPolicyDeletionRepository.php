<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání verze zaměstnavatelského pravidla, podle které se ještě nic nespočítalo.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Schválený i rozpracovaný VÝPOČET. Pravidlo určuje výplatní termín, zaokrouhlení
 * salda a další provozní volby — jakmile v jeho platnosti existuje mzdový běh,
 * počítalo se podle něj a smazáním bychom se rozešli s tím, co je v revizích
 * zamrazené.
 * Typický případ, kvůli kterému mazání vzniklo, je opak: verze s BUDOUCÍ
 * platností, kterou někdo založil s překlepem a ještě do ní nespadl žádný běh.
 *
 * ── Auditní stopa pravidla ────────────────────────────────────────────────────
 * `payroll_employer_policy_audit` je append-only a měla FK RESTRICT, takže
 * pravidlo nešlo smazat ani po odblokování triggeru. Migrace 1388 mění ten cizí
 * klíč na ON DELETE CASCADE: řádky auditu jsou snapshoty „založeno / upraveno"
 * TOHO pravidla, ne důkaz pohybu, a mizí spolu s ním. Append-only trigger zůstává
 * — přímé mazání auditu je dál zakázané, FK kaskáda triggery nespouští. Fakt, že
 * pravidlo existovalo a kdo ho smazal, zůstává v `activity_log`.
 */
final class PayrollEmployerPolicyDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function blockers(): array
    {
        return [
            'run' => [
                'code' => 'payroll_employer_policy_in_use',
                'message' => 'Podle tohoto pravidla se už počítala mzda — v jeho platnosti '
                    . 'existuje mzdový běh. Smazat ho nelze; ukončete mu platnost '
                    . 'a založte novou verzi od dalšího období.',
                'sql' => "EXISTS (
                    SELECT 1
                      FROM payroll_runs run
                     WHERE run.supplier_id = policy.supplier_id
                       AND run.period_start >= policy.valid_from
                       AND run.period_start <= COALESCE(policy.valid_to, '9999-12-31')
                )",
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [
            'audit' => 'SELECT COUNT(*) FROM payroll_employer_policy_audit audit
                         WHERE audit.supplier_id = policy.supplier_id
                           AND audit.policy_id = policy.id',
        ];
    }

    protected static function table(): string
    {
        return 'payroll_employer_policies';
    }

    protected static function rowAlias(): string
    {
        return 'policy';
    }

    protected static function notFoundMessage(): string
    {
        return 'Zaměstnavatelská politika nebyla nalezena.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.employer_policy.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_employer_policy';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'valid_from',
            'valid_to',
            'payday_day',
            'delivery_channel',
            'source_kind',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
            'payday_day' => (int) ($row['payday_day'] ?? 0),
            'delivery_channel' => (string) ($row['delivery_channel'] ?? ''),
            'source_kind' => (string) ($row['source_kind'] ?? ''),
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }
}
