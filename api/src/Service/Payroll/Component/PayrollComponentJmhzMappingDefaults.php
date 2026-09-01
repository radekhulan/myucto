<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

/**
 * Výchozí zařazení mzdových složek do JMHZ.
 *
 * Výchozí číselník složek ({@see PayrollComponentDefaults}) dodáváme my, takže
 * u složek, kde je cílový atribut jednoznačný, nemá smysl nutit každou účetní
 * vyplnit totéž ručně. Doplňuje se JEN tam, kde ještě žádná volba neexistuje;
 * rozhodnutí účetní — včetně vědomě zrušeného (deaktivovaného) mapování — se
 * nikdy nepřepisuje ani neobnovuje.
 *
 * Mapuje se na NEJPODROBNĚJŠÍ uzel, který složce odpovídá, ne na sběrný součet:
 * součty do nadřazených atributů se dopočítají samy přes `ancestor_attribute_ids`
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder}),
 * takže detail naplní i catch-all total nad sebou, kdežto opačně to nejde.
 */
final class PayrollComponentJmhzMappingDefaults
{
    /**
     * Kód výchozí složky → cílový atribut JMHZ.
     *
     * Záměrně tu NEJSOU složky, u kterých je zařazení úsudek účetní a špatný
     * default by byl horší než žádný — PROVIZE, ODSTUPNE,
     * NAHRADA_KONKURENCNI_DOLOZKA, DOPLATEK_MZDY, NEPENEZNI_PRIJEM,
     * CESTOVNI_NAHRADA*, PRISPEVEK_RIZIKOVE_SPORENI a všechny benefity
     * (PRISPEVEK_*, VZDELAVANI, REKREACE_VOLNY_CAS, ZDRAVOTNI_BENEFIT,
     * PRECHODNE_UBYTOVANI, SOUKROME_VOZIDLO). Předvyplněná chybná hodnota by
     * prošla do hlášení tiše, kdežto prázdné zařazení obrazovka viditelně
     * hlásí jako `missing` a účetní ho musí vyřešit.
     *
     * @var array<string,string>
     */
    private const DEFAULTS = [
        // Tarifní mzdy — základ mzdy bez ohledu na způsob odměňování.
        'MZDA_MESICNI' => '10329',
        'MZDA_HODINOVA' => '10329',
        'MZDA_UKOLOVA' => '10329',
        // Prémie a odměny nepravidelné.
        'ODMENA' => '10331',
        // Příplatky celkem. Společná složka „Prémie a příplatky" nese obojí,
        // ale zákonné příplatky mají od migrace vlastní kódy, takže sem patří
        // jako sběrný uzel příplatků.
        'PREMIE_PRIPLATKY' => '10332',
        'PRIPLATEK_PRESCAS' => '10333',
        'PRIPLATEK_NOCNI' => '10334',
        'PRIPLATEK_VIKEND' => '10335',
        'PRIPLATEK_SVATEK' => '10336',
        // Příplatek za ztížené pracovní prostředí vlastní detailní uzel v JMHZ
        // nemá, zůstává tedy na sběrném součtu příplatků.
        'PRIPLATEK_ZTIZENE_PROSTREDI' => '10332',
        // Náhrady mzdy zúčtované; náhrada při DPN má vlastní detailní uzel.
        'NAHRADA_MZDY' => '10337',
        'NAHRADA_MZDY_DPN' => '10342',
    ];

    public function __construct(
        private readonly PayrollComponentRepository $components,
        private readonly PayrollComponentJmhzMappingRepository $mappings,
    ) {}

    /**
     * Doplní chybějící výchozí mapování a vrátí jen ta, která opravdu vznikla.
     *
     * Opakované volání nic nemění: složka, která už jakýkoli záznam mapování má
     * (aktivní i deaktivovaný), se přeskočí. Selhání u jedné složky nesmí shodit
     * celý průchod — cíl může v nainstalovaném balíčku specifikace chybět nebo
     * si účetní mohla složku přeřadit mimo JMHZ; takovou složku přeskočíme
     * a zbytek dokončíme.
     *
     * `created_by` zůstává NULL: předvyplnění udělala aplikace, ne účetní, a
     * podle prázdného autora se to pozná i zpětně.
     *
     * @return list<array<string,mixed>> nově založená mapování
     */
    public function apply(int $supplierId): array
    {
        $existing = $this->mappings->listForSupplier($supplierId);
        $applied = [];
        foreach ($this->components->list($supplierId) as $component) {
            $code = PayrollTimeValue::string($component['code'] ?? null, 'code');
            $target = self::DEFAULTS[$code] ?? null;
            if ($target === null) {
                continue;
            }
            if (PayrollTimeValue::string($component['jmhz_treatment'] ?? null, 'jmhz_treatment') !== 'included') {
                continue;
            }
            $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
            if (isset($existing[$componentId])) {
                continue;
            }
            try {
                $applied[] = $this->mappings->put($supplierId, $componentId, $target, null, null);
            } catch (\Throwable) {
                continue;
            }
        }

        return $applied;
    }

    /**
     * Výchozí zařazení pro daný kód složky. Slouží testům a případné kontrole
     * číselníku proti katalogu cílů.
     */
    public static function targetForCode(string $code): ?string
    {
        return self::DEFAULTS[$code] ?? null;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::DEFAULTS;
    }
}
