<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Které pojišťovně se smí poslat vyplněný úřední tiskopis — a když ne, proč.
 *
 * Jednotné vydání 2026 (`UNI 73.51/2026` pro HOZ, `UNI 76.51/2026` pro PPZ)
 * je navržené jako tiskopis bez značky: nemá logo, nemá kód pojišťovny
 * a mluví o „pracovníku pojišťovny". Zveřejňuje ho ale zatím **jen VZP**.
 * Ostatní pojišťovny dál nabízejí svoje starší, značkou podepsané formuláře
 * (a většinou jako dynamický XFA, který se strojově vyplnit nedá).
 *
 * Katalog proto NEHÁDÁ. Pojišťovna dostane úřední tiskopis jen tehdy, když je
 * doložené, že jde o tentýž tiskopis; jinak dostane vlastní čitelnou sestavu
 * a s ní jednovětný důvod. Je to stejná fail-closed logika jako
 * u {@see HealthInsurerChannelCatalog}: raději říct, co chybí, než poslat
 * úřední formulář, který pojišťovna nezná.
 *
 * ## Jak se přidá další pojišťovna
 *
 * Jeden řádek v {@see self::SHARED_FORM} s odkazem na doklad. Nic víc —
 * vyplňování adresuje pole tiskopisu jménem (`ZamNaz`, `PojCis_1`, …), takže
 * pro každou pojišťovnu, která zveřejní tentýž tiskopis, funguje beze změny
 * kódu. Kdyby některá pojišťovna zveřejnila tiskopis vlastní, přibude jí
 * v {@see CachedHealthOfficialFormProvider} vlastní záznam a tady odkaz na něj.
 */
final class HealthOfficialFormCatalog
{
    public const REASON_INSURER_FORM_NOT_SHARED =
        'zp_official_form_insurer_not_documented';
    public const REASON_CAPACITY_EXCEEDED =
        'zp_official_form_capacity_exceeded';

    /**
     * Pojišťovny, u kterých je doložené, že jednotný tiskopis je jejich.
     *
     * @var array<string,string> kód pojišťovny => doklad
     */
    private const SHARED_FORM = [
        // Vydavatel jednotného vydání; tiskopis je ke stažení na vzp.cz
        // a k němu XDP šablona „pro hromadné vyplňování z účetních systémů".
        '111' => 'VZP jednotné vydání tiskopisu sama zveřejňuje.',
        // VoZP čísluje své poučení k témuž tiskopisu `vozp-73.51/2019`
        // (HOZ) a `vozp-76.51/2019` (PPZ) — jde o stejná čísla tiskopisů,
        // jaká nese jednotné vydání. XDP šablona HOZ, kterou VoZP zveřejňuje,
        // je navíc bajt po bajtu shodná s XDP šablonou VZP (liší se jen
        // odkaz na soubor PDF), takže datová část je prokazatelně tatáž.
        '201' => 'VoZP používá tatáž čísla tiskopisů 73.51 a 76.51 '
            . 'a zveřejňuje k nim shodnou XDP šablonu.',
    ];

    /**
     * Proč konkrétní pojišťovna jednotný tiskopis nedostane. Jedna věta,
     * která pojmenuje, co brání — ne obecné „nepodporováno".
     *
     * @var array<string,string>
     */
    private const OWN_FORM_REASON = [
        '205' => 'ČPZP přijímá hromadné oznámení i přehled o platbě výhradně '
            . 'jako XML a PDF ve stejné datové zprávě výslovně nepřipouští.',
        '207' => 'OZP zveřejňuje vlastní tiskopis (OZP 03.01/2010), '
            . 'ne jednotné vydání 2026.',
        '209' => 'ZPŠ zveřejňuje vlastní tiskopis (ZPS 73.01/2010), '
            . 'ne jednotné vydání 2026.',
        '211' => 'ZP MV ČR zveřejňuje vlastní tiskopis (HOZ 2011 v3.2 ZPMV) '
            . 'a vede vlastní kanál eKomunikace, ne jednotné vydání 2026.',
        '213' => 'RBP připouští vlastní tisk jen formátově a obsahově shodný '
            . 'se svým tiskopisem, který zveřejňuje jen jako dynamický '
            . 'formulář XFA — ten se strojově vyplnit nedá.',
    ];

    public function __construct(
        private readonly HealthInsurerChannelCatalog $channels = new HealthInsurerChannelCatalog(),
        private readonly HealthOfficialFormProvider $forms = new CachedHealthOfficialFormProvider(),
    ) {}

    /**
     * @param int $rows počet vět, které se mají na tiskopis vejít
     *                  (u přehledu o platbě vždy jedna)
     */
    public function decide(
        string $insurerCode,
        string $formId,
        int $rows,
    ): HealthOfficialFormDecision {
        // Neznámý kód pojišťovny je chyba podání, ne důvod k vlastní sestavě.
        $this->channels->forInsurer($insurerCode);

        if (!isset(self::SHARED_FORM[$insurerCode])) {
            return HealthOfficialFormDecision::ownDocument(
                self::REASON_INSURER_FORM_NOT_SHARED,
                self::OWN_FORM_REASON[$insurerCode]
                    ?? sprintf(
                        'Pro pojišťovnu %s není doložené, že je jednotný '
                        . 'tiskopis 2026 její.',
                        $insurerCode,
                    ),
            );
        }

        $form = $this->forms->form($formId);
        if ($rows > $form->rowCapacity) {
            return HealthOfficialFormDecision::ownDocument(
                self::REASON_CAPACITY_EXCEEDED,
                sprintf(
                    'Na úřední tiskopis %s se vejdou %d věty a číslo listu má '
                    . 'natištěné „1/1", takže %d vět na něj nelze vytisknout.',
                    $form->formNumber,
                    $form->rowCapacity,
                    $rows,
                ),
            );
        }

        return HealthOfficialFormDecision::official($formId);
    }

    /** @return array<string,string> kód pojišťovny => doklad */
    public static function insurersWithSharedForm(): array
    {
        return self::SHARED_FORM;
    }
}
