<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Eet\Certificate\EetCertificateInspector;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;

/**
 * Úložiště pokladních certifikátů EET jednotlivých firem.
 *
 * PKCS#12 i heslo se ukládají jen přes `SecretEncryption::encryptFor()`
 * s kontextem firmy, otisku a účelu; starý formát bez kontextu se při čtení
 * odmítne. Ven (JSON, log, audit) jdou jen metadata z {@see listMetadata()}.
 * Lokální deaktivace není revokace u CA — certifikát jen přestane být nabízen
 * k podpisu.
 */
final class EetCertificateVault implements EetCredentialProvider
{
    private const CIPHER_PREFIX = 'enc:v2:';
    private const WARNING_DAYS = 30;
    private const CRITICAL_DAYS = 7;

    private const METADATA_COLUMNS = 'id, environment, eic, subject_cn, serial_number, fingerprint_sha256, issuer,
        not_before, not_after, label, status, uploaded_by, created_at, retired_at';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
        private readonly EetCertificateInspector $inspector,
    ) {}

    /**
     * Ověří a uloží certifikát. Vrací bezpečná metadata.
     *
     * @return array<string,mixed>
     */
    public function import(
        int $supplierId,
        string $environment,
        #[\SensitiveParameter] string $pkcs12,
        #[\SensitiveParameter] string $password,
        ?string $label,
        ?int $userId,
    ): array {
        if (!in_array($environment, EetIdentifiers::ENVIRONMENTS, true)) {
            throw EetException::validation('Neznámé prostředí EET. Povolené hodnoty: playground, production.', ['field' => 'environment']);
        }
        $label = $label === null ? null : trim($label);
        if ($label !== null && mb_strlen($label) > 120) {
            throw EetException::validation('Popisek certifikátu může mít nejvýš 120 znaků.', ['field' => 'label']);
        }
        if ($this->secrets->validateKey() !== null) {
            throw new EetException('eet_encryption_unavailable', 'Šifrovací klíč aplikace není nastavený, certifikát nelze bezpečně uložit.', 503);
        }

        $now = new \DateTimeImmutable();
        $inspected = $this->inspector->inspect($pkcs12, $password, $now);
        if ($inspected->environment !== $environment) {
            throw new EetException(
                'eet_certificate_environment',
                $inspected->environment === 'playground'
                    ? 'Certifikát je z testovacího prostředí Playground, nelze ho použít pro ostrou evidenci.'
                    : 'Certifikát je produkční, pro prostředí Playground použijte testovací certifikát.',
                422,
            );
        }
        $settingsEic = $this->settingsEic($supplierId);
        if ($settingsEic !== null && $settingsEic !== $inspected->eic) {
            throw new EetException(
                'eet_certificate_eic_mismatch',
                sprintf('Certifikát patří poplatníkovi %s, v nastavení EET je DIČ %s.', $inspected->eic, $settingsEic),
                422,
            );
        }

        $existing = $this->db->pdo()->prepare('SELECT id FROM eet_certificates WHERE supplier_id = ? AND fingerprint_sha256 = ?');
        $existing->execute([$supplierId, $inspected->fingerprintSha256]);
        if ($existing->fetchColumn() !== false) {
            throw new EetException('eet_certificate_duplicate', 'Tento certifikát už je u firmy nahraný.', 409);
        }

        $p12Encrypted = $this->secrets->encryptFor($inspected->pkcs12, self::context($supplierId, $inspected->fingerprintSha256, 'p12'));
        $passwordEncrypted = $this->secrets->encryptFor($password, self::context($supplierId, $inspected->fingerprintSha256, 'password'));

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO eet_certificates
                (supplier_id, environment, eic, subject_cn, serial_number, fingerprint_sha256, issuer,
                 not_before, not_after, p12_enc, password_enc, label, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        );
        $insert->execute([
            $supplierId,
            $inspected->environment,
            $inspected->eic,
            $inspected->subjectCn,
            $inspected->serialNumber,
            $inspected->fingerprintSha256,
            $inspected->issuer,
            self::dbTime($inspected->notBefore),
            self::dbTime($inspected->notAfter),
            $p12Encrypted,
            $passwordEncrypted,
            $label === '' ? null : $label,
            $userId,
        ]);

        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Uložený certifikát se nepodařilo načíst.');
    }

    /**
     * Nejnovější platný aktivní certifikát firmy pro prostředí (a případně EIČ),
     * odemčený pro podpis. `null`, když žádný není.
     */
    public function activeCredential(int $supplierId, EetEnvironment $env, ?string $eic = null): ?EetActiveCredential
    {
        $row = $this->activeRow($supplierId, $env->value, $eic, true);
        if ($row === null) {
            return null;
        }
        $fingerprint = (string) $row['fingerprint_sha256'];
        $p12Stored = (string) $row['p12_enc'];
        $passwordStored = (string) $row['password_enc'];
        if (!str_starts_with($p12Stored, self::CIPHER_PREFIX) || !str_starts_with($passwordStored, self::CIPHER_PREFIX)) {
            throw new EetException('eet_certificate_unavailable', 'Uložený certifikát EET není v platném šifrovaném formátu. Nahrajte ho znovu.', 503);
        }
        try {
            $pkcs12 = $this->secrets->decryptFor($p12Stored, self::context($supplierId, $fingerprint, 'p12'));
            $password = $this->secrets->decryptFor($passwordStored, self::context($supplierId, $fingerprint, 'password'));
        } catch (\RuntimeException) {
            // Typicky obnovená záloha bez šifrovacího klíče: certifikát je nefunkční
            // a musí se nahrát znovu, platby se nesmí tvářit, že se podepíší.
            throw new EetException('eet_certificate_unavailable', 'Uložený certifikát EET nejde odemknout (chybí šifrovací klíč). Nahrajte certifikát znovu.', 503);
        }

        return new EetActiveCredential((int) $row['id'], EetSigningCredential::fromPkcs12($pkcs12, $password));
    }

    /**
     * Metadata aktivního platného certifikátu bez odemykání klíče (pro capabilities
     * a překážky aktivace).
     *
     * @return array<string,mixed>|null
     */
    public function activeMetadata(int $supplierId, string $environment, ?string $eic = null): ?array
    {
        $row = $this->activeRow($supplierId, $environment, $eic, false);
        return $row === null ? null : self::present($row);
    }

    /** @return list<array<string,mixed>> */
    public function listMetadata(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::METADATA_COLUMNS . ' FROM eet_certificates
              WHERE supplier_id = ?
              ORDER BY (status = \'active\') DESC, not_after DESC, id DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::present(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::METADATA_COLUMNS . ' FROM eet_certificates WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::present($row) : null;
    }

    /** Místní vyřazení certifikátu z podepisování (ne revokace u CA). Opakované volání nic nemění. */
    public function deactivate(int $supplierId, int $id, ?int $userId): void
    {
        if ($this->find($supplierId, $id) === null) {
            throw EetException::notFound('Certifikát nebyl nalezen.');
        }
        $this->db->pdo()->prepare(
            "UPDATE eet_certificates SET status = 'retired', retired_at = ?
              WHERE supplier_id = ? AND id = ? AND status = 'active'"
        )->execute([self::dbTime(new \DateTimeImmutable()), $supplierId, $id]);
    }

    public static function context(int $supplierId, string $fingerprint, string $purpose): string
    {
        return sprintf('eet:supplier:%d:certificate:%s:%s', $supplierId, $fingerprint, $purpose);
    }

    /** @return array<string,mixed>|null */
    private function activeRow(int $supplierId, string $environment, ?string $eic, bool $withSecrets): ?array
    {
        $now = self::dbTime(new \DateTimeImmutable());
        $columns = self::METADATA_COLUMNS . ($withSecrets ? ', p12_enc, password_enc' : '');
        $sql = 'SELECT ' . $columns . ' FROM eet_certificates
                 WHERE supplier_id = ? AND environment = ? AND status = \'active\'
                   AND not_before <= ? AND not_after > ?';
        $params = [$supplierId, $environment, $now, $now];
        if ($eic !== null) {
            $sql .= ' AND eic = ?';
            $params[] = $eic;
        }
        $sql .= ' ORDER BY not_before DESC, id DESC LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function settingsEic(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT eic FROM eet_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $eic = $stmt->fetchColumn();
        return is_string($eic) && $eic !== '' ? $eic : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $now = new \DateTimeImmutable('now', $tz);
        $notBefore = new \DateTimeImmutable((string) $row['not_before'], $tz);
        $notAfter = new \DateTimeImmutable((string) $row['not_after'], $tz);
        $seconds = $notAfter->getTimestamp() - $now->getTimestamp();
        $expiresInDays = (int) floor($seconds / 86400);
        $validNow = $notBefore <= $now && $notAfter > $now;
        $active = (string) $row['status'] === 'active';

        return [
            'id' => (int) $row['id'],
            'environment' => (string) $row['environment'],
            'eic' => (string) $row['eic'],
            'subject_cn' => $row['subject_cn'] === null ? null : (string) $row['subject_cn'],
            'serial_number' => $row['serial_number'] === null ? null : (string) $row['serial_number'],
            'fingerprint_sha256' => (string) $row['fingerprint_sha256'],
            'issuer' => $row['issuer'] === null ? null : (string) $row['issuer'],
            'not_before' => $notBefore->format(DATE_ATOM),
            'not_after' => $notAfter->format(DATE_ATOM),
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'status' => (string) $row['status'],
            'uploaded_by' => $row['uploaded_by'] === null ? null : (int) $row['uploaded_by'],
            'created_at' => $row['created_at'] === null ? null : (new \DateTimeImmutable((string) $row['created_at'], $tz))->format(DATE_ATOM),
            'retired_at' => $row['retired_at'] === null ? null : (new \DateTimeImmutable((string) $row['retired_at'], $tz))->format(DATE_ATOM),
            'valid_now' => $validNow,
            'expires_in_days' => $expiresInDays,
            'expiry_warning' => !$active ? null : match (true) {
                $seconds <= 0 => 'expired',
                $expiresInDays < self::CRITICAL_DAYS => 'critical',
                $expiresInDays < self::WARNING_DAYS => 'warning',
                default => null,
            },
        ];
    }

    private static function dbTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
