<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

/**
 * Kdo nese odpovědnost za odbornou správnost DODANÝCH legislativních sad
 * v TÉTO instalaci.
 *
 * ── Proč to není konstanta s naším jménem ─────────────────────────────────────
 * Do 8/2026 měla dodaná sada `approval = null` a v komentáři stálo, že formální
 * záznam o tom, KDO za hodnoty ručí, zatím nevzniká. Zadavatel rozhodl, že
 * schvalovatelem je firma, která instalaci provozuje — u nás MyWebdesign.cz s.r.o.
 *
 * Napsat to jméno natvrdo do rulesetu by ale byla chyba v úrovni abstrakce.
 * Schvalovatel je vlastnost INSTALACE, ne produktu: tentýž kód nasadí jiný
 * provozovatel (hosting, partnerská účetní firma, zákazník on-premise) a v jeho
 * instalaci musí být uvedený ON, protože odpovědnost za dodané sazby nese vůči
 * svým uživatelům on. Jméno je proto konfigurace s výchozí hodnotou, ne literál
 * rozstrkaný po jedenácti verzích rulesetu.
 *
 * ── Kde se nastavuje ──────────────────────────────────────────────────────────
 *   cfg.php / cfg.local.php:  ['payroll' => ['ruleset' => ['approver' => '…']]]
 *   ENV:                      MYINVOICE_PAYROLL_RULESET_APPROVER
 *   výchozí hodnota:          {@see DEFAULT_NAME}
 *
 * `Bootstrap::buildContainer()` předá hodnotu z konfigurace do {@see configure()}.
 * Statická cesta je tu proto, že {@see CzechPayrollRulesets2026::provider()} je
 * statická tovární metoda volaná i mimo kontejner (CLI, testy, fixtury); vstřikovat
 * do ní kontejner by znamenalo přepsat každé její volání. Bez volání `configure()`
 * (tj. v testech a v CLI bez bootstrapu) se čte ENV a pak výchozí hodnota, takže
 * sada je vždy podepsaná — nikdy ne prázdná.
 *
 * ── Co to dělá s otisky ───────────────────────────────────────────────────────
 * Schválení je součástí PLNÉHO snapshotu verze ({@see PayrollRulesetVersion::$canonicalHash}),
 * ale NENÍ součástí otisku OBSAHU ({@see PayrollRulesetContent}). Jiný
 * provozovatel tedy má jiné `canonical_hash`, ale TÝŽ `content_hash` — a protože
 * dodanou sadu pozná {@see VendorRulesetManifest} právě podle otisku obsahu,
 * změna schvalovatele nemůže sadu připravit o status dodané sady ani ji shodit
 * na „účinná bez schválení". To je záměr, ne shoda náhod: integritní pin
 * {@see CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH} je proto vedený
 * nad obsahem, ne nad plným snapshotem.
 */
final class VendorRulesetApprover
{
    /**
     * Výchozí schvalovatel = provozovatel téhle instalace.
     *
     * Není to „autor aplikace" ani „dodavatel software" — je to subjekt, který
     * uživatelům odpovídá za to, že dodané sazby jsou správné. U nás jsou to
     * shodou okolností titíž lidé, u jiného provozovatele nebudou.
     */
    public const DEFAULT_NAME = 'MyWebdesign.cz s.r.o.';

    public const CONFIG_KEY = 'payroll.ruleset.approver';

    public const ENV_NAME = 'MYINVOICE_PAYROLL_RULESET_APPROVER';

    /**
     * Datum, ke kterému provozovatel dodanou sadu podepsal. Je to KONSTANTA, ne
     * „dnes": schválení je vlastnost konkrétní dodané sady a musí být bajtově
     * stabilní, jinak by se otisk plného snapshotu měnil každý den.
     */
    public const APPROVED_ON = '2026-08-17';

    /**
     * Datum podpisu ročníku 2025, doplněného zpětně.
     *
     * Vlastní konstanta, protože {@see APPROVED_ON} je starší než den, kdy se
     * zdroje pro rok 2025 stahovaly — a {@see RulesetApproval} (správně) trvá na
     * tom, že schválení nesmí předcházet technické kontrole. Přepsat společné
     * datum nešlo: je součástí plného snapshotu ročníku 2026 a jeho posunutí by
     * změnilo `canonical_hash` každé jeho verze.
     */
    public const APPROVED_ON_2025 = '2026-08-28';

    private static ?string $configured = null;

    /**
     * Nastaví schvalovatele z konfigurace instalace. `null` nebo prázdná hodnota
     * znamená „neurčeno" a vrací se k ENV, případně k výchozí hodnotě.
     */
    public static function configure(?string $name): void
    {
        self::$configured = self::normalize($name);
    }

    /** Jen pro testy — vrátí rozlišení zpět na ENV / výchozí hodnotu. */
    public static function reset(): void
    {
        self::$configured = null;
    }

    public static function name(): string
    {
        if (self::$configured !== null) {
            return self::$configured;
        }

        $env = getenv(self::ENV_NAME);

        return self::normalize(is_string($env) ? $env : null) ?? self::DEFAULT_NAME;
    }

    /**
     * Podpis provozovatele pod dodanou sadou.
     *
     * `reviewed_by` je technická kontrola zdrojů (ta o sobě sama říká, že není
     * odborné ani právní schválení), `approved_by` je provozovatel, který za
     * hodnoty ručí. {@see RulesetApproval} trvá na tom, aby to byly RŮZNÉ
     * identity — kontrolovat a schvalovat sám sebe nemá důkazní hodnotu.
     */
    public static function approval(
        RulesetTechnicalReview $technicalReview,
        string $approvedOn = self::APPROVED_ON,
    ): RulesetApproval {
        $approver = self::name();
        if ($approver === $technicalReview->checkedBy) {
            throw new InvalidArgumentException(
                'Schvalovatel dodaných legislativních sad nesmí být totožný s technickou kontrolou.',
            );
        }

        return new RulesetApproval(
            $technicalReview->checkedBy,
            $technicalReview->checkedOn,
            $approver,
            $approvedOn,
            'Provozovatel instalace přebírá odpovědnost za odbornou správnost dodaných '
            . 'legislativních sad. Doklad je manifest oficiálních zdrojů u každé verze '
            . '(odkaz a datum stažení) a technická kontrola přesných hodnot; jednotlivé '
            . 'sazby uživatel neschvaluje.',
        );
    }

    private static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);

        return $trimmed === '' ? null : $trimmed;
    }
}
