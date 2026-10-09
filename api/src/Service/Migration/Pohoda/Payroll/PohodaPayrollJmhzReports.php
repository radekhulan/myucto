<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAttributeDocument;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;

/**
 * Podání ČSSZ z `91_mzdy.xml` (PAMICA): měsíční hlášení JMHZ (`MH`, `MHitems`) a registrace
 * zaměstnanců (`RegZAM`, `RegZAMitems`, `PredRegZAM`, `PredRegZAMitems`). Obsah podání nese
 * export po atributech datového slovníku ({@see PohodaXml::attributes()}).
 *
 * Třída jen čte a skládá, nic nezapisuje. Formulář měsíčního hlášení se skládá přes XML
 * ({@see JmhzAttributeDocument}) a čte toutéž čtečkou jako nahraný soubor hlášení
 * ({@see JmhzReportReader}), takže z PAMICA vznikne tentýž {@see JmhzReportForm} jako
 * z XML, které PAMICA odeslala.
 *
 * Kódy PAMICA ověřené na datech: `MH.RelTyp` 1 = řádné, 2 = opravné (a `RefID` míří na
 * opravované podání), `ElOdeslano` = podání odešlo, `DatPod`/`DatPrij` = odeslání
 * a přijetí; `RegZAMitems.RelTyp` viz {@see PohodaPayrollPeople}. Doručenka datové
 * schránky k hlášení je v `DataBoxSent` s agendou 190 a `RefID` = `MH.ID`.
 */
final class PohodaPayrollJmhzReports
{
    public const PROGRAM = 'PAMICA';
    private const TYPES = ['1' => 'R', '2' => 'O', '3' => 'S'];
    private const DATA_BOX_AGENDA_MONTHLY = '190';
    /** `RelStavDP` přijatého podání. */
    private const STATE_ACCEPTED = '7';

    /**
     * Měsíční hlášení roku v pořadí období a odeslání.
     *
     * @return list<array{
     *   source_key:string, id:string, corrected_source_key:?string, type:string, year:int, month:int, period:string,
     *   sent:bool, state:string, filled_at:string, submitted_at:?string, accepted_at:?string, guid:?string,
     *   header:array<string,string>, summary:list<array<string,int|string>>, delivery:?array<string,string>,
     *   forms:list<array{item_id:string, relation_key:string, person_key:string, item:array<string,string>,
     *     attributes:list<array<string,int|string>>, form:?JmhzReportForm, error:?string}>
     * }>
     */
    public static function read(string $file, int $year, JmhzReportReader $reader = new JmhzReportReader()): array
    {
        // Hlavičky hlášení a doručenky jedním průchodem, formuláře (velké, se všemi daty
        // podání) druhým - jen hlášení převáděného roku.
        $headers = [];
        $deliveries = [];
        /** @var array<string,array<string,string>> $ppvRelations osoba => ID PPV => vztah (souběžné bloky položky) */
        $ppvRelations = [];
        foreach (PohodaXml::scan($file, ['MH', 'DataBoxSent', 'ZAMpomer']) as $table => $row) {
            if ($table === 'ZAMpomer') {
                $ppv = preg_replace('/\D/', '', PohodaXml::text($row, 'IDPPV')) ?? '';
                if ($ppv !== '') {
                    $ppvRelations[PohodaXml::text($row, 'RefZAM')][$ppv] = PohodaXml::text($row, 'ID');
                }
            } elseif ($table === 'MH') {
                if ((int) PohodaXml::text($row, 'Rok') === $year) {
                    $headers[PohodaXml::text($row, 'ID')] = $row;
                }
            } elseif (PohodaXml::text($row, 'RelAgID') === self::DATA_BOX_AGENDA_MONTHLY) {
                $deliveries[PohodaXml::text($row, 'RefID')] = self::columns($row, []);
            }
        }
        if ($headers === []) {
            return [];
        }
        /** @var array<string,array{month:int,type:string,guid:?string,filled:string,summary:list<array<string,int|string>>,header:array<string,int|string>}> $meta */
        $meta = [];
        foreach ($headers as $id => $row) {
            $month = (int) PohodaXml::text($row, 'RelMesic');
            if ($month < 1 || $month > 12) {
                continue;
            }
            $summary = PohodaXml::attributes($row, 'DataAll');
            $guid = self::guid(self::first($summary, 10001));
            $type = self::TYPES[PohodaXml::text($row, 'RelTyp')] ?? 'R';
            $filled = self::moment(PohodaXml::text($row, 'DatPod')) ?? self::moment(PohodaXml::text($row, 'DatSave')) ?? self::moment(PohodaXml::text($row, 'DatCreate'))
                ?? sprintf('%04d-%02d-01T00:00:00', $year, $month);
            $meta[$id] = [
                'month' => $month, 'type' => $type, 'guid' => $guid, 'filled' => $filled, 'summary' => $summary,
                'header' => ['guid' => $guid ?? '00000000-0000-0000-0000-000000000000', 'type' => $type, 'year' => $year, 'month' => $month, 'filled_at' => $filled],
            ];
        }
        // Položka se rozloží na formulář hned při čtení: syrový řádek (všechny atributy
        // podání) by jinak ležel v paměti za celý rok vedle svého rozloženého obrazu.
        /** @var array<string,list<array<string,mixed>>> $forms hlášení => formuláře v pořadí ze souboru */
        $forms = [];
        foreach (PohodaXml::records($file, 'MHitems') as $item) {
            $parent = PohodaXml::text($item, 'RefAg');
            if (!isset($meta[$parent])) {
                continue;
            }
            $personKey = PohodaXml::text($item, 'RefZAM');
            $columns = self::columns($item, ['Data']);
            // Položka osoby se souběžnými vztahy (`Soubeh`) nese za každé PPV vlastní blok
            // atributů (`order2`: 0 hlavní vztah položky, 1… další). Každý blok je samostatný
            // formulář hlášení; slité do jednoho by pozdější blok přepsal hodnoty hlavního vztahu.
            $blocks = [];
            foreach (PohodaXml::attributes($item, 'Data') as $attribute) {
                $blocks[(int) $attribute['order2']][] = $attribute;
            }
            ksort($blocks);
            if ($blocks === []) {
                $blocks = [0 => []];
            }
            foreach ($blocks as $block => $attributes) {
                $position = count($forms[$parent] ?? []) + 1;
                $form = null;
                $error = null;
                if ($attributes === []) {
                    $error = 'Položka hlášení nemá obsah (v exportu chybí atributy formuláře).';
                } else {
                    try {
                        $form = $reader->formFromDocument(JmhzAttributeDocument::form($meta[$parent]['header'], $attributes), $position);
                    } catch (RegistrationImportFileException $e) {
                        $error = $e->getMessage();
                    }
                }
                $ppv = preg_replace('/\D/', '', self::first($attributes, 10228) ?? '') ?? '';
                $forms[$parent][] = [
                    'item_id' => PohodaXml::text($item, 'ID') . ($block === 0 ? '' : ':' . $block),
                    'relation_key' => $block === 0 ? PohodaXml::text($item, 'RefPomer') : ($ppvRelations[$personKey][$ppv] ?? ''),
                    'person_key' => $personKey,
                    'item' => $columns,
                    'attributes' => $attributes,
                    'form' => $form,
                    'error' => $error,
                ];
            }
        }

        $out = [];
        foreach ($headers as $id => $row) {
            if (!isset($meta[$id])) {
                continue;
            }
            ['month' => $month, 'type' => $type, 'guid' => $guid, 'filled' => $filled, 'summary' => $summary] = $meta[$id];
            $submitted = self::moment(PohodaXml::text($row, 'DatPod'));
            $refId = PohodaXml::text($row, 'RefID');

            $out[] = [
                'source_key' => 'MH:' . $id,
                'id' => (string) $id,
                'corrected_source_key' => $refId !== '' && $refId !== '0' ? 'MH:' . $refId : null,
                'type' => $type,
                'year' => $year,
                'month' => $month,
                'period' => sprintf('%04d-%02d', $year, $month),
                'sent' => self::submitted($row, isset($deliveries[$id])),
                'state' => PohodaXml::text($row, 'RelStavDP'),
                'filled_at' => $filled,
                'submitted_at' => $submitted,
                'accepted_at' => self::moment(PohodaXml::text($row, 'DatPrij')),
                'guid' => $guid,
                'header' => self::columns($row, ['DataAll']),
                'summary' => $summary,
                'delivery' => $deliveries[$id] ?? null,
                'forms' => $forms[$id] ?? [],
            ];
            unset($forms[$id]);
        }
        usort($out, static fn (array $a, array $b): int => [$a['period'], $a['filled_at'], (int) $a['id']] <=> [$b['period'], $b['filled_at'], (int) $b['id']]);

        return $out;
    }

    /**
     * Platný formulář každého vztahu a měsíce: z podání, které za období odešlo jako poslední
     * (opravné po řádném). Neodeslané podání se nebere - ČSSZ ho nikdy nedostala.
     *
     * @param list<array<string,mixed>> $reports {@see self::read()}
     * @return array<string,array<string,array{form:JmhzReportForm,report:array<string,mixed>}>> vztah (RefPomer) => období => formulář
     */
    public static function effective(array $reports): array
    {
        $out = [];
        foreach ($reports as $report) {
            if ($report['sent'] !== true || $report['type'] === 'S') {
                continue;
            }
            foreach ($report['forms'] as $form) {
                if ($form['form'] === null || $form['relation_key'] === '' || $form['form']->formType === 'S') {
                    continue;
                }
                // Hlášení jsou seřazená podle odeslání, pozdější přepíše dřívější.
                $out[$form['relation_key']][$report['period']] = ['form' => $form['form'], 'report' => $report];
            }
        }
        foreach ($out as $relation => $periods) {
            ksort($periods, SORT_STRING);
            $out[$relation] = $periods;
        }

        return $out;
    }

    /**
     * Registrace zaměstnanců (REGZEC) a předregistrace ze všech let, s obsahem vět po atributech.
     *
     * @return list<array{source_key:string, kind:string, id:string, sent:bool, state:string, filled_at:?string,
     *   submitted_at:?string, accepted_at:?string, header:array<string,string>,
     *   items:list<array{item_id:string, relation_key:string, person_key:string, type:string, item:array<string,string>,
     *     attributes:list<array<string,int|string>>, values:array<int,string>}>}>
     */
    public static function registrations(string $file): array
    {
        $out = [];
        $tables = ['RegZAM' => 'RegZAMitems', 'PredRegZAM' => 'PredRegZAMitems'];
        // Registrace i předregistrace s větami jedním průchodem souborem. Věta se rozloží
        // hned při čtení, syrové řádky všech let by jinak ležely v paměti vedle rozložených.
        $rows = [];
        foreach (PohodaXml::scan($file, [...array_keys($tables), ...array_values($tables)]) as $table => $row) {
            if (isset($tables[$table])) {
                $rows[$table][PohodaXml::text($row, 'ID')] = $row;
            } else {
                $rows[$table][PohodaXml::text($row, 'RefAg')][] = self::sentence($row);
            }
        }
        foreach ($tables as $headerTable => $itemTable) {
            $headers = $rows[$headerTable] ?? [];
            if ($headers === []) {
                continue;
            }
            $items = $rows[$itemTable] ?? [];
            foreach ($headers as $id => $row) {
                $sentences = $items[$id] ?? [];
                $submitted = self::moment(PohodaXml::text($row, 'DatPod'));
                $out[] = [
                    'source_key' => $headerTable . ':' . $id,
                    'kind' => $headerTable === 'RegZAM' ? 'registration' : 'preregistration',
                    'id' => (string) $id,
                    'sent' => self::submitted($row, false),
                    'state' => PohodaXml::text($row, 'RelStavDP'),
                    'filled_at' => $submitted ?? self::moment(PohodaXml::text($row, 'DatSave')) ?? self::moment(PohodaXml::text($row, 'DatCreate')),
                    'submitted_at' => $submitted,
                    'accepted_at' => self::moment(PohodaXml::text($row, 'DatPrij')),
                    'header' => self::columns($row, []),
                    'items' => $sentences,
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => [(string) $a['filled_at'], $a['source_key']] <=> [(string) $b['filled_at'], $b['source_key']]);

        return $out;
    }

    /**
     * @param array<string,mixed> $item řádek `RegZAMitems` / `PredRegZAMitems`
     * @return array{item_id:string, relation_key:string, person_key:string, type:string, item:array<string,string>,
     *   attributes:list<array<string,int|string>>, values:array<int,string>}
     */
    private static function sentence(array $item): array
    {
        $attributes = PohodaXml::attributes($item, 'Data');
        $values = [];
        foreach ($attributes as $attribute) {
            if ($attribute['order'] === 0 && !isset($values[$attribute['id']])) {
                $values[$attribute['id']] = $attribute['value'];
            }
        }

        return [
            'item_id' => PohodaXml::text($item, 'ID'),
            'relation_key' => PohodaXml::text($item, 'RefPomer'),
            'person_key' => PohodaXml::text($item, 'RefZAM'),
            'type' => PohodaXml::text($item, 'RelTyp'),
            'item' => self::columns($item, ['Data']),
            'attributes' => $attributes,
            'values' => $values,
        ];
    }

    /**
     * Údaje z poslední odeslané registrace každého vztahu: atribut => hodnota (registrace
     * trvajícího vztahu a přihláška; odhláška údaje vztahu nenese).
     *
     * @param list<array<string,mixed>> $registrations {@see self::registrations()}
     * @return array<string,array<int,string>> vztah (RefPomer) => atribut => hodnota
     */
    public static function registrationProfiles(array $registrations): array
    {
        $out = [];
        foreach ($registrations as $registration) {
            if ($registration['sent'] !== true || $registration['kind'] !== 'registration') {
                continue;
            }
            foreach ($registration['items'] as $item) {
                if ($item['relation_key'] === '' || $item['type'] === '2' || $item['values'] === []) {
                    continue;
                }
                $out[$item['relation_key']] = $item['values'] + ($out[$item['relation_key']] ?? []);
            }
        }

        return $out;
    }

    /**
     * VS zaměstnavatele u ČSSZ (ID 10221) a kód OSSZ (ID 10004) z odeslaných registrací.
     * Hodnota se vrátí jen tehdy, když ji všechny věty uvádějí shodně.
     *
     * @param list<array<string,mixed>> $registrations {@see self::registrations()}
     * @return array{symbol:?string,office:?string,ambiguous:list<int>}
     */
    public static function employerIdentifiers(array $registrations): array
    {
        $seen = [10221 => [], 10004 => []];
        foreach ($registrations as $registration) {
            if ($registration['sent'] !== true) {
                continue;
            }
            foreach ($registration['items'] as $item) {
                foreach (array_keys($seen) as $id) {
                    $value = trim((string) ($item['values'][$id] ?? ''));
                    if ($value !== '') {
                        $seen[$id][$value] = true;
                    }
                }
            }
        }
        $pick = static fn (array $values): ?string => count($values) === 1 ? (string) array_key_first($values) : null;

        return [
            'symbol' => $pick($seen[10221]),
            'office' => $pick($seen[10004]),
            'ambiguous' => array_keys(array_filter($seen, static fn (array $values): bool => count($values) > 1)),
        ];
    }

    /**
     * @param list<array{id:int,value:string}> $attributes
     */
    private static function first(array $attributes, int $id): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute['id'] === $id && $attribute['value'] !== '') {
                return $attribute['value'];
            }
        }

        return null;
    }

    private static function guid(?string $value): ?string
    {
        $value = $value === null ? null : strtoupper(trim($value, " {}\t"));

        return $value !== null && preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/D', $value) === 1 ? $value : null;
    }

    /** Okamžik z exportu (`RRRR-MM-DD` nebo `RRRR-MM-DDTHH:MM:SS`); nulové datum Accessu = žádný. */
    private static function moment(string $value): ?string
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?$/D', $value, $m) !== 1 || (int) $m[1] < 1901) {
            return null;
        }

        return strlen($value) === 10 ? $value . 'T00:00:00' : $value;
    }

    /**
     * Sloupce záznamu jako text (bez atributových bloků), pro uložený obsah podání.
     *
     * @param array<string,mixed> $row
     * @param list<string> $skip
     * @return array<string,string>
     */
    private static function columns(array $row, array $skip): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, $skip, true) || str_starts_with((string) $key, '@') || !is_string($value)) {
                continue;
            }
            $out[(string) $key] = $value;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Podání odešlo: příznak elektronického odeslání, stav „přijato" (`RelStavDP` 7), datum přijetí,
     * nebo doručenka datové schránky. Podání odeslané datovou schránkou má `ElOdeslano` 0
     * (příznak patří jen odeslání přes rozhraní ČSSZ), a bez ostatních znaků by ho převod vedl
     * jako neodeslané a vyzval k druhému podání téhož měsíce.
     *
     * @param array<string,mixed> $row hlavička `MH` / `RegZAM` / `PredRegZAM`
     */
    private static function submitted(array $row, bool $delivered): bool
    {
        return self::bool(PohodaXml::text($row, 'ElOdeslano'))
            || PohodaXml::text($row, 'RelStavDP') === self::STATE_ACCEPTED
            || self::moment(PohodaXml::text($row, 'DatPrij')) !== null
            || $delivered;
    }

    /** Přijaté podání: datum přijetí, nebo stav „přijato" (podání datovou schránkou datum přijetí nenese). */
    public static function accepted(array $submission): bool
    {
        return ($submission['accepted_at'] ?? null) !== null || ($submission['state'] ?? null) === self::STATE_ACCEPTED;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }
}
