<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\CsszCertificateSerialNumber;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CertificateVaultUsage
{
    public function __construct(
        private readonly Connection $db,
        private readonly CertificateVaultSupplierSharing $sharing,
    ) {}

    /**
     * @return list<array{
     *     supplier_id:int,
     *     name:string,
     *     epo:'eligible'|'invalid'|'disabled',
     *     payroll:list<array{environment:string,registration:'missing'|'matches'|'mismatch'|'unknown'}>,
     *     databox:list<array{environment:string,box_id:string,source:'company'|'shared',operations:list<string>}>
     * }>|null
     */
    public function companies(Request $request, int $credentialId, int $ownerUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT serial_hex, valid_from, valid_to
               FROM epo_signing_credentials
              WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$credentialId, $ownerUserId]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($certificate === false) {
            return null;
        }

        $companies = [];
        foreach ($this->sharing->permittedSuppliers($request, $ownerUserId, AccessLevel::READ) as $supplier) {
            $companies[$supplier['supplier_id']] = [
                'supplier_id' => $supplier['supplier_id'],
                'name' => $supplier['name'],
                'epo' => 'disabled',
                'payroll' => [],
                'databox' => [],
            ];
        }
        if ($companies === []) {
            return [];
        }

        $supplierIds = array_keys($companies);
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $params = [$credentialId, ...$supplierIds];
        $now = time();
        $from = strtotime((string) $certificate['valid_from']);
        $to = strtotime((string) $certificate['valid_to']);
        $epo = $from !== false && $to !== false && $from <= $now && $to >= $now ? 'eligible' : 'invalid';
        foreach ($this->rows(
            "SELECT supplier_id FROM epo_signing_credential_suppliers
              WHERE credential_id = ? AND supplier_id IN ($placeholders)",
            $params,
        ) as $row) {
            $companies[(int) $row['supplier_id']]['epo'] = $epo;
        }

        $serial = (string) $certificate['serial_hex'];
        foreach ($this->rows(
            "SELECT supplier_id, environment, cssz_registered_serial
               FROM payroll_submission_signing_profiles
              WHERE credential_id = ? AND supplier_id IN ($placeholders) AND owner_user_id = ?
              ORDER BY environment",
            [...$params, $ownerUserId],
        ) as $row) {
            $registeredSerial = (string) $row['cssz_registered_serial'];
            $registration = match (true) {
                $registeredSerial === '' => 'missing',
                CsszCertificateSerialNumber::normalizeRegisteredInput($serial) === null => 'unknown',
                CsszCertificateSerialNumber::matches($serial, $registeredSerial) => 'matches',
                default => 'mismatch',
            };
            $companies[(int) $row['supplier_id']]['payroll'][] = [
                'environment' => (string) $row['environment'],
                'registration' => $registration,
            ];
        }

        foreach ($this->rows(
            "SELECT supplier_id, environment, box_id FROM submission_channel_credentials
              WHERE channel = 'isds' AND credential_id = ? AND supplier_id IN ($placeholders)
              ORDER BY environment",
            $params,
        ) as $row) {
            $companies[(int) $row['supplier_id']]['databox'][] = [
                'environment' => (string) $row['environment'],
                'box_id' => (string) $row['box_id'],
                'source' => 'company',
                'operations' => ['send', 'receive', 'receipts'],
            ];
        }

        if ($this->db->hasTable('isds_access_profiles') && $this->db->hasTable('isds_access_profile_suppliers')) {
            foreach ($this->rows(
                "SELECT s.supplier_id, p.environment, p.box_id, s.can_send, s.can_receive, s.can_receipts
                   FROM isds_access_profiles p
                   JOIN isds_access_profile_suppliers s ON s.profile_id = p.id
                  WHERE p.scope = 'shared' AND p.certificate_id = ? AND s.supplier_id IN ($placeholders)
                    AND p.owner_user_id = ?
                  ORDER BY p.environment, p.box_id, p.id",
                [...$params, $ownerUserId],
            ) as $row) {
                $operations = [];
                foreach (['send', 'receive', 'receipts'] as $operation) {
                    if ((bool) $row['can_' . $operation]) {
                        $operations[] = $operation;
                    }
                }
                $companies[(int) $row['supplier_id']]['databox'][] = [
                    'environment' => (string) $row['environment'],
                    'box_id' => (string) $row['box_id'],
                    'source' => 'shared',
                    'operations' => $operations,
                ];
            }
        }

        return array_values($companies);
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
