<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\EshopException;

final class CatalogImportProfileStore
{
    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, version, config_json, updated_at FROM catalog_import_profiles WHERE supplier_id = ? ORDER BY name, id');
        $stmt->execute([$supplierId]);
        return array_map($this->cast(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function get(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, version, config_json, updated_at FROM catalog_import_profiles WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new EshopException('not_found', 'Profil importu nenalezen.', 404);
        }
        return $this->cast($row);
    }

    public function save(int $supplierId, ?int $id, ?int $version, string $name, array $config): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150 || ($id !== null && ($version ?? 0) < 1)) {
            throw new \InvalidArgumentException('import_profile_invalid');
        }
        $config = CatalogImportProfile::normalize($config);
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $pdo = $this->db->pdo();
        if ($id === null) {
            $pdo->prepare('INSERT INTO catalog_import_profiles (supplier_id, name, config_json) VALUES (?, ?, ?)')->execute([$supplierId, $name, $json]);
            return $this->get($supplierId, (int) $pdo->lastInsertId());
        }
        $current = $this->get($supplierId, $id);
        if ($current['version'] !== $version) {
            throw new EshopException('version_conflict', 'Profil importu byl změněn.', 409);
        }
        if ($current['name'] === $name && $current['config'] === $config) {
            return $current;
        }
        $stmt = $pdo->prepare('UPDATE catalog_import_profiles SET name = ?, config_json = ?, version = version + 1 WHERE supplier_id = ? AND id = ? AND version = ?');
        $stmt->execute([$name, $json, $supplierId, $id, $version]);
        if ($stmt->rowCount() !== 1) {
            throw new EshopException('version_conflict', 'Profil importu byl změněn.', 409);
        }
        return $this->get($supplierId, $id);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['config'] = json_decode($row['config_json'], true, 32, JSON_THROW_ON_ERROR);
        unset($row['config_json']);
        return $row;
    }
}
