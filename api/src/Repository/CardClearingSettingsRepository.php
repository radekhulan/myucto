<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Nastavení účtování plateb firemní kartou (`card_clearing_settings`), jeden řádek na firmu.
 *
 * Firma bez řádku má režim vypnutý a všechny účty výchozí — čtení vrací vždy úplný tvar,
 * volající se na existenci řádku ptát nemusí. Účty jsou volitelné: NULL znamená výchozí
 * účet (viz {@see DEFAULT_CODES}), takže firma, která nic nenastavila, účtuje stejně
 * jako bankovní automatika.
 */
final class CardClearingSettingsRepository
{
    /** Povolené syntetiky mezičlenu. 325 ne — je v SALDO_BLACKLIST bankovní automatiky. */
    public const SYNTHETICS = ['378', '261', '395'];

    public const DEFAULT_SYNTHETIC = '378';

    /** Výchozí kódy pro nevyplněné účty. FX kódy se berou z kontací fx.loss/fx.gain. */
    public const DEFAULT_CODES = [
        'writeoff'      => '548',
        'holder'        => '335',
        'rounding_loss' => '548',
        'rounding_gain' => '648',
    ];

    public const ACCOUNT_FIELDS = [
        'writeoff_account_id',
        'holder_account_id',
        'fx_loss_account_id',
        'fx_gain_account_id',
        'rounding_loss_account_id',
        'rounding_gain_account_id',
    ];

    public const DEFAULT_ALERT_DAYS = 30;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{configured:bool, enabled:bool, effective_from:?string, clearing_synthetic:string,
     *   writeoff_account_id:?int, holder_account_id:?int, fx_loss_account_id:?int, fx_gain_account_id:?int,
     *   rounding_loss_account_id:?int, rounding_gain_account_id:?int,
     *   writeoff_account_code:?string, holder_account_code:?string, fx_loss_account_code:?string,
     *   fx_gain_account_code:?string, rounding_loss_account_code:?string, rounding_gain_account_code:?string,
     *   auto_create_cards:bool, unmatched_alert_days:int}
     */
    public function find(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*,
                    wa.account_code AS writeoff_account_code,
                    ha.account_code AS holder_account_code,
                    fl.account_code AS fx_loss_account_code,
                    fg.account_code AS fx_gain_account_code,
                    rl.account_code AS rounding_loss_account_code,
                    rg.account_code AS rounding_gain_account_code
               FROM card_clearing_settings s
          LEFT JOIN chart_of_accounts wa ON wa.id = s.writeoff_account_id AND wa.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts ha ON ha.id = s.holder_account_id AND ha.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts fl ON fl.id = s.fx_loss_account_id AND fl.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts fg ON fg.id = s.fx_gain_account_id AND fg.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts rl ON rl.id = s.rounding_loss_account_id AND rl.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts rg ON rg.id = s.rounding_gain_account_id AND rg.supplier_id = s.supplier_id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return self::defaults();
        }
        $out = [
            'configured'           => true,
            'enabled'              => (bool) $row['enabled'],
            'effective_from'       => $row['effective_from'] !== null ? (string) $row['effective_from'] : null,
            'clearing_synthetic'   => in_array((string) $row['clearing_synthetic'], self::SYNTHETICS, true)
                ? (string) $row['clearing_synthetic'] : self::DEFAULT_SYNTHETIC,
            'auto_create_cards'    => (bool) $row['auto_create_cards'],
            'unmatched_alert_days' => (int) $row['unmatched_alert_days'],
        ];
        foreach (self::ACCOUNT_FIELDS as $field) {
            $out[$field] = $row[$field] !== null ? (int) $row[$field] : null;
            $codeField = str_replace('_id', '_code', $field);
            $out[$codeField] = $row[$codeField] !== null ? (string) $row[$codeField] : null;
        }
        return $out;
    }

    /** @param array<string,mixed> $data normalizovaný a validovaný vstup */
    public function save(int $supplierId, array $data, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO card_clearing_settings
                (supplier_id, enabled, effective_from, clearing_synthetic,
                 writeoff_account_id, holder_account_id, fx_loss_account_id, fx_gain_account_id,
                 rounding_loss_account_id, rounding_gain_account_id,
                 auto_create_cards, unmatched_alert_days, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                effective_from = VALUES(effective_from),
                clearing_synthetic = VALUES(clearing_synthetic),
                writeoff_account_id = VALUES(writeoff_account_id),
                holder_account_id = VALUES(holder_account_id),
                fx_loss_account_id = VALUES(fx_loss_account_id),
                fx_gain_account_id = VALUES(fx_gain_account_id),
                rounding_loss_account_id = VALUES(rounding_loss_account_id),
                rounding_gain_account_id = VALUES(rounding_gain_account_id),
                auto_create_cards = VALUES(auto_create_cards),
                unmatched_alert_days = VALUES(unmatched_alert_days),
                updated_by = VALUES(updated_by)'
        )->execute([
            $supplierId,
            !empty($data['enabled']) ? 1 : 0,
            $data['effective_from'] ?? null,
            (string) ($data['clearing_synthetic'] ?? self::DEFAULT_SYNTHETIC),
            $data['writeoff_account_id'] ?? null,
            $data['holder_account_id'] ?? null,
            $data['fx_loss_account_id'] ?? null,
            $data['fx_gain_account_id'] ?? null,
            $data['rounding_loss_account_id'] ?? null,
            $data['rounding_gain_account_id'] ?? null,
            !array_key_exists('auto_create_cards', $data) || !empty($data['auto_create_cards']) ? 1 : 0,
            (int) ($data['unmatched_alert_days'] ?? self::DEFAULT_ALERT_DAYS),
            $userId,
        ]);
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $out = [
            'configured'           => false,
            'enabled'              => false,
            'effective_from'       => null,
            'clearing_synthetic'   => self::DEFAULT_SYNTHETIC,
            'auto_create_cards'    => true,
            'unmatched_alert_days' => self::DEFAULT_ALERT_DAYS,
        ];
        foreach (self::ACCOUNT_FIELDS as $field) {
            $out[$field] = null;
            $out[str_replace('_id', '_code', $field)] = null;
        }
        return $out;
    }
}
