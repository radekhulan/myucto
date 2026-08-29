<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;

final class PayrollSetupCheckService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployerPolicyRepository $policies,
    ) {}

    /**
     * @return array{
     *   ready:bool,
     *   effective_on:string,
     *   policy_id:?int,
     *   checks:list<array{
     *     code:string,
     *     status:string,
     *     message:string
     *   }>,
     *   blockers:list<string>
     * }
     */
    public function check(
        int $supplierId,
        string $effectiveOn,
        PayrollSetupFeatures $features,
    ): array {
        $this->assertDate($effectiveOn);
        $checks = [];
        $blockers = [];

        foreach ($features->sourceBlockers as $code => $message) {
            $this->addCheck(
                $checks,
                $blockers,
                $code,
                false,
                $message,
            );
        }

        $settingsReady = $this->hasEmployerSettings($supplierId);
        $this->addCheck(
            $checks,
            $blockers,
            'employer_settings',
            $settingsReady,
            $settingsReady
                ? 'Profil zaměstnavatele a aktivní účtárna jsou připravené.'
                : 'Chybí profil zaměstnavatele nebo aktivní výchozí účtárna.',
        );

        $policy = $this->policies->findEffective(
            $supplierId,
            $effectiveOn,
        );
        $this->addCheck(
            $checks,
            $blockers,
            'effective_policy',
            $policy !== null,
            $policy !== null
                ? 'Pro zvolené datum existuje právě jedna účinná politika.'
                : 'Pro zvolené datum chybí účinná zaměstnavatelská politika.',
        );

        if ($features->homeOffice) {
            $ready = ($policy['home_office_policy'] ?? null) === 'configured';
            $this->addCheck(
                $checks,
                $blockers,
                'home_office_policy',
                $ready,
                $ready
                    ? 'Home-office politika je nakonfigurovaná.'
                    : 'Zapnutý home office vyžaduje dokončenou politiku.',
            );
        }
        if ($features->travelExpenses) {
            $ready = ($policy['travel_expense_policy'] ?? null) === 'configured';
            $this->addCheck(
                $checks,
                $blockers,
                'travel_expense_policy',
                $ready,
                $ready
                    ? 'Cestovní politika je nakonfigurovaná.'
                    : 'Zapnuté cestovní náhrady vyžadují dokončenou politiku.',
            );
        }
        $this->featureFlagCheck(
            $checks,
            $blockers,
            $features->automaticPosting,
            $policy,
            'automatic_posting_enabled',
            'automatic_posting',
            'Automatické zaúčtování',
        );

        if ($features->secureDelivery) {
            $channel = $policy['delivery_channel'] ?? null;
            $verifiedOn = $policy['delivery_verified_on'] ?? null;
            $ready = is_string($channel)
                && in_array(
                    $channel,
                    ['employee_portal', 'smime_email', 'manual_handover'],
                    true,
                )
                && is_string($verifiedOn)
                && $verifiedOn <= $effectiveOn;
            $this->addCheck(
                $checks,
                $blockers,
                'secure_delivery',
                $ready,
                $ready
                    ? 'Bezpečný kanál doručení je ověřený.'
                    : 'Bezpečné doručení vyžaduje ověřený kanál účinný k datu kontroly.',
            );
        }
        if ($features->jmhz) {
            $this->addCheck(
                $checks,
                $blockers,
                'jmhz_registry',
                $features->jmhzRegistryReady,
                $features->jmhzRegistryReady
                    ? 'Registrační číslo zaměstnavatele u ČSSZ je vyplněné.'
                    : 'Vyplňte registrační číslo zaměstnavatele přidělené ČSSZ'
                        . ' (Mzdy → Podání → Registrace zaměstnavatele). Bez něj'
                        . ' se hlášení nespáruje se zaměstnavatelem.',
            );
            $this->addCheck(
                $checks,
                $blockers,
                'jmhz_certificate',
                $features->jmhzCertificateReady,
                $features->jmhzCertificateReady
                    ? 'Podpisový certifikát pro produkční podání je zvolený a platný.'
                    : 'Zvolte podpisový certifikát pro produkční prostředí'
                        . ' (Mzdy → Podání → Certifikát). Testovací volba ani prošlý'
                        . ' certifikát se nepočítají — hlášení by ČSSZ odmítla.',
                // Nezablokuje nastavení: produkční endpoint VREP zatím není
                // doložený, takže se z aplikace ostře stejně podat nedá.
                // Vynucovat certifikát dřív, než k něčemu bude, znamená držet
                // firmu v nepřipraveném stavu za něco, co jí teď nic nepřinese.
                blocking: false,
            );
        }

        return [
            'ready' => $blockers === [],
            'effective_on' => $effectiveOn,
            'policy_id' => isset($policy['id']) && is_int($policy['id'])
                ? $policy['id']
                : null,
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    private function hasEmployerSettings(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employer_settings settings
               JOIN payroll_offices office
                 ON office.supplier_id = settings.supplier_id
                AND office.id = settings.default_office_id
                AND office.is_active = 1
              WHERE settings.supplier_id = ?
              LIMIT 1',
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param list<array{code:string,status:string,message:string}> $checks
     * @param list<string> $blockers
     * @param array<string,mixed>|null $policy
     */
    private function featureFlagCheck(
        array &$checks,
        array &$blockers,
        bool $required,
        ?array $policy,
        string $field,
        string $code,
        string $label,
    ): void {
        if (!$required) {
            return;
        }
        $ready = ($policy[$field] ?? false) === true;
        $this->addCheck(
            $checks,
            $blockers,
            $code,
            $ready,
            $ready
                ? "{$label} je povolené politikou."
                : "{$label} je zapnuté, ale zaměstnavatelská politika je nepovoluje.",
        );
    }

    /**
     * @param list<array{code:string,status:string,message:string}> $checks
     * @param list<string> $blockers
     */
    /**
     * @param bool $blocking false u kontroly, která má být VIDĚT, ale nesmí
     *     zastavit nastavení. Blokovat se smí jen to, co uživatel může splnit
     *     a co mu splnění k něčemu bude — jinak se z panelu stane trvale
     *     červená cedule, kterou přestane číst.
     */
    private function addCheck(
        array &$checks,
        array &$blockers,
        string $code,
        bool $ready,
        string $message,
        bool $blocking = true,
    ): void {
        $checks[] = [
            'code' => $code,
            'status' => $ready ? 'ok' : ($blocking ? 'blocked' : 'pending'),
            'message' => $message,
        ];
        if (!$ready && $blocking) {
            $blockers[] = $code;
        }
    }

    private function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                'Datum setup checku musí mít formát YYYY-MM-DD.',
            );
        }
    }
}
