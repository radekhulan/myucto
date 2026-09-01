<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Obsah období se změnil, zatímco se z něj skládal export.
 *
 * Plán částí se zmrazí hned na začátku, aby archiv obsahoval přesně to, co
 * v tom okamžiku existovalo. Když mezitím přibude výplatní páska nebo podání,
 * další pokus najde jiný plán — a to je konec, ne zádrhel: opakovat se nemá
 * co, protože nový obsah už nikdy nebude odpovídat zmrazenému plánu.
 *
 * Zůstává podtypem UnexpectedValueException, protože o neočekávanou hodnotu
 * v plánu skutečně jde a volající, kteří na ni reagují, se tím nemění.
 *
 * Vlastní typ existuje proto, aby to fronta poznala a job **zavřela jako
 * neúspěšný**, místo aby ho třikrát zopakovala s týmž výsledkem. Uživatel
 * u toho jinak kouká na kolečko „Připravuji bezpečný ZIP…", které nikdy
 * neskončí, a přitom stačí přípravu spustit znovu.
 */
final class PayrollPeriodExportPlanChangedException extends \UnexpectedValueException
{
    public static function forJob(): self
    {
        return new self(
            'Obsah období se během přípravy exportu změnil (přibyl dokument '
            . 'nebo podání). Spusťte přípravu znovu — archiv se sestaví '
            . 'z aktuálního obsahu.',
        );
    }
}
