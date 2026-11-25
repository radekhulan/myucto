<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\Oidc\ExternalIdentity;
use MyInvoice\Service\Auth\Oidc\ExternalIdentityRepository;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use MyInvoice\Service\Supplier\SupplierCreator;

final class MyeetCustomerProvisioner
{
    public const READ_ONLY_PERIOD_SECONDS = 30 * 86400;

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierCreator $suppliers,
        private readonly LicenseCapacityGate $capacity,
        private readonly PasswordHasher $passwords,
        private readonly PasswordSetupLinkIssuer $passwordSetup,
        private readonly ExternalIdentityRepository $identities,
    ) {}

    public function canRegister(string $email, string $ico, ?ExternalIdentity $identity, bool $saleManaged = false): bool
    {
        $account = $this->account($email, $identity);
        return $account !== false && !$this->companyExists($ico)
            && (!$saleManaged || $account === null || !$this->hasSubscription($account));
    }

    public function provision(array $order, ?ExternalIdentity $identity, bool $saleManaged = false): ?array
    {
        $hash = hash('sha256', json_encode($order, JSON_THROW_ON_ERROR));
        $id = $order['order_id'];
        $payment = $order['payment_id'];
        $company = $order['company'];
        $email = $order['email'];
        $pdo = $this->db->pdo();

        return $this->capacity->mutateSeats(function () use ($pdo, $id, $payment, $hash, $email, $company, $identity, $saleManaged): ?array {
            $stmt = $pdo->prepare('SELECT * FROM myeet_checkouts WHERE order_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if ($existing = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (!hash_equals($existing['payload_hash'], $hash)) {
                    throw new \InvalidArgumentException('Obsah potvrzené objednávky se změnil.');
                }
                return $existing;
            }

            $account = $this->account($email, $identity);
            if ($account === false || $this->companyExists($company['ico'])
                || ($saleManaged && $account !== null && $this->hasSubscription($account))) {
                return null;
            }

            $role = $pdo->query("SELECT id FROM roles WHERE system_key = 'admin' AND role_type = 'staff' AND is_active = 1")->fetchColumn();
            if ($role === false) {
                throw new \RuntimeException('Firemní administrátorská role není dostupná.');
            }
            if ($account === null) {
                $defaultRole = $pdo->query("SELECT id FROM roles WHERE system_key = 'readonly' AND role_type = 'staff' AND is_active = 1")->fetchColumn();
                if ($defaultRole === false) {
                    throw new \RuntimeException('Výchozí uživatelská role není dostupná.');
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
                     VALUES (?, ?, ?, 'readonly', ?, 'cs', 1)"
                );
                $name = trim((string) $identity?->name) ?: $company['name'];
                $stmt->execute([$email, $this->passwords->hash($this->passwordSetup->randomPassword()), $name, (int) $defaultRole]);
                $userId = (int) $pdo->lastInsertId();
                if ($identity !== null) {
                    $this->identities->link($userId, $identity, 'invite');
                }
            } else {
                $userId = $account;
            }

            $supplierId = $this->suppliers->create([
                'company_name' => $company['name'],
                'ic' => $company['ico'],
                'dic' => $company['dic'],
                'street' => $company['street'],
                'city' => $company['city'],
                'zip' => $company['zip'],
                'country_iso2' => 'CZ',
                'email' => $email,
            ], $userId, false);

            $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
                ->execute([$userId, $supplierId, (int) $role]);
            $pdo->prepare('INSERT INTO myeet_checkouts (order_id, payment_id, payload_hash, user_id, supplier_id, sale_managed) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $payment, $hash, $userId, $supplierId, (int) $saleManaged]);

            return ['order_id' => $id, 'user_id' => $userId, 'supplier_id' => $supplierId, 'invoice_id' => null, 'sale_managed' => $saleManaged];
        });
    }

    public function recordLicense(string $orderId, int $userId, int $supplierId, int $validUntil, int $version, ?int $readOnlyUntil = null): bool
    {
        $pdo = $this->db->pdo();
        $update = $pdo->prepare(
            'UPDATE myeet_checkouts SET license_valid_until = ?, license_readonly_until = ?, license_version = ?
             WHERE order_id = ? AND user_id = ? AND supplier_id = ? AND sale_managed = 1 AND license_version < ?'
        );
        $update->execute([$validUntil, $readOnlyUntil ?? 0, $version, $orderId, $userId, $supplierId, $version]);
        if ($update->rowCount() === 1) {
            return true;
        }
        $current = $pdo->prepare(
            'SELECT license_version, license_valid_until, license_readonly_until FROM myeet_checkouts
             WHERE order_id = ? AND user_id = ? AND supplier_id = ? AND sale_managed = 1'
        );
        $current->execute([$orderId, $userId, $supplierId]);
        $row = $current->fetch(\PDO::FETCH_ASSOC);
        return $row !== false && (
            ($readOnlyUntil === null && (int) $row['license_version'] > $version)
            || ((int) $row['license_version'] >= $version && (int) $row['license_valid_until'] === $validUntil
                && (int) $row['license_readonly_until'] === ($readOnlyUntil ?? 0))
        );
    }

    /** @return array{valid_until: int, readonly_until: int}|null */
    public function licenseAccess(int $userId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(license_valid_until) AS valid_until, MAX(license_readonly_until) AS readonly_until
             FROM myeet_checkouts WHERE user_id = ? AND supplier_id = ? AND sale_managed = 1
             GROUP BY user_id, supplier_id'
        );
        $stmt->execute([$userId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'valid_until' => (int) $row['valid_until'],
            'readonly_until' => (int) $row['readonly_until'],
        ];
    }

    public function recordInvoice(string $orderId, int $invoiceId): void
    {
        $this->db->pdo()->prepare('UPDATE myeet_checkouts SET invoice_id = ? WHERE order_id = ? AND invoice_id IS NULL')
            ->execute([$invoiceId, $orderId]);
    }

    private function account(string $email, ?ExternalIdentity $identity): int|false|null
    {
        $linked = $identity !== null ? $this->identities->findBySubject($identity) : null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.is_active, r.is_active AS role_active, r.role_type FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($account === false) {
            return $linked === null ? null : false;
        }
        if (!$account['is_active'] || !$account['role_active'] || $account['role_type'] !== 'staff'
            || (int) ($linked['user_id'] ?? 0) !== (int) $account['id']) {
            return false;
        }
        return (int) $account['id'];
    }

    private function hasSubscription(int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM myeet_checkouts WHERE user_id = ? AND sale_managed = 1 LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function companyExists(string $ico): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM supplier WHERE ic = ? LIMIT 1');
        $stmt->execute([$ico]);
        return $stmt->fetchColumn() !== false;
    }
}
