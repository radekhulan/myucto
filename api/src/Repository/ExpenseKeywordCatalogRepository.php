<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ExpenseKeywordCatalogRepository
{
    /** @var array<int,list<array<string,mixed>>> */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    public function latestVersion(): int
    {
        return (int) ($this->db->pdo()->query(
            'SELECT COALESCE(MAX(catalog_version), 0) FROM expense_keyword_catalog WHERE is_active = 1'
        )->fetchColumn() ?: 0);
    }

    /** @return list<array<string,mixed>> */
    public function active(?int $version = null): array
    {
        $version ??= $this->latestVersion();
        if ($version <= 0) {
            return [];
        }
        return $this->cache[$version] ??= (function () use ($version): array {
            $stmt = $this->db->pdo()->prepare(
                'SELECT locale, concept_key, phrase, polarity, confidence, expense_kind,
                        target_account_code, requires_review
                   FROM expense_keyword_catalog
                  WHERE catalog_version = ? AND is_active = 1
                  ORDER BY polarity DESC, CHAR_LENGTH(phrase) DESC, id ASC'
            );
            $stmt->execute([$version]);
            return array_map(static function (array $row): array {
                $row['confidence'] = (float) $row['confidence'];
                $row['requires_review'] = (bool) $row['requires_review'];
                return $row;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        })();
    }
}
