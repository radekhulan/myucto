<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document\Delivery;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Komu se smí odkaz poslat.
 *
 * Adresa se bere výhradně z `payroll_person_contacts` — z jediného kontaktu typu
 * `email`, který je `is_active` a `is_primary`. Když jich je víc nebo žádný,
 * resolver NEHÁDÁ a odmítne: „poslal jsem to na jednu ze dvou adres" je u výplatní
 * pásky horší než „neposlal jsem nic".
 *
 * Plaintext se dešifruje jen v {@see plaintextEmail()}, tedy až ve workeru
 * v okamžiku sestavení zprávy. Všechno ostatní — fronta, evidence, API, log —
 * pracuje jen s otiskem a maskovanou podobou.
 */
final class PayrollDeliveryRecipientResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * @return array{
     *   employee_id:int,
     *   contact_id:int,
     *   email_hash:string,
     *   masked:string,
     *   ciphertext:string,
     *   secure_delivery_channel:?string
     * }
     * @throws PayrollSecureDeliveryBlockedException
     */
    public function resolve(int $supplierId, int $employeeId): array
    {
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException('Identita příjemce není platná.');
        }

        $channelStatement = $this->db->pdo()->prepare(
            'SELECT secure_delivery_channel
               FROM payroll_employee_profiles
              WHERE supplier_id = ? AND employee_id = ?',
        );
        $channelStatement->execute([$supplierId, $employeeId]);
        $channelRow = $channelStatement->fetch(PDO::FETCH_ASSOC);
        $channel = $channelRow === false || $channelRow['secure_delivery_channel'] === null
            ? null
            : (string) $channelRow['secure_delivery_channel'];

        $statement = $this->db->pdo()->prepare(
            'SELECT id, contact_value_ciphertext, contact_value_hash, contact_value_masked
               FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ?
                AND contact_type = "email" AND is_active = 1 AND is_primary = 1
              ORDER BY id',
        );
        $statement->execute([$supplierId, $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            throw new PayrollSecureDeliveryBlockedException(
                'recipient_email_missing',
                'Zaměstnanec nemá v kartě aktivní primární e-mail.',
            );
        }
        if (count($rows) > 1) {
            throw new PayrollSecureDeliveryBlockedException(
                'recipient_email_ambiguous',
                'Zaměstnanec má víc primárních e-mailů; ponechte právě jeden.',
            );
        }

        $row = $rows[0];
        return [
            'employee_id' => $employeeId,
            'contact_id' => (int) $row['id'],
            'email_hash' => (string) $row['contact_value_hash'],
            'masked' => (string) $row['contact_value_masked'],
            'ciphertext' => (string) $row['contact_value_ciphertext'],
            'secure_delivery_channel' => $channel,
        ];
    }

    /**
     * Dešifruje adresu a ZÁROVEŇ ověří, že pořád odpovídá otisku uloženému
     * v odkazu při zařazení do fronty.
     *
     * To je hlavní pojistka proti přesměrování výplatnice: kdo mezi zařazením
     * a odesláním přepíše osobě e-mail, nedostane pásku na novou adresu — odeslání
     * selže a účetní musí odkaz zařadit znovu, tentokrát vědomě na tu novou.
     *
     * @throws PayrollSecureDeliveryBlockedException
     */
    public function plaintextEmail(
        int $supplierId,
        int $employeeId,
        string $expectedEmailHashBinary,
    ): string {
        $recipient = $this->resolve($supplierId, $employeeId);
        if (!hash_equals($expectedEmailHashBinary, $recipient['email_hash'])) {
            throw new PayrollSecureDeliveryBlockedException(
                'recipient_email_changed',
                'E-mail zaměstnance se od zařazení do fronty změnil; odeslání zastaveno.',
            );
        }

        $email = $this->sensitiveData->reveal(
            $recipient['ciphertext'],
            PayrollSensitiveField::CONTACT_EMAIL,
            $supplierId,
            $employeeId,
            PayrollRevealPurpose::DOCUMENT_SECURE_DELIVERY,
        );
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new PayrollSecureDeliveryBlockedException(
                'recipient_email_invalid',
                'Uložený e-mail zaměstnance není platná adresa.',
            );
        }
        return $email;
    }
}
