<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document\Delivery;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\PayrollProductionGate;

/**
 * Brána odchozí cesty k zaměstnanci.
 *
 * Výplatní páska se z aplikace neodešle, dokud NEPLATÍ VŠECH PĚT podmínek. Jsou
 * úmyslně nezávislé a každá je jinde, aby žádný jeden omyl — zapomenutá
 * konfigurace, špatný merge, přehlédnutý přepínač — nestačil k tomu, aby se
 * cizím lidem rozeslaly jejich výplatnice:
 *
 *   1. `payroll.secure_delivery.enabled` v konfiguraci instance (default FALSE).
 *      Vývojová instance běží nad ostrými daty, takže bez explicitního zapnutí
 *      se neodešle nic ani omylem.
 *   2. {@see PayrollProductionGate::assertActive()} — interní release brána
 *      (`PRODUCT_RELEASED = false`) plus dokončené nastavení firmy.
 *   3. Zaměstnavatelská politika `delivery_channel = 'employee_portal'` a
 *      potvrzené `delivery_verified_on`. Tenhle sloupec existuje od migrace 1276
 *      a znamená „zaměstnavatel si kanál ověřil a stojí si za ním".
 *   4. Souhlas konkrétní osoby: `payroll_employee_profiles.secure_delivery_channel
 *      = 'portal'`. Kdo má v kartě `paper`, tomu se nic neposílá — papír je jeho
 *      volba, ne výchozí stav, který by šlo tiše přebít hromadnou akcí.
 *   5. Osoba má aktivní primární e-mail. Řeší resolver, ne tahle třída.
 *
 * Body 1 a 2 se kontrolují i ve workeru těsně před odesláním, ne jen při zařazení
 * do fronty. Fronta může přežít restart i změnu konfigurace a nikdo nechce, aby
 * dávka zařazená ve chvíli, kdy byl kanál zapnutý, dojela po jeho vypnutí.
 */
final class PayrollSecureDeliveryPolicy
{
    public const CONFIG_KEY = 'payroll.secure_delivery.enabled';

    public function __construct(
        private readonly Config $config,
        private readonly PayrollProductionGate $productionGate,
        private readonly PayrollEmployerPolicyRepository $employerPolicies,
    ) {}

    /** Přepínač instance. Bez něj se neodesílá nic, ani z fronty. */
    public function isChannelEnabled(): bool
    {
        return (bool) $this->config->get(self::CONFIG_KEY, false);
    }

    public function linkTtlDays(): int
    {
        return max(1, min(90, (int) $this->config->get(
            'payroll.secure_delivery.link_ttl_days',
            30,
        )));
    }

    public function codeTtlSeconds(): int
    {
        return max(60, min(3600, (int) $this->config->get(
            'payroll.secure_delivery.code_ttl_seconds',
            600,
        )));
    }

    public function codeResendCooldownSeconds(): int
    {
        return max(15, min(600, (int) $this->config->get(
            'payroll.secure_delivery.code_resend_cooldown_seconds',
            60,
        )));
    }

    public function maxCodeAttempts(): int
    {
        return max(3, min(10, (int) $this->config->get(
            'payroll.secure_delivery.max_code_attempts',
            5,
        )));
    }

    /**
     * Relace je krátká vědomě. U výkazu práce vydrží dny, u výplatní pásky ne:
     * prohlížeč bývá sdílený a cena za znovuzadání kódu je jeden e-mail.
     */
    public function sessionTtlSeconds(): int
    {
        return max(300, min(43200, (int) $this->config->get(
            'payroll.secure_delivery.session_ttl_seconds',
            7200,
        )));
    }

    public function maxDispatchAttempts(): int
    {
        return max(1, min(10, (int) $this->config->get(
            'payroll.secure_delivery.max_dispatch_attempts',
            3,
        )));
    }

    /**
     * Podmínky 1–4. Volá se při zařazení do fronty i znovu ve workeru.
     *
     * @throws PayrollSecureDeliveryBlockedException
     */
    public function assertDispatchAllowed(int $supplierId, string $effectiveOn): void
    {
        if (!$this->isChannelEnabled()) {
            throw new PayrollSecureDeliveryBlockedException(
                'secure_delivery_disabled',
                'Odesílání mzdových dokumentů zaměstnancům není na této instanci zapnuté.',
            );
        }

        // Interní release brána. Nesmí být přeskočitelná konfigurací výše — proto
        // je až za ní a hází vlastní typ výjimky dál nahoru.
        $this->productionGate->assertActive($supplierId);

        $policy = $this->employerPolicies->findEffective($supplierId, $effectiveOn);
        if ($policy === null) {
            throw new PayrollSecureDeliveryBlockedException(
                'employer_policy_missing',
                'Pro toto období není nastavená zaměstnavatelská politika.',
            );
        }
        if ((string) ($policy['delivery_channel'] ?? '') !== 'employee_portal') {
            throw new PayrollSecureDeliveryBlockedException(
                'employer_channel_not_portal',
                'Zaměstnavatel nemá jako způsob předávání zvolený zabezpečený odkaz.',
            );
        }
        if (($policy['delivery_verified_on'] ?? null) === null) {
            throw new PayrollSecureDeliveryBlockedException(
                'employer_channel_unverified',
                'Způsob předávání dokumentů není potvrzený.',
            );
        }
    }

    /** Podmínka 5a — souhlas konkrétní osoby. */
    public function assertEmployeeOptedIn(?string $secureDeliveryChannel): void
    {
        if ($secureDeliveryChannel !== 'portal') {
            throw new PayrollSecureDeliveryBlockedException(
                'employee_prefers_paper',
                'Zaměstnanec má v kartě zvolené předání na papíře.',
            );
        }
    }
}
