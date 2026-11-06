<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\People;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sloupce E-mail a Telefon v seznamu zaměstnanců.
 *
 * Kontakty jsou šifrované a karta osoby je ukazuje jen maskované. Celé hodnoty
 * smí vidět jen ten, kdo smí odhalit citlivé údaje osoby
 * (`payroll.person.read_sensitive`), a jen v přihlášené relaci. Ostatním se
 * sloupce v seznamu vůbec nenabízejí a server jim kontakty nevrátí, ani když
 * o ně požádají.
 *
 * Každé zobrazení stránky s kontakty se zapíše do auditu se seznamem osob,
 * stejně jako jednotlivé odhalení na kartě. Na rozdíl od něj se tu neptá na
 * důvod: sloupec si uživatel zapnul sám a ptát se na každou stránku by sloupec
 * znehodnotilo.
 */
final class PayrollPeopleContactColumns
{
    public const PERMISSION = 'payroll.person.read_sensitive';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly ActivityLogger $activity,
    ) {}

    public static function allowed(Request $request): bool
    {
        return RequestAuthorization::isSessionAuth($request)
            && RequestAuthorization::allows($request, self::PERMISSION, AccessLevel::READ);
    }

    /**
     * Hlavní aktivní e-mail a telefon osob stránky — jeden dotaz na celou stránku.
     * Hodnota, jejíž otisk nesedí s dešifrovaným obsahem, se vynechá: jeden
     * poškozený záznam nesmí shodit celý seznam.
     *
     * @param list<int> $employeeIds
     * @return array<int,array{email:?string,phone:?string}>
     */
    public function forPage(Request $request, int $supplierId, array $employeeIds, ?string $ip, ?string $userAgent): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if (!self::allowed($request) || $employeeIds === []) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, contact_type, contact_value_ciphertext, contact_value_hash
               FROM payroll_person_contacts
              WHERE supplier_id = ?
                AND employee_id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ")
                AND is_active = 1
                AND contact_type IN ('email', 'phone')
              ORDER BY employee_id ASC, is_primary DESC, id ASC",
        );
        $stmt->execute([$supplierId, ...$employeeIds]);

        $out = [];
        $revealed = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $employeeId = (int) $row['employee_id'];
            $type = (string) $row['contact_type'];
            $out[$employeeId] ??= ['email' => null, 'phone' => null];
            if ($out[$employeeId][$type] !== null) {
                continue;
            }
            $value = $this->reveal(
                (string) $row['contact_value_ciphertext'],
                (string) $row['contact_value_hash'],
                $type === 'email' ? PayrollSensitiveField::CONTACT_EMAIL : PayrollSensitiveField::CONTACT_PHONE,
                $supplierId,
                (int) $row['id'],
            );
            if ($value !== null) {
                $out[$employeeId][$type] = $value;
                $revealed[$employeeId] = true;
            }
        }

        if ($revealed !== []) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $this->activity->log(
                action: 'payroll.person_contacts.listed',
                userId: (int) ($user['id'] ?? 0) ?: null,
                entityType: 'payroll_employee',
                payload: ['employee_ids' => array_keys($revealed)],
                ip: $ip,
                userAgent: $userAgent,
                supplierId: $supplierId,
            );
        }

        return $out;
    }

    private function reveal(
        string $ciphertext,
        string $expectedHash,
        PayrollSensitiveField $field,
        int $supplierId,
        int $contactId,
    ): ?string {
        if ($ciphertext === '' || $expectedHash === '') {
            return null;
        }
        try {
            $plaintext = $this->sensitiveData->reveal(
                $ciphertext,
                $field,
                $supplierId,
                $contactId,
                PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
            );
        } catch (\RuntimeException) {
            return null;
        }

        return hash_equals($expectedHash, $this->sensitiveData->lookupHash($plaintext, $field, $supplierId))
            ? $plaintext
            : null;
    }
}
