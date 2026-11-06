<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Auth\SecretEncryption;

final class AbraConnectionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly AbraReadOnlyClient $client,
        private readonly AbraImportRepository $runs,
        private readonly ImportJobRepository $jobs,
    ) {}

    public function credentials(int $supplierId): array
    {
        $row = $this->row($supplierId);
        if ($row === null) throw new AbraException('not_configured', 'Nejprve nastavte připojení k ABRA Flexi.');
        try {
            if (!str_starts_with($row['credentials_enc'], 'enc:v2:')) throw new \RuntimeException();
            $credentials = json_decode($this->crypto->decryptFor($row['credentials_enc'], self::context($supplierId)), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($credentials)) throw new \RuntimeException();
            return $credentials;
        } catch (\Throwable) {
            throw new AbraException('credentials_unavailable', 'Uložené připojení nelze dešifrovat. Přihlašovací údaje zadejte znovu.');
        }
    }

    public function save(int $supplierId, int $userId, array $body): array
    {
        $credentials = [
            'url' => AbraReadOnlyClient::normalizeUrl((string) ($body['url'] ?? '')),
            'username' => trim((string) ($body['username'] ?? '')),
            'password' => (string) ($body['password'] ?? ''),
        ];
        if ($credentials['username'] === '' || $credentials['password'] === ''
            || strlen($credentials['username']) > 255 || strlen($credentials['password']) > 2048) {
            throw new AbraException('invalid_credentials', 'Vyplňte přihlašovací jméno a heslo ABRA Flexi.');
        }
        $row = $this->row($supplierId);
        $fingerprint = hash('sha256', $credentials['url']);
        if ($row !== null && !hash_equals($row['source_fingerprint'], $fingerprint)
            && ($row['imported_at'] !== null || $this->runs->hasImportedData($supplierId))) {
            throw new AbraException('source_locked', 'Po převodu nelze změnit zdrojovou účetní firmu. Připojení k původní firmě lze aktualizovat.', [], 409);
        }
        $discovery = $this->discoverCredentials($credentials);
        $encrypted = $this->crypto->encryptFor(json_encode($credentials, JSON_THROW_ON_ERROR), self::context($supplierId));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockSupplier($supplierId);
            $this->assertIdle($supplierId);
            $current = $this->row($supplierId);
            if ($current !== null && !hash_equals($current['source_fingerprint'], $fingerprint)
                && ($current['imported_at'] !== null || $this->runs->hasImportedData($supplierId))) {
                throw new AbraException('source_locked', 'Po převodu nelze změnit zdrojovou účetní firmu.', [], 409);
            }
            $pdo->prepare('INSERT INTO abra_flexi_connections (supplier_id, credentials_enc, source_fingerprint, discovery, updated_by)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials_enc = VALUES(credentials_enc),
                source_fingerprint = VALUES(source_fingerprint), discovery = VALUES(discovery),
                connection_version = connection_version + 1, updated_by = VALUES(updated_by)')->execute([
                    $supplierId, $encrypted, $fingerprint, json_encode($discovery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $userId,
                ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->status($supplierId);
    }

    public function discover(int $supplierId): array
    {
        $row = $this->row($supplierId);
        if ($row === null) throw new AbraException('not_configured', 'Nejprve nastavte připojení k ABRA Flexi.');
        $discovery = $this->discoverCredentials($this->credentials($supplierId));
        $this->db->pdo()->prepare('UPDATE abra_flexi_connections SET discovery = ? WHERE supplier_id = ? AND connection_version = ?')
            ->execute([json_encode($discovery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $supplierId, $row['connection_version']]);
        return $this->status($supplierId);
    }

    public function status(int $supplierId): array
    {
        $row = $this->row($supplierId);
        $discovery = $row === null ? [] : self::decode($row['discovery']);
        $syncState = $row === null ? [] : self::decode($row['sync_state']);
        $years = $discovery['years'] ?? [];
        $now = (int) date('Y');
        $available = array_column($years, 'year');
        $defaults = array_values(array_intersect([$now - 1, $now], $available));
        $selectedYears = $row === null ? [] : self::decode($row['selected_years']);
        $discoveryWarnings = array_values(array_filter($discovery['warnings'] ?? [],
            static function (mixed $warning) use ($selectedYears): bool {
                if (!is_string($warning)) return false;
                if (preg_match('/^Rok (\d{4}) obsahuje více zdrojových účetních období\./u', $warning, $match) !== 1) return true;
                return in_array((int) $match[1], $selectedYears, true);
            }));
        $jobs = $this->jobs->listForTenant($supplierId, 'abra_flexi_import', 20);
        $active = null;
        foreach ($jobs as $job) {
            if (in_array($job['status'], ['queued', 'running'], true)) { $active = (int) $job['id']; break; }
        }
        $target = $this->targetCompany($supplierId);
        return [
            'configured' => $row !== null,
            'imported' => $row !== null && $row['imported_at'] !== null,
            'years' => $years,
            'default_years' => $defaults,
            'selected_years' => $selectedYears,
            'last_synced_at' => $row['last_synced_at'] ?? null,
            'active_job_id' => $active,
            'warnings' => array_slice(array_values(array_unique(array_merge($discoveryWarnings, $syncState['warnings'] ?? []))), 0, 50),
            'source_company' => $discovery['source_company'] ?? null,
            'target_company' => $target,
            'ico_mismatch' => $row !== null && !AbraSource::sameIco($discovery['source_company']['ico'] ?? '', $target['ico']),
            'connection_version' => (int) ($row['connection_version'] ?? 0),
            'sync_state' => $syncState,
            'catalog' => $syncState['addons']['catalog'] ?? null,
        ];
    }

    public function markSynced(int $supplierId, array $years, array $syncState): void
    {
        $previous = $this->row($supplierId);
        $addons = $previous === null ? [] : (self::decode($previous['sync_state'])['addons'] ?? []);
        if ($addons !== []) $syncState['addons'] = $addons;
        $this->db->pdo()->prepare('UPDATE abra_flexi_connections SET selected_years = ?, sync_state = ?,
            imported_at = COALESCE(imported_at, NOW()), last_synced_at = NOW() WHERE supplier_id = ?')->execute([
                json_encode(array_values($years), JSON_THROW_ON_ERROR), json_encode($syncState, JSON_THROW_ON_ERROR), $supplierId,
            ]);
    }

    public function markCatalogSynced(int $supplierId, int $sourceRows): void
    {
        $row = $this->row($supplierId);
        if ($row === null || $row['imported_at'] === null) throw new AbraException('invalid_state', 'Nejprve dokončete účetní převod.');
        $state = self::decode($row['sync_state']);
        $state['addons']['catalog'] = ['last_synced_at' => gmdate('c'), 'source_rows' => $sourceRows];
        $this->db->pdo()->prepare('UPDATE abra_flexi_connections SET sync_state = ? WHERE supplier_id = ?')
            ->execute([json_encode($state, JSON_THROW_ON_ERROR), $supplierId]);
    }

    public function delete(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockSupplier($supplierId);
            $this->assertIdle($supplierId);
            if ($this->runs->hasImportedData($supplierId)) throw new AbraException('source_locked', 'Připojení k již převzatým datům nelze odstranit.', [], 409);
            $row = $this->row($supplierId);
            if ($row !== null && $row['imported_at'] !== null) throw new AbraException('source_locked', 'Připojení po dokončeném převodu nelze odstranit.', [], 409);
            $pdo->prepare('DELETE FROM abra_flexi_connections WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function assertIdle(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM import_jobs WHERE supplier_id = ? AND source = "abra_flexi_import"
            AND status IN ("queued", "running") LIMIT 1');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() !== false || !$this->runs->isLockFree($supplierId)) {
            throw new AbraException('already_running', 'Převod této firmy právě běží.', [], 409);
        }
    }

    public function lockSupplier(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) throw new AbraException('supplier_not_found', 'Účetní firma nebyla nalezena.', [], 404);
    }

    private function discoverCredentials(array $credentials): array
    {
        $periods = $this->client->list($credentials, 'ucetni-obdobi');
        $years = [];
        $warnings = [];
        foreach ($periods as $period) {
            $start = substr((string) ($period['platiOdData'] ?? ''), 0, 10);
            $end = substr((string) ($period['platiDoData'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end) || $start > $end) {
                throw new AbraException('invalid_period', 'Zdrojové účetní období nemá platné vymezení.');
            }
            $year = AbraSource::periodYear($period) ?? (int) substr($start, 0, 4);
            if (isset($years[$year])) {
                $warnings[] = sprintf('Rok %d obsahuje více zdrojových účetních období. Jeho převod vyžaduje kontrolu vymezení období.', $year);
                $start = min($start, $years[$year]['starts_on']);
                $end = max($end, $years[$year]['ends_on']);
            }
            $years[$year] = ['year' => $year, 'starts_on' => $start, 'ends_on' => $end];
        }
        ksort($years);
        $versions = $this->client->get($credentials, 'nastaveni')['winstrom']['nastaveni'] ?? [];
        $settings = [];
        $today = date('Y-m-d');
        foreach ($versions as $version) {
            $validFrom = substr((string) ($version['platiOdData'] ?? ''), 0, 10);
            if ($validFrom <= $today && $validFrom >= (string) ($settings['platiOdData'] ?? '')) $settings = $version;
        }
        $catalog = $this->client->get($credentials, 'evidence-list')['evidences'] ?? [];
        $company = ['name' => (string) ($settings['nazFirmy'] ?? $settings['nazevFirmy'] ?? $settings['nazev'] ?? $catalog['companyName'] ?? ''),
            'ico' => preg_replace('/\D/', '', (string) ($settings['ic'] ?? '')),
            'base_currency' => strtoupper(preg_replace('/^code:/', '', (string) ($settings['mena'] ?? '')))];
        if ($company['ico'] === '') $warnings[] = 'Zdroj neposkytl IČO firmy. Před převodem je nutné ověřit identitu účetní jednotky.';
        return ['years' => array_values($years), 'source_company' => $company, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @return array{name:string,ico:string} */
    private function targetCompany(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['name' => (string) ($row['company_name'] ?? ''), 'ico' => (string) ($row['ic'] ?? '')];
    }

    private function row(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM abra_flexi_connections WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    private static function context(int $supplierId): string { return 'abra-flexi:connection:' . $supplierId; }
    private static function decode(?string $json): array
    {
        $decoded = $json !== null ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
