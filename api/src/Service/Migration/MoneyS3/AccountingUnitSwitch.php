<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use PDO;

/**
 * Režim účetní jednotky a automatika účtování kolem převodu.
 *
 * **Režim je na dvou místech.** Historii režimu čte {@see AccountingModeRepository::forYear()}
 * (výkazy, daně), ale nastavení v UI a aktivační průvodce čtou sloupce na firmě
 * (`supplier.accounting_mode` a spol.). Zapsat jen jedno z nich znamená firmu, která se
 * v jedné části aplikace tváří jako daňová evidence. Stejný dvojí zápis dělá aktivace
 * účetnictví ({@see \MyInvoice\Service\Accounting\Activation\BackfillService}) —
 * tam je ale součástí jobu doúčtování a zavolat samostatně nejde.
 *
 * **Automatika je během převodu vypnutá.** Deník přichází z Money hotový, takže každý
 * automatický zápis nad týmiž doklady je duplicita. Po úspěšném převodu se vrátí stav
 * před převodem; firma, která podvojné účetnictví zapíná právě převodem, dostane výchozí
 * nastavení účetní jednotky stejně jako po aktivaci. Neúspěšný převod automatiku NEZAPÍNÁ:
 * doklady bez vazby na deník by jinak cron zaúčtoval podruhé.
 */
final class AccountingUnitSwitch
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
        private readonly AutoPostingPolicyService $policy,
    ) {}

    /** @return array<string,mixed> stav automatiky a režimu před převodem */
    public function snapshot(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT accounting_mode, auto_post_invoices, auto_post_purchases FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $policy = $this->policy->listPolicy($supplierId);
        $rows = [];
        foreach ($policy['rows'] as $row) {
            if (empty($row['is_default'])) {
                $rows[(string) $row['operation_type']] = (string) $row['level'];
            }
        }
        return [
            'was_double_entry' => ($supplier['accounting_mode'] ?? null) === 'double_entry',
            'automation_level' => (string) ($policy['automation_level'] ?? 'suggest'),
            'policy' => $rows,
            'auto_post_invoices' => (int) ($supplier['auto_post_invoices'] ?? 0),
            'auto_post_purchases' => (int) ($supplier['auto_post_purchases'] ?? 0),
        ];
    }

    public function disableAutomation(int $supplierId, ?int $userId): void
    {
        $this->policy->applyPreset($supplierId, 'off', $userId);
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_invoices = 0, auto_post_purchases = 0 WHERE id = ?')
            ->execute([$supplierId]);
    }

    public function automationLevel(int $supplierId): string
    {
        return (string) ($this->policy->listPolicy($supplierId)['automation_level'] ?? 'suggest');
    }

    /** @param array<string,mixed> $snapshot */
    public function restoreAutomation(int $supplierId, array $snapshot, ?int $userId): void
    {
        if (empty($snapshot['was_double_entry'])) {
            $this->policy->applyAccountingUnitDefaults($supplierId, $userId);
            return;
        }
        $level = (string) ($snapshot['automation_level'] ?? 'suggest');
        $this->policy->applyPreset($supplierId, in_array($level, ['off', 'suggest', 'assisted', 'full'], true) ? $level : 'suggest', $userId);
        foreach ((array) ($snapshot['policy'] ?? []) as $type => $rowLevel) {
            $this->policy->upsertRow($supplierId, (string) $type, (string) $rowLevel, $userId);
        }
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_invoices = ?, auto_post_purchases = ? WHERE id = ?')
            ->execute([(int) ($snapshot['auto_post_invoices'] ?? 0), (int) ($snapshot['auto_post_purchases'] ?? 0), $supplierId]);
    }

    /**
     * Podvojné účetnictví od začátku prvního převáděného období — jinak by historické
     * roky spadly do daňové evidence. Začátek účetnictví se jen posouvá dozadu, nikdy
     * dopředu (firma mohla v MyÚčtu účtovat už dřív).
     *
     * `$onSupplier = false` (zkouška nanečisto) zapíše jen historii režimů, ze které čtou
     * výkazy a uzávěrka — řádek firmy by v transakci zkoušky zůstal zamčený.
     *
     * Záznam jiného režimu, který v historii leží uvnitř převáděných let (`$since` až
     * `$until`), by je pro výkazy a daně vrátil do daňové evidence — v Money byly
     * podvojné, takový záznam se proto odstraní. Pozdější záznamy zůstávají.
     */
    public function switchToDoubleEntry(int $supplierId, string $since, bool $onSupplier = true, ?string $until = null): void
    {
        if ($until !== null) {
            $this->db->pdo()->prepare(
                "DELETE FROM supplier_accounting_modes
                  WHERE supplier_id = ? AND effective_from > ? AND effective_from <= ? AND accounting_mode <> 'double_entry'"
            )->execute([$supplierId, $since, $until]);
        }
        if (!$onSupplier) {
            $this->modes->record($supplierId, $since, 'double_entry');
            return;
        }
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET accounting_mode = 'double_entry',
                    accounting_enabled = 1,
                    accounting_activation_status = 'completed',
                    accounting_starts_on = CASE
                        WHEN accounting_starts_on IS NULL OR accounting_starts_on > ? THEN ?
                        ELSE accounting_starts_on END
              WHERE id = ?"
        )->execute([$since, $since, $supplierId]);
        $this->modes->record($supplierId, $since, 'double_entry');
    }
}
