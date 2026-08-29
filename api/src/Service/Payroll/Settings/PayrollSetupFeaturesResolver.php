<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\SupportMatrix;

final class PayrollSetupFeaturesResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployerPolicyRepository $policies,
        private readonly SupportMatrix $supportMatrix,
    ) {}

    public function resolve(
        int $supplierId,
        string $effectiveOn,
    ): PayrollSetupFeatures {
        $policy = $this->policies->findEffective(
            $supplierId,
            $effectiveOn,
        );
        $sourceBlockers = [];
        $jmhzAvailable = $this->featureAvailability('jmhz_export');
        if ($jmhzAvailable === null) {
            // Tohle je jediný legitimní blokátor zdroje: matice funkcí se
            // nedá přečíst, takže o dostupnosti JMHZ nevíme nic.
            $sourceBlockers['jmhz_feature_source'] =
                'Support matrix neobsahuje ověřitelnou dostupnost JMHZ.';
        }

        return new PayrollSetupFeatures(
            homeOffice: $policy !== null
                && $this->string($policy, 'home_office_policy') !== 'not_used',
            travelExpenses: $policy !== null
                && $this->string(
                    $policy,
                    'travel_expense_policy',
                ) !== 'not_used',
            automaticPosting: $this->bool(
                $policy,
                'automatic_posting_enabled',
            ),
            secureDelivery: $policy !== null
                && $this->string($policy, 'delivery_channel') !== 'disabled',
            // Měsíční hlášení se týká každého zaměstnavatele ze zákona, ne až
            // po zapnutí přepínače. Per-firemní „aktivace" proto neexistuje
            // a nikdy neexistovala — dřívější blokátor o chybějícím tenantovém
            // zdroji byl vývojářská poznámka, která se omylem dostala před
            // uživatele a četla se jako chyba konfigurace.
            jmhz: $jmhzAvailable === true,
            activeApproverCount: $this->activeApproverCount($supplierId),
            jmhzRegistryReady: $this->hasEmployerRegistrationNumber($supplierId),
            jmhzCertificateReady: $this->hasUsableSigningCertificate(
                $supplierId,
                $effectiveOn,
            ),
            sourceBlockers: $sourceBlockers,
        );
    }

    /**
     * Registrační číslo přidělené ČSSZ. Bez něj se hlášení nespáruje se
     * zaměstnavatelem, takže je to skutečná podmínka, ne formalita.
     */
    private function hasEmployerRegistrationNumber(int $supplierId): bool
    {
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT employer_registration_number
               FROM payroll_employer_settings
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Zvolený podpisový certifikát pro PRODUKČNÍ podání, a ještě platný.
     *
     * Testovací volba se sem záměrně nepočítá: hlášení se podává do ostrého
     * prostředí a tvrdit „připraveno" kvůli testovacímu certifikátu by byla
     * přesně ta lež, kterou má tahle kontrola odhalovat. Stejně tak prošlý
     * certifikát — ten přestane fungovat v den vypršení, ne až si toho někdo
     * všimne.
     */
    private function hasUsableSigningCertificate(
        int $supplierId,
        string $effectiveOn,
    ): bool {
        if (!$this->db->hasTable('payroll_submission_signing_profiles')) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_submission_signing_profiles profile
               JOIN epo_signing_credentials credential
                 ON credential.id = profile.credential_id
                AND credential.deleted_at IS NULL
              WHERE profile.supplier_id = ?
                AND profile.environment = "production"
                AND (credential.valid_from IS NULL OR credential.valid_from <= ?)
                AND (credential.valid_to IS NULL OR credential.valid_to >= ?)',
        );
        $statement->execute([$supplierId, $effectiveOn, $effectiveOn]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function featureAvailability(string $key): ?bool
    {
        foreach ($this->supportMatrix->all()['features'] as $feature) {
            if ($feature['key'] === $key) {
                return $feature['available'];
            }
        }

        return null;
    }

    private function activeApproverCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT candidate.user_id)
               FROM (
                 SELECT users.id AS user_id,
                        COALESCE(membership.role_id, users.role_id) AS role_id
                   FROM users
                   JOIN user_suppliers membership
                     ON membership.user_id = users.id
                    AND membership.supplier_id = ?
                  WHERE users.is_active = 1
                 UNION ALL
                 SELECT users.id AS user_id, users.role_id
                   FROM users
                   JOIN roles global_role
                     ON global_role.id = users.role_id
                    AND global_role.system_key = "superadmin"
                    AND global_role.is_active = 1
                  WHERE users.is_active = 1
               ) candidate
               JOIN roles effective_role
                 ON effective_role.id = candidate.role_id
                AND effective_role.is_active = 1
          LEFT JOIN role_permissions permission
                 ON permission.role_id = effective_role.id
                AND permission.permission_key = "payroll.approve"
                AND permission.access_level >= 2
              WHERE effective_role.system_key = "superadmin"
                 OR (
                   effective_role.role_type = "staff"
                   AND permission.role_id IS NOT NULL
                 )',
        );
        $stmt->execute([$supplierId]);
        $count = $stmt->fetchColumn();
        if (!is_int($count) && !is_string($count)) {
            throw new \UnexpectedValueException(
                'Počet aktivních schvalovatelů nelze ověřit.',
            );
        }

        return (int) $count;
    }

    /** @param array<string,mixed>|null $policy */
    private function string(?array $policy, string $field): string
    {
        $value = $policy[$field] ?? null;
        return is_string($value) ? $value : '';
    }

    /** @param array<string,mixed>|null $policy */
    private function bool(?array $policy, string $field): bool
    {
        return ($policy[$field] ?? null) === true;
    }
}
