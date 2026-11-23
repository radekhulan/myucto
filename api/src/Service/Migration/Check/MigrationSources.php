<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Z jakých programů firma převzala data: dokončený převod (POHODA, Money S3, ABRA Flexi,
 * PREMIER) nebo mapa převodu (Stereo NX, Helios). Kontroly podle toho rozhodují, zda se
 * firmy týkají ({@see MigrationDataCheck::appliesTo()}).
 */
final class MigrationSources
{
    public const POHODA = 'pohoda';
    public const MONEY_S3 = 'money_s3';
    public const ABRA = 'abra_flexi';
    public const PREMIER = 'premier';
    public const STEREO_NX = 'stereo_nx';
    public const HELIOS = 'helios';

    /** Zdroj => tabulka dokončených převodů. */
    private const RUN_TABLES = [
        self::POHODA => 'pohoda_imports',
        self::MONEY_S3 => 'money_s3_imports',
        self::ABRA => 'abra_flexi_imports',
        self::PREMIER => 'premier_imports',
    ];

    /** Zdroj => mapa převodu (převody bez tabulky běhů). */
    private const MAP_TABLES = [
        self::STEREO_NX => 'stereo_nx_import_map',
        self::HELIOS => 'helios_import_map',
    ];

    /** @var array<int,list<string>> */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    /** @return list<string> zdroje převodu firmy */
    public function of(int $supplierId): array
    {
        if (isset($this->cache[$supplierId])) {
            return $this->cache[$supplierId];
        }
        $parts = [];
        $params = [];
        foreach (self::RUN_TABLES as $source => $table) {
            $parts[] = "SELECT '{$source}' FROM {$table} WHERE supplier_id = ? AND mode = 'import' AND status IN ('completed', 'completed_with_warnings') LIMIT 1";
            $params[] = $supplierId;
        }
        foreach (self::MAP_TABLES as $source => $table) {
            $parts[] = "SELECT '{$source}' FROM {$table} WHERE supplier_id = ? LIMIT 1";
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare('(' . implode(') UNION ALL (', $parts) . ')');
        $stmt->execute($params);
        return $this->cache[$supplierId] = array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @return list<int> firmy s převodem z kteréhokoli zdroje */
    public function suppliers(): array
    {
        $parts = [];
        foreach (self::RUN_TABLES as $table) {
            $parts[] = "SELECT supplier_id FROM {$table} WHERE mode = 'import' AND status IN ('completed', 'completed_with_warnings')";
        }
        foreach (self::MAP_TABLES as $table) {
            $parts[] = "SELECT DISTINCT supplier_id FROM {$table}";
        }
        $ids = $this->db->pdo()->query('SELECT DISTINCT s.id FROM supplier s JOIN (' . implode(' UNION ', $parts) . ') m ON m.supplier_id = s.id ORDER BY s.id')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $ids);
    }

    public function has(int $supplierId, string $source): bool
    {
        return in_array($source, $this->of($supplierId), true);
    }

    /** @param list<string> $sources */
    public function any(int $supplierId, array $sources): bool
    {
        return array_intersect($sources, $this->of($supplierId)) !== [];
    }

    /**
     * Konec posledního převedeného roku ze zdroje s rokem agendy (POHODA, ABRA Flexi, PREMIER):
     * konec účetního období roku agendy posledního dokončeného převodu. Úhrady a zůstatky
     * po něm vede už MyÚčto.
     */
    public function agendaEnd(int $supplierId, string $source): ?string
    {
        if (!in_array($source, [self::POHODA, self::ABRA, self::PREMIER], true) || !$this->has($supplierId, $source)) {
            return null;
        }
        $table = self::RUN_TABLES[$source];
        $stmt = $this->db->pdo()->prepare("SELECT MAX(ap.ends_on) FROM accounting_periods ap
              JOIN (SELECT MAX(agenda_year) AS y FROM {$table}
                     WHERE supplier_id = ? AND mode = 'import' AND status IN ('completed', 'completed_with_warnings')) r ON r.y = ap.fiscal_year
             WHERE ap.supplier_id = ?");
        $stmt->execute([$supplierId, $supplierId]);
        $end = $stmt->fetchColumn();
        return is_string($end) && $end !== '' ? substr($end, 0, 10) : null;
    }

    /** Zapomene zjištěné zdroje (test, dlouhý proces přes víc firem). */
    public function reset(): void
    {
        $this->cache = [];
    }
}
