<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Ukázkový profil „Vzor MyÚčto" — obecné měsíční podklady pro firmy bez
 * docházkového systému (typicky brigádníci na DPP a DPČ). Jeden list
 * „Podklady", jeden řádek = jedna osoba za měsíc.
 *
 * Seznam sloupců {@see self::columns()} je jediná definice: z něj vznikají
 * pravidla profilu i šablona XLSX ke stažení
 * ({@see AttendanceMyUctoTemplate}), takže se nemohou rozejít.
 *
 * Na rozdíl od Vzoru GIRITON tu není pravidlo „vše ostatní podle hlavičky":
 * do obecné šablony si firma dopisuje vlastní sloupce (poznámky, kontrolní
 * součty) a z neznámého sloupce s částkou by vznikla mzdová složka bez
 * zařazení pro JMHZ. Neznámé sloupce i ostatní listy se proto ignorují.
 *
 * Při každé změně sloupců nebo složek zvyšte {@see self::VERSION}.
 */
final class AttendanceMyUctoSampleProfile
{
    public const KEY = 'myucto';
    public const NAME = 'Vzor MyÚčto';
    public const VERSION = 2;

    public const SHEET = 'Podklady';
    public const GUIDE_SHEET = 'Návod';

    public const FORMAT_TEXT = 'text';
    public const FORMAT_DATE = 'date';
    public const FORMAT_HOURS = 'hours';
    public const FORMAT_AMOUNT = 'amount';

    /**
     * Hodinová mzda podle podkladů je deklarovaná složka (stejná jako ve Vzoru
     * GIRITON): základní hodinová mzda z číselníku je pravidelná složka a vstupní
     * brána bere jen jednorázové.
     */
    private const HOURLY = 'MZDA_HODINOVA_DOCH';
    private const EXTRAORDINARY = 'ODMENA_MIMORADNA';

    /**
     * Sloupce šablony v pořadí, v jakém jsou v listu.
     *
     * `header` = hlavička v šabloně, `patterns` = hlavičky, které pravidlo
     * pozná (první je přesná hlavička nebo vzor s `*`), `examples` = dva
     * ukázkové řádky smyšlených osob (datum jako YYYY-MM-DD).
     *
     * @return list<array{
     *   header:string,patterns:list<string>,meaning:string,unit:?string,component_code:?string,
     *   format:string,required:bool,help:string,examples:array{0:string|int|float|null,1:string|int|float|null}
     * }>
     */
    public static function columns(): array
    {
        $column = static fn (
            string $header,
            array $patterns,
            string $meaning,
            string $format,
            string $help,
            array $examples,
            ?string $component = null,
            bool $required = false,
        ): array => [
            'header' => $header,
            'patterns' => $patterns,
            'meaning' => $meaning,
            // Hodiny bez pevné jednotky: server pozná desetinné hodiny i čas
            // zapsaný v Excelu („7:30") podle formátu buňky.
            'unit' => AttendanceMeaning::isMoney($meaning) ? AttendanceMeaning::UNIT_AMOUNT : null,
            'component_code' => $component,
            'format' => $format,
            'required' => $required,
            'help' => $help,
            'examples' => $examples,
        ];

        return [
            $column('Osobní číslo', ['osobní číslo'], 'personal_number', self::FORMAT_TEXT,
                'Vaše označení osoby. Nepovinné; u nové osoby se převezme jako osobní číslo a další měsíce podle něj import osobu pozná spolehlivěji než podle jména.',
                ['B001', 'B002']),
            $column('Příjmení a jméno', ['příjmení a jméno', 'jméno a příjmení', 'jméno'], 'person_name', self::FORMAT_TEXT,
                'Povinné. Podle jména a osobního čísla import osobu najde v evidenci; kdo tam není, toho nabídne založit.',
                ['Ukázková Eva', 'Příkladný Adam'], required: true),
            $column('Rodné číslo', ['rodné číslo'], 'birth_number', self::FORMAT_TEXT,
                'S lomítkem nebo bez. Pomáhá spárovat osobu a u nové osoby se uloží na kartu zaměstnance.',
                [null, null]),
            $column('Datum narození', ['datum narození'], 'birth_date', self::FORMAT_DATE,
                'Jen u osoby bez rodného čísla (typicky cizinec). Formát data viz níže.',
                ['2001-03-15', '1998-11-02']),
            $column('Zdravotní pojišťovna', ['zdravotní pojišťovna*'], 'health_insurer_code', self::FORMAT_TEXT,
                'Kód pojišťovny (seznam níže). Použije se při založení nové osoby.',
                ['111', '211']),
            $column('Druh vztahu', ['druh vztahu'], 'relation_label', self::FORMAT_TEXT,
                'HPP = pracovní poměr, DPP = dohoda o provedení práce, DPČ = dohoda o pracovní činnosti. Určí druh vztahu nové osoby a pomůže najít správný vztah u osoby s více vztahy.',
                ['DPP', 'DPČ']),
            $column('Nástup', ['nástup*', 'datum nástupu'], 'start_date', self::FORMAT_DATE,
                'Den nástupu nové osoby. Bez něj se nástup navrhne na první den importovaného měsíce.',
                ['2026-06-01', '2026-06-15']),
            $column('Týdenní úvazek', ['týdenní úvazek*'], 'weekly_hours', self::FORMAT_HOURS,
                'Sjednaná týdenní pracovní doba v hodinách (např. 40). U DPP se nevyplňuje.',
                [null, 10]),
            $column('Měsíční mzda', ['měsíční mzda*'], 'monthly_wage', self::FORMAT_AMOUNT,
                'Sjednaná měsíční mzda v Kč jen u zaměstnance s měsíční mzdou. Při založení osoby se předvyplní do podmínek, u existující osoby ji import nabídne ke změně podmínek.',
                [null, null]),
            $column('Hodinová sazba (Kč/h)', ['hodinová sazba*'], 'hourly_rate', self::FORMAT_AMOUNT,
                'Sjednaná hodinová sazba v Kč za hodinu (např. 180). Při založení osoby se uloží do podmínek vztahu jako pravděpodobný výdělek pro náhrady, než vznikne skutečný průměr.',
                [180, 180]),
            $column('Konec dohody', ['konec dohody*', 'sjednaný konec*'], 'end_date', self::FORMAT_DATE,
                'Sjednaný konec dohody nebo pracovního poměru na dobu určitou. Při založení osoby se uloží do podmínek vztahu; po tomto dni ji nabídne hromadné ukončení dohod.',
                ['2026-12-31', null]),
            $column('Číslo účtu', ['číslo účtu*', 'bankovní účet*'], 'bank_account', self::FORMAT_TEXT,
                'Účet pro výplatu ve tvaru předčíslí-číslo/kód banky (např. 1000000005/0100) nebo IBAN. Při založení osoby se uloží jako výplatní účet; před první výplatou ho na kartě osoby ověřte.',
                ['1000000005/0100', null]),
            $column('Odpracováno (hod)', ['odpracováno*'], 'worked_hours', self::FORMAT_HOURS,
                'Všechny odpracované hodiny v měsíci včetně noci, víkendu, svátku a přesčasu.',
                [42.5, 60]),
            $column('z toho noc (hod)', ['z toho noc*'], 'night_hours', self::FORMAT_HOURS,
                'Část odpracovaných hodin v noci (22 až 6 hodin).',
                [8, null]),
            $column('z toho víkend (hod)', ['z toho víkend*'], 'weekend_hours', self::FORMAT_HOURS,
                'Část odpracovaných hodin v sobotu a v neděli.',
                [12, null]),
            $column('z toho svátek (hod)', ['z toho svátek*'], 'holiday_work_hours', self::FORMAT_HOURS,
                'Část odpracovaných hodin ve svátek.',
                [null, null]),
            $column('z toho přesčas (hod)', ['z toho přesčas*'], 'overtime_hours', self::FORMAT_HOURS,
                'Část odpracovaných hodin přesčas (jen pracovní poměr).',
                [null, null]),
            $column('Dovolená (hod)', ['dovolená*'], 'vacation_hours', self::FORMAT_HOURS,
                'Hodiny čerpané dovolené.',
                [null, 8]),
            $column('Nemoc (hod)', ['nemoc*'], 'sick_hours', self::FORMAT_HOURS,
                'Hodiny pracovní neschopnosti.',
                [null, null]),
            $column('Lékař (hod)', ['lékař*'], 'doctor_hours', self::FORMAT_HOURS,
                'Hodiny návštěvy lékaře.',
                [null, null]),
            $column('Hodinová mzda celkem (Kč)', ['hodinová mzda celkem*'], 'component', self::FORMAT_AMOUNT,
                'Mzda za odpracované hodiny za celý měsíc (hodiny krát sazba), ne hodinová sazba.',
                [7650, 10800], self::HOURLY),
            $column('Odměna (Kč)', ['odměna*'], 'component', self::FORMAT_AMOUNT,
                'Odměna nebo prémie za měsíc.',
                [1000, null], 'ODMENA'),
            $column('Mimořádná odměna (Kč)', ['mimořádná odměna*'], 'component', self::FORMAT_AMOUNT,
                'Jednorázová mimořádná odměna.',
                [null, 2000], self::EXTRAORDINARY),
            $column('Srážka z čisté mzdy (Kč)', ['srážka z čisté mzdy*'], 'net_other_deduction', self::FORMAT_AMOUNT,
                'Částka, která se strhne z čisté mzdy (např. za stravu nebo zálohu). Import pro ni založí dohodu o srážce na daný měsíc.',
                [null, 500]),
        ];
    }

    /** @return list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> */
    public static function rules(): array
    {
        $sheet = AttendanceText::normalize(self::SHEET) . '*';
        $rules = [];
        foreach (self::columns() as $column) {
            foreach ($column['patterns'] as $pattern) {
                $rules[] = [
                    'sheet' => $sheet,
                    'header' => $pattern,
                    'meaning' => $column['meaning'],
                    'unit' => $column['unit'],
                    'component_code' => $column['component_code'],
                ];
            }
        }
        // Neznámé sloupce, list Návod i ostatní listy sešitu do importu nepatří.
        $rules[] = ['sheet' => '*', 'header' => '*', 'meaning' => AttendanceMeaning::IGNORE, 'unit' => null, 'component_code' => null];

        return $rules;
    }

    /**
     * Složky, které výchozí číselník nemá. Odměna jde na ODMENA z číselníku.
     *
     * @return list<array{code:string,name:string,kind:string}>
     */
    public static function components(): array
    {
        return [
            // Tarifní druh: v JMHZ jde na tarifní mzdy jako základní hodinová mzda.
            ['code' => self::HOURLY, 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage'],
            // Odměna: zda pravidelná (10330), nebo nepravidelná (10331), rozhodne
            // účetní jednou za firmu, jako u ostatních odměn.
            ['code' => self::EXTRAORDINARY, 'name' => 'Mimořádná odměna', 'kind' => 'bonus'],
        ];
    }
}
