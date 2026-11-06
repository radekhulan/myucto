<?php

declare(strict_types=1);

namespace MyInvoice\Service\Config;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Tajemství, která smí vzniknout JEN při úplně prvním zřízení instalace.
 *
 * `app.payroll_hash_key` je klíč keyed hashů mzdových identifikátorů (rodné
 * číslo, číslo dokladu). Když chybí, {@see \MyInvoice\Service\Payroll\Security\PayrollSensitiveData}
 * ho odvozuje z `app.pepper`. Samostatný klíč je lepší, protože únik pepperu
 * pak neodhalí otisky, ALE na instalaci, která už nějaké otisky uložila, se
 * klíč měnit nesmí: všechny uložené otisky by přestaly odpovídat a dohledání
 * osob podle rodného čísla by tiše selhávalo.
 *
 * Proto se klíč generuje pouze ve chvíli, kdy instalace prokazatelně nemá
 * žádná data (setup wizard a `api/bin/setup.php` to ověřují nad databází),
 * a nikdy ve spravované instalaci, kde konfiguraci vlastní provisioning.
 */
final class FreshInstallSecrets
{
    public const KEY_PAYROLL_HASH = 'app.payroll_hash_key';

    /**
     * Klíče k zápisu do `cfg.local.php` pro čerstvou instalaci. Prázdné pole,
     * když je klíč už nastavený (cfg.php, cfg.local.php nebo ENV).
     *
     * @return array<string,string>
     */
    public static function forFreshInstall(Config $config): array
    {
        $configured = $config->get(self::KEY_PAYROLL_HASH, '');
        if (is_string($configured) && trim($configured) !== '') {
            return [];
        }
        if ($configured !== null && !is_string($configured)) {
            // Neplatný typ nechává hlásit PayrollSensitiveData; nic nepřepisujeme.
            return [];
        }

        return [self::KEY_PAYROLL_HASH => base64_encode(random_bytes(32))];
    }
}
