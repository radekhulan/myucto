<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemException;

/**
 * Nastavení ostatních pohledávek a závazků per firma (migrace 2249): šablona složky
 * v Dokumentech pro soubory nahrané k dokladu. {YYYY} a {MM} doplní klient z data
 * vystavení, server šablonu jen normalizuje a hlídá.
 */
final class OtherItemSettingsRepository
{
    public const DEFAULT_DOCUMENT_FOLDER = 'Ostatní pohledávky a závazky/{YYYY}/{MM}';
    private const MAX_LENGTH = 500;

    public function __construct(private readonly Connection $db) {}

    /** @return array{document_folder:string, document_folder_default:string, is_default:bool} */
    public function get(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_folder FROM other_item_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $folder = $stmt->fetchColumn();
        $folder = is_string($folder) && $folder !== '' ? $folder : null;
        return [
            'document_folder' => $folder ?? self::DEFAULT_DOCUMENT_FOLDER,
            'document_folder_default' => self::DEFAULT_DOCUMENT_FOLDER,
            'is_default' => $folder === null,
        ];
    }

    /** @return array{document_folder:string, document_folder_default:string, is_default:bool} */
    public function save(int $supplierId, ?string $documentFolder, ?int $userId): array
    {
        $folder = self::normalizeFolder($documentFolder);
        if ($folder === self::DEFAULT_DOCUMENT_FOLDER) $folder = null;
        $this->db->pdo()->prepare(
            'INSERT INTO other_item_settings (supplier_id, document_folder, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE document_folder = VALUES(document_folder), updated_by = VALUES(updated_by)'
        )->execute([$supplierId, $folder, $userId]);
        return $this->get($supplierId);
    }

    /** Prázdná hodnota = výchozí složka. Segmenty bez okrajových mezer, bez prázdných a bez „..". */
    public static function normalizeFolder(?string $folder): string
    {
        $folder = trim(str_replace('\\', '/', (string) $folder));
        if ($folder === '') return self::DEFAULT_DOCUMENT_FOLDER;
        $segments = [];
        foreach (explode('/', $folder) as $segment) {
            $segment = trim($segment);
            if ($segment === '') continue;
            if ($segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F]/u', $segment) === 1) {
                throw new OtherItemException('invalid_document_folder', 'Složka obsahuje nepovolený název.', 422);
            }
            $segments[] = $segment;
        }
        $normalized = implode('/', $segments);
        if ($normalized === '') return self::DEFAULT_DOCUMENT_FOLDER;
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new OtherItemException('invalid_document_folder', 'Cesta ke složce je delší než 500 znaků.', 422);
        }
        return $normalized;
    }
}
