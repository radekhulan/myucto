<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Povolení certifikátu z trezoru i v dalších firmách uživatele.
 *
 * Certifikát se NEKOPÍRUJE. Trezor je osobní (`epo_signing_credentials.owner_user_id`),
 * soukromý klíč i heslo v něm leží zašifrované jednou, a firma k němu dostává jen
 * přístup řádkem v `epo_signing_credential_suppliers`. Přesně ten řádek si vyžádá
 * podpis ({@see EpoSigningCredentialRepository::findUsable()}), takže hromadné
 * povolení je totéž, co by uživatel udělal ručně v každé firmě zvlášť.
 *
 * Cílové firmy se odvozují výhradně na serveru: členství uživatele v `user_suppliers`
 * a pro každou firmu znovu vyhodnocená role (per-firemní override, aktivita, typ) se
 * stejným oprávněním, jaké hlídá stránka, tedy `settings.signing` pro zápis. Ani
 * superadmin se nerozšiřuje na firmy, kde není členem: hromadná akce nemá dosáhnout
 * dál než výběr firem, který uživatel sám vidí. Výjimkou je superadmin bez jediného
 * členství: v přepínači vidí všechny firmy, a kdyby se bral jen `user_suppliers`,
 * tlačítko by u něj tiše nepovolilo nic. Firma, do které se resoluce přístupu
 * nedostane (vlastní doména zamčená na jinou firmu), se ve výsledku vůbec neukáže.
 */
final class CertificateVaultSupplierSharing
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_ALREADY_ENABLED = 'already_enabled';
    public const STATUS_SKIPPED_HAS_VALID = 'skipped_has_valid';
    public const STATUS_SKIPPED_NO_PERMISSION = 'skipped_no_permission';
    public const STATUS_REPLACED = 'replaced';

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $permissions,
        private readonly EpoSigningCredentialRepository $credentials,
        private readonly ActivityLogger $logger,
        private readonly PersonalCertificateVaultService $vault,
    ) {}

    /**
     * Nahradí starý certifikát novým ve všech firmách, kam hromadná akce dosáhne,
     * včetně firmy, ze které se spouští.
     *
     * Na rozdíl od prostého povolení se nic nepřeskakuje kvůli „už má platný
     * certifikát": nový certifikát je právě náhrada toho platného. Ve firmě se
     * nový povolí a místa, která používala starý, se přepojí na nový: přístup
     * k datové schránce, osobní podpisové profily a volba pro mzdová podání.
     * Odesílací brána ISDS je instalačně globální a registrovaná v ISDS, proto
     * se tu nemění. Starý certifikát zůstává v trezoru i povolený; smazat ho jde
     * ručně, jakmile ho nic nepoužívá.
     *
     * Registrace u ČSSZ (`cssz_registered_serial`) se záměrně nepřepisuje: patří
     * starému certifikátu a obrazovka mezd pak správně ohlásí, že nový certifikát
     * u ČSSZ registrovaný není.
     *
     * Dříve podepsaná PDF se nepřegenerují: podpis platil v okamžiku podpisu
     * a vystavené dokumenty se nemají měnit jen proto, že přišel nový certifikát.
     *
     * @return list<array{supplier_id:int,name:string,status:string,replaced:array{data_box:int,signing_profiles:int,payroll:int}}>|null
     *         null = některý z certifikátů v trezoru uživatele není
     * @throws \InvalidArgumentException nový je týž jako starý, nebo už neplatí
     */
    public function replaceInSuppliers(
        Request $request,
        int $credentialId,
        int $replacedCredentialId,
        int $ownerUserId,
        int $sourceSupplierId,
        ?string $ip = null,
    ): ?array {
        if ($credentialId === $replacedCredentialId) {
            throw new \InvalidArgumentException('Certifikát nelze nahradit sám sebou.');
        }
        $credential = $this->credentials->findOwned($credentialId, $ownerUserId);
        $replaced = $this->credentials->findOwned($replacedCredentialId, $ownerUserId);
        if ($credential === null || $replaced === null) {
            return null;
        }
        // Čas z PHP, ne NOW(): platnost se ukládá i vyhodnocuje v časové zóně PHP.
        if ((string) ($credential['valid_to'] ?? '') < date('Y-m-d H:i:s')) {
            throw new \InvalidArgumentException('Prošlým certifikátem nelze nahrazovat.');
        }

        $results = [];
        $enabled = [];
        $metadata = null;
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($this->candidateSuppliers($request, $ownerUserId) as $supplier) {
                $supplierId = $supplier['supplier_id'];
                $permitted = $this->permittedFor($request, $supplierId);
                if ($permitted === null) {
                    continue;
                }
                $counts = ['data_box' => 0, 'signing_profiles' => 0, 'payroll' => 0];
                if (!$permitted) {
                    $results[] = [
                        'supplier_id' => $supplierId,
                        'name' => $supplier['name'],
                        'status' => self::STATUS_SKIPPED_NO_PERMISSION,
                        'replaced' => $counts,
                    ];
                    continue;
                }
                $wasEnabled = $this->credentials->isEnabledForSupplier($credentialId, $supplierId);
                if (!$wasEnabled) {
                    if (!$this->credentials->setSupplierEnabled(
                        $credentialId,
                        $ownerUserId,
                        $supplierId,
                        true,
                        $ownerUserId,
                    )) {
                        throw new \RuntimeException('Credential supplier mapping failed.');
                    }
                    $enabled[] = $supplierId;
                }
                $counts = $this->rebind(
                    $supplierId,
                    $replacedCredentialId,
                    $credentialId,
                    $ownerUserId,
                    $metadata,
                );
                $results[] = [
                    'supplier_id' => $supplierId,
                    'name' => $supplier['name'],
                    'status' => array_sum($counts) > 0
                        ? self::STATUS_REPLACED
                        : ($wasEnabled ? self::STATUS_ALREADY_ENABLED : self::STATUS_ENABLED),
                    'replaced' => $counts,
                ];
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        foreach ($enabled as $supplierId) {
            $this->logger->log(
                'certificate_vault_supplier_enabled',
                $ownerUserId,
                'certificate',
                $credentialId,
                [
                    'source_supplier_id' => $sourceSupplierId,
                    'fingerprint' => (string) $credential['fingerprint_sha256'],
                ],
                $ip,
                $userAgent,
                $supplierId,
            );
        }
        foreach ($results as $row) {
            if ($row['status'] !== self::STATUS_REPLACED) {
                continue;
            }
            $this->logger->log(
                'certificate_vault_replaced',
                $ownerUserId,
                'certificate',
                $credentialId,
                [
                    'source_supplier_id' => $sourceSupplierId,
                    'replaced_credential_id' => $replacedCredentialId,
                    'replaced_fingerprint' => (string) $replaced['fingerprint_sha256'],
                    'fingerprint' => (string) $credential['fingerprint_sha256'],
                    'replaced' => $row['replaced'],
                ],
                $ip,
                $userAgent,
                $row['supplier_id'],
            );
        }

        return $results;
    }

    /**
     * Přepojí místa jedné firmy ze starého certifikátu na nový.
     *
     * Podpisový profil drží vedle vazby i veřejná metadata certifikátu (kvůli
     * výběru S/MIME podle e-mailu a zobrazení platnosti), proto se u něj
     * přepisují spolu s vazbou. Metadata se čtou z trezoru jen jednou za akci.
     *
     * @param array<string,mixed>|null $metadata
     * @return array{data_box:int,signing_profiles:int,payroll:int}
     */
    private function rebind(
        int $supplierId,
        int $fromCredentialId,
        int $toCredentialId,
        int $ownerUserId,
        ?array &$metadata,
    ): array {
        $pdo = $this->db->pdo();
        $counts = ['data_box' => 0, 'signing_profiles' => 0, 'payroll' => 0];

        if ($this->db->hasTable('submission_channel_credentials')) {
            // Přihlášení novým certifikátem ještě neproběhlo, ověření tedy neplatí.
            $stmt = $pdo->prepare(
                'UPDATE submission_channel_credentials
                    SET credential_id = ?, last_verified_at = NULL
                  WHERE supplier_id = ? AND credential_id = ?'
            );
            $stmt->execute([$toCredentialId, $supplierId, $fromCredentialId]);
            $counts['data_box'] = $stmt->rowCount();
        }

        if ($this->db->hasTable('payroll_submission_signing_profiles')) {
            $stmt = $pdo->prepare(
                'UPDATE payroll_submission_signing_profiles
                    SET credential_id = ?, owner_user_id = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND credential_id = ?'
            );
            $stmt->execute([$toCredentialId, $ownerUserId, $supplierId, $fromCredentialId]);
            $counts['payroll'] = $stmt->rowCount();
        }

        $profiles = $pdo->prepare(
            'SELECT sc.profile_id
               FROM signing_credentials sc
               JOIN signing_profiles sp ON sp.id = sc.profile_id
              WHERE sp.supplier_id = ? AND sc.vault_credential_id = ?
                AND sc.deleted_at IS NULL AND sp.deleted_at IS NULL'
        );
        $profiles->execute([$supplierId, $fromCredentialId]);
        $profileIds = array_map('intval', $profiles->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if ($profileIds !== []) {
            if ($metadata === null) {
                $resolved = $this->vault->resolve($toCredentialId, $ownerUserId, $supplierId);
                $metadata = [
                    'certificate_fingerprint' => $resolved['certificate_fingerprint'],
                    'certificate_subject' => $resolved['certificate_subject'],
                    'certificate_email' => $resolved['certificate_email'],
                    'certificate_valid_from' => $resolved['certificate_valid_from'],
                    'certificate_valid_to' => $resolved['certificate_valid_to'],
                    'certificate_usage_json' => json_encode(
                        $resolved['certificate_usage'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                ];
            }
            $update = $pdo->prepare(
                'UPDATE signing_credentials
                    SET vault_credential_id = ?, certificate_fingerprint = ?, certificate_subject = ?,
                        certificate_email = ?, certificate_valid_from = ?, certificate_valid_to = ?,
                        certificate_usage_json = ?
                  WHERE profile_id = ? AND vault_credential_id = ? AND deleted_at IS NULL'
            );
            foreach ($profileIds as $profileId) {
                $update->execute([
                    $toCredentialId,
                    $metadata['certificate_fingerprint'],
                    $metadata['certificate_subject'],
                    $metadata['certificate_email'],
                    $metadata['certificate_valid_from'],
                    $metadata['certificate_valid_to'],
                    $metadata['certificate_usage_json'],
                    $profileId,
                    $fromCredentialId,
                ]);
                $counts['signing_profiles'] += $update->rowCount();
            }
        }

        return $counts;
    }

    /**
     * @return list<array{supplier_id:int,name:string,status:string}>|null
     *         null = certifikát v trezoru uživatele není
     */
    public function shareWithOtherSuppliers(
        Request $request,
        int $credentialId,
        int $ownerUserId,
        int $sourceSupplierId,
        bool $onlyWithoutValidCertificate,
        ?string $ip = null,
    ): ?array {
        $credential = $this->credentials->findOwned($credentialId, $ownerUserId);
        if ($credential === null) {
            return null;
        }

        $results = [];
        $enabled = [];
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($this->candidateSuppliers($request, $ownerUserId) as $membership) {
                $supplierId = $membership['supplier_id'];
                if ($supplierId === $sourceSupplierId) {
                    continue;
                }
                $permitted = $this->permittedFor($request, $supplierId);
                if ($permitted === null) {
                    continue;
                }
                $status = match (true) {
                    !$permitted => self::STATUS_SKIPPED_NO_PERMISSION,
                    $this->credentials->isEnabledForSupplier($credentialId, $supplierId)
                        => self::STATUS_ALREADY_ENABLED,
                    $onlyWithoutValidCertificate && $this->credentials->hasOtherValidEnabledForSupplier(
                        $ownerUserId,
                        $supplierId,
                        $credentialId,
                    ) => self::STATUS_SKIPPED_HAS_VALID,
                    default => self::STATUS_ENABLED,
                };
                if ($status === self::STATUS_ENABLED) {
                    if (!$this->credentials->setSupplierEnabled(
                        $credentialId,
                        $ownerUserId,
                        $supplierId,
                        true,
                        $ownerUserId,
                    )) {
                        throw new \RuntimeException('Credential supplier mapping failed.');
                    }
                    $enabled[] = $supplierId;
                }
                $results[] = [
                    'supplier_id' => $supplierId,
                    'name' => $membership['name'],
                    'status' => $status,
                ];
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($enabled as $supplierId) {
            $this->logger->log(
                'certificate_vault_supplier_enabled',
                $ownerUserId,
                'certificate',
                $credentialId,
                [
                    'source_supplier_id' => $sourceSupplierId,
                    'fingerprint' => (string) $credential['fingerprint_sha256'],
                ],
                $ip,
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return $results;
    }

    /**
     * Povolí certifikát v jedné výslovně určené firmě, typicky právě založené.
     *
     * Cíl se nebere z {@see candidateSuppliers()}: superadmin s jiným členstvím
     * řádek `user_suppliers` k nové firmě nedostane, a přesto do ní certifikát
     * převzít má. Oprávnění `settings.signing` se ale ověří stejně jako u hromadné
     * akce, rolí přepnutou do cílové firmy.
     *
     * @return self::STATUS_ENABLED|self::STATUS_ALREADY_ENABLED|self::STATUS_SKIPPED_NO_PERMISSION|null
     *         null = certifikát v trezoru uživatele není
     */
    public function enableInSupplier(
        Request $request,
        int $credentialId,
        int $ownerUserId,
        int $supplierId,
        ?string $ip = null,
    ): ?string {
        $credential = $this->credentials->findOwned($credentialId, $ownerUserId);
        if ($credential === null) {
            return null;
        }
        if ($this->permittedFor($request, $supplierId) !== true) {
            return self::STATUS_SKIPPED_NO_PERMISSION;
        }
        if ($this->credentials->isEnabledForSupplier($credentialId, $supplierId)) {
            return self::STATUS_ALREADY_ENABLED;
        }
        if (!$this->credentials->setSupplierEnabled($credentialId, $ownerUserId, $supplierId, true, $ownerUserId)) {
            throw new \RuntimeException('Credential supplier mapping failed.');
        }
        $this->logger->log(
            'certificate_vault_supplier_enabled',
            $ownerUserId,
            'certificate',
            $credentialId,
            [
                'source' => 'supplier_carry_over',
                'fingerprint' => (string) $credential['fingerprint_sha256'],
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return self::STATUS_ENABLED;
    }

    /** @return list<array{supplier_id:int,name:string}> */
    private function candidateSuppliers(Request $request, int $ownerUserId): array
    {
        $memberships = $this->memberships->listForUser($ownerUserId);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ($memberships !== [] || ($user['is_superadmin'] ?? false) !== true) {
            return $memberships;
        }

        $rows = $this->db->pdo()->query(
            'SELECT id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name FROM supplier ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => ['supplier_id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows,
        );
    }

    /**
     * true = smí, false = je členem, ale chybí mu oprávnění, null = firma
     * v tomhle kontextu vůbec není dosažitelná (a nemá se ani jmenovat).
     */
    public function permittedFor(Request $request, int $supplierId): ?bool
    {
        $scoped = $request
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId)
            ->withQueryParams([]);
        $access = $this->supplierAccess->resolve($scoped);
        if ($access->denied || $access->supplierId !== $supplierId) {
            return null;
        }
        $role = $this->permissions->resolve($scoped);
        $scoped = $scoped
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);

        return $role->isActive
            && !$role->isClientType()
            && RequestAuthorization::allows($scoped, 'settings.signing', AccessLevel::WRITE);
    }
}
