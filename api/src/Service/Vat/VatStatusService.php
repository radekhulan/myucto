<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Plátcovství DPH vlastní firmy K DATU (SSOT čtení supplier_vat_status_history).
 *
 * Historie (migrace 1120) je řada řádků {effective_from, is_vat_payer}; stav k datu D
 * = poslední řádek s effective_from <= D. Fallback na živý supplier.is_vat_payer kryje
 * legacy firmy bez řádku historie (baseline doplňuje migrace 1180, ale externě
 * založené řádky mimo aplikaci garantované nejsou).
 *
 * Živý sloupec supplier.is_vat_payer je derivovaná cache stavu "dnes" — pro jakékoli
 * rozhodování k datu dokladu/období se NESMÍ číst přímo; jediná správná cesta je
 * isVatPayerAt() (PHP) nebo payerAtExpr() (SQL joiny/agregace).
 */
final class VatStatusService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Byla firma plátcem DPH k danému datu (YYYY-MM-DD)? */
    public function isVatPayerAt(int $supplierId, string $date): bool
    {
        return self::payerAt($this->db->pdo(), $supplierId, $date);
    }

    /** Nulová sazba „osvobozeno" platná k datu: tu nesou řádky neplátce. `null` = číselník ji nemá. */
    public static function exemptRateIdAt(\PDO $pdo, string $date): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM vat_rates
              WHERE rate_percent = 0 AND is_reverse_charge = 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$date, $date]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Statická varianta {@see isVatPayerAt()} pro kontexty bez DI kontejneru
     * (statické buildery jako EpoSupplierBlockBuilder, bin skripty). Táž SQL
     * sémantika, jeden zdroj pravdy.
     */
    public static function payerAt(\PDO $pdo, int $supplierId, string $date): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(
                (SELECT is_vat_payer FROM supplier_vat_status_history
                  WHERE supplier_id = ? AND effective_from <= ?
                  ORDER BY effective_from DESC, id DESC LIMIT 1),
                (SELECT is_vat_payer FROM supplier WHERE id = ?)
            )'
        );
        $stmt->execute([$supplierId, $date, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Stav obou flagů (plátce + identifikovaná osoba) k danému datu. @return array{is_vat_payer:bool,is_identified:bool} */
    public function statusAt(int $supplierId, string $date): array
    {
        return self::flagsAt($this->db->pdo(), $supplierId, $date);
    }

    /**
     * Statická varianta {@see statusAt()}: oba flagy z řádku historie k datu
     * (migrace 1181 historizuje i is_identified), fallback na živý supplier
     * řádek u firem bez použitelného řádku historie.
     *
     * @return array{is_vat_payer:bool,is_identified:bool}
     */
    public static function flagsAt(\PDO $pdo, int $supplierId, string $date): array
    {
        $stmt = $pdo->prepare(
            'SELECT is_vat_payer, is_identified FROM supplier_vat_status_history
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $live = $pdo->prepare('SELECT is_vat_payer, is_identified FROM supplier WHERE id = ?');
            $live->execute([$supplierId]);
            $row = $live->fetch(\PDO::FETCH_ASSOC) ?: [];
        }

        return [
            'is_vat_payer'  => (bool) ($row['is_vat_payer'] ?? false),
            'is_identified' => (bool) ($row['is_identified'] ?? false),
        ];
    }

    /** Byla firma plátcem kdykoli BĚHEM intervalu [from, to]? */
    public function wasPayerDuring(int $supplierId, string $from, string $to): bool
    {
        return self::payerDuring($this->db->pdo(), $supplierId, $from, $to);
    }

    /**
     * Statická varianta {@see wasPayerDuring()}. Plátcovství byť jen po část
     * období zakládá povinnosti za CELÉ období (přiznání/KH za období, v němž
     * plátcovství zaniklo, se pořád podává) — kontroly „je plátce?" nad obdobím
     * proto nesmí testovat jen poslední den.
     */
    public static function payerDuring(\PDO $pdo, int $supplierId, string $from, string $to): bool
    {
        if (self::payerAt($pdo, $supplierId, $from)) {
            return true;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM supplier_vat_status_history
              WHERE supplier_id = ? AND effective_from > ? AND effective_from <= ?
                AND is_vat_payer = 1
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $from, $to]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Korelovaný SQL výraz "plátce k datu" pro použití uvnitř větších dotazů.
     *
     * Všechny tři argumenty jsou SQL fragmenty vkládané doslova — volající ručí,
     * že nejde o neošetřený uživatelský vstup (typicky inline int supplier_id,
     * název datového sloupce a fallback literál/sloupec).
     */
    public static function payerAtExpr(string $supplierIdSql, string $dateSql, string $fallbackSql): string
    {
        return "COALESCE((SELECT h.is_vat_payer FROM supplier_vat_status_history h
                 WHERE h.supplier_id = {$supplierIdSql} AND h.effective_from <= {$dateSql}
                 ORDER BY h.effective_from DESC, h.id DESC LIMIT 1), {$fallbackSql})";
    }

    /**
     * SQL varianta {@see payerDuring()}: plátce k prvnímu dni intervalu, nebo se jím
     * stal kdykoli během něj. Argumenty jsou SQL fragmenty jako u {@see payerAtExpr()}.
     */
    public static function payerDuringExpr(string $supplierIdSql, string $fromSql, string $toSql, string $fallbackSql): string
    {
        return '(' . self::payerAtExpr($supplierIdSql, $fromSql, $fallbackSql) . " = 1
                OR EXISTS (SELECT 1 FROM supplier_vat_status_history hd
                            WHERE hd.supplier_id = {$supplierIdSql} AND hd.effective_from > {$fromSql}
                              AND hd.effective_from <= {$toSql} AND hd.is_vat_payer = 1))";
    }

    /**
     * Baseline řádek historie pro nově založenou firmu (effective_from 1900-01-01).
     *
     * Statická, aby ji mohly volat i bin skripty bez DI kontejneru; idempotentní
     * přes INSERT IGNORE (UNIQUE supplier_id + effective_from).
     */
    public static function seedInitialStatus(\PDO $pdo, int $supplierId, bool $isVatPayer, bool $isIdentified = false): void
    {
        $pdo->prepare(
            'INSERT IGNORE INTO supplier_vat_status_history
                (supplier_id, effective_from, is_vat_payer, is_identified, annual_deduction_percent)
             VALUES (?, \'1900-01-01\', ?, ?, 100)'
        )->execute([$supplierId, $isVatPayer ? 1 : 0, (!$isVatPayer && $isIdentified) ? 1 : 0]);
    }

    /**
     * Jediná zápisová cesta do historie (VH-01) — sdílí ji legacy checkbox
     * v PUT /settings/supplier i správa historie (VatStatusHistoryAction).
     * Upsert po UNIQUE (supplier_id, effective_from).
     */
    public function upsert(
        int $supplierId,
        string $effectiveFrom,
        bool $isVatPayer,
        bool $isIdentified,
        ?string $note = null,
        ?int $userId = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history
                (supplier_id, effective_from, is_vat_payer, is_identified, note, annual_deduction_percent, created_by)
             VALUES (?, ?, ?, ?, ?, 100, ?)
             ON DUPLICATE KEY UPDATE
                is_vat_payer  = VALUES(is_vat_payer),
                is_identified = VALUES(is_identified),
                note          = VALUES(note),
                created_by    = VALUES(created_by)'
        )->execute([$supplierId, $effectiveFrom, $isVatPayer ? 1 : 0, $isIdentified ? 1 : 0, $note, $userId ?: null]);
    }

    /**
     * Přepočet živé cache supplier.is_vat_payer + is_identified z historie
     * k dnešku. Nemění nic, pokud firma nemá žádný řádek s effective_from <= dnes
     * (budoucí změny se do cache propíší až cronem v den účinnosti).
     */
    public function refreshLiveCache(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier s
               JOIN supplier_vat_status_history h ON h.supplier_id = s.id
                AND h.id = (
                    SELECT h2.id FROM supplier_vat_status_history h2
                     WHERE h2.supplier_id = s.id AND h2.effective_from <= CURRENT_DATE
                     ORDER BY h2.effective_from DESC, h2.id DESC LIMIT 1
                )
                SET s.is_vat_payer  = h.is_vat_payer,
                    s.is_identified = h.is_identified
              WHERE s.id = ?'
        )->execute([$supplierId]);
    }

    /**
     * Denní krok `vat-status-apply` (cron): propíše historii k dnešku do živé
     * cache VŠECH firem jediným set-based UPDATE. Idempotentní — mění jen řádky,
     * kde se cache liší; vrací počet upravených firem.
     *
     * Statická (vzor seedInitialStatus), aby šla volat z bin skriptu i testů
     * bez DI kontejneru.
     */
    public static function applyDueStatuses(\PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'UPDATE supplier s
               JOIN (
                   SELECT h.supplier_id, h.is_vat_payer, h.is_identified
                     FROM supplier_vat_status_history h
                     JOIN (
                         SELECT supplier_id, MAX(effective_from) AS max_from
                           FROM supplier_vat_status_history
                          WHERE effective_from <= CURRENT_DATE
                          GROUP BY supplier_id
                     ) m ON m.supplier_id = h.supplier_id AND m.max_from = h.effective_from
               ) cur ON cur.supplier_id = s.id
                SET s.is_vat_payer  = cur.is_vat_payer,
                    s.is_identified = cur.is_identified
              WHERE s.is_vat_payer <> cur.is_vat_payer OR s.is_identified <> cur.is_identified'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
