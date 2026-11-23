<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\PayrollBuiltin;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Mzdy vedené přímo v POHODĚ (agenda Mzdy v datovém souboru `StwPh_<IČO>_<rok>.mdb`)
 * převedené do tvaru exportu PAMICA, který čte převod mezd
 * ({@see \MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter}).
 *
 * Oba programy vyrábí tentýž `91_mzdy.xml` (`tools/pohoda-export/Export-PohodaMdb.ps1`)
 * se stejnými jmény tabulek i sloupců mzdy, liší se ale stavbou:
 *  - POHODA nemá pracovní poměry (`ZAMpomer`): vztah (nástup, druh, úvazek, pojišťovna)
 *    je na kartě zaměstnance `ZAM`, každý zaměstnanec má jediný vztah,
 *  - mzda (`MZ`) nemá vazbu na poměr (`RefPomer`) ani položky (`MZslozky`, `MZneprit`,
 *    `MZsrazky`, `MZdoch`): částky a hodiny jsou sloupce mzdy a doby nepřítomnosti karta
 *    zaměstnance (`ZAMpDov`),
 *  - karta dovolené (`Dovolena`) je ve sloupcích `ZAM`, zaúčtování mezd (`MZzauct`) nenese
 *    účty (POHODA mzdy zaúčtuje interními doklady účetnictví).
 *
 * Převod proto nemá vlastní importér: {@see self::prepare()} zapíše vedle exportu soubor
 * v tvaru PAMICA ({@see self::ADAPTED_FILE}) a převod mezd pak jde stejnou cestou jako
 * u PAMICA. Jeden poměr na zaměstnance (`ZAMpomer.ID` = `ZAM.ID`), mzdové složky
 * a nepřítomnosti rozložené ze sloupců mzdy ({@see PohodaMzdyPayslip}), karta dovolené
 * ze `ZAM`. Co převod nezná, vrací v `unconverted_items` k vypsání do protokolu.
 */
final class PohodaMzdyAdapter
{
    /** Zdroj mezd: agenda Mzdy v POHODĚ. */
    public const VARIANT_POHODA = 'pohoda';
    /** Zdroj mezd: PAMICA (a starší POHODA Mzdy s pracovními poměry). */
    public const VARIANT_PAMICA = 'pamica';
    /** Soubor v tvaru PAMICA, který převod zapíše vedle `91_mzdy.xml`. */
    public const ADAPTED_FILE = '91_mzdy.pamica.xml';
    /** Atribut kořene převedeného souboru (převod ho podruhé nepřevádí). */
    private const ADAPTED_ATTRIBUTE = 'adapted';

    /** Tabulky, které převod PAMICA čte a POHODA je nese ve stejném tvaru: jdou beze změny. */
    private const PASSTHROUGH_TABLES = ['ZAM', 'ZAMpDet', 'ZAMucet', 'MZnahr', 'MZdavky', 'RegZAM', 'PredRegZAM', 'ONZ',
        'ELDP', 'ELDPpol', 'MH', 'NEMPRI', 'NEMPRIpol', 'NEMPRIpecovalDny', 'HZUPN', 'HZUPNpol', 'DataBoxSent',
        'sMzPoj', 'sMzMist', 'sSTR', 'pPK', 'pOS'];

    /** Tabulky podání a karet, kterým se doplní vazba na poměr (`RefPomer` = zaměstnanec). */
    private const RELATION_TABLES = ['MHitems', 'RegZAMitems', 'PredRegZAMitems', 'ONZpol', 'ZAMzp'];

    /** Sloupec s vazbou na zaměstnance v tabulkách {@see self::RELATION_TABLES}, v tomtéž pořadí. */
    private const RELATION_COLUMNS = ['RefZAM', 'RefZAM', 'RefZAM', 'RefZam', 'RefAg'];

    /** Sloupce karty zaměstnance s dovolenou v hodinách (nárok, převod, krácení, čerpání) => `Dovolena`. */
    private const LEAVE_COLUMNS = ['DovNarHod', 'DovStaraHod', 'DovKracHod', 'DovCerpHod'];
    private const LEAVE_TARGETS = ['NarokHod', 'StaraHod', 'KraceniHod', 'CerpanoHod'];

    /** Číselník složek a nepřítomností PAMICA, na které převod sloupce POHODY rozkládá. */
    private const CATALOG = [
        'sMZslozky' => [
            PohodaMzdyPayslip::MONTHLY_WAGE => ['Měsíční mzda', '1', '1', '1'],
            PohodaMzdyPayslip::HOURLY_WAGE => ['Hodinová mzda', '1', '1', '1'],
            PohodaMzdyPayslip::MEAL_ALLOWANCE => ['Stravenkový paušál', '4', '0', '0'],
        ],
        'sMZneprit' => [
            PohodaMzdyPayslip::VACATION => ['Dovolená', '0', '0', '0'],
            PohodaMzdyPayslip::HOLIDAY => ['Svátek', '0', '0', '0'],
            PohodaMzdyPayslip::SICKNESS => ['Pracovní neschopnost', '0', '0', '1'],
        ],
    ];

    /**
     * Pravidlo, podle kterého se zdroj pozná: zaměstnanci bez jediného pracovního poměru
     * a mzdy bez vazby na poměr. **Jediné místo rozhodnutí**: používá ho přehled exportu
     * ({@see \MyInvoice\Service\Migration\Pohoda\PohodaExport::payrollSummary()}) i převod.
     */
    public static function variant(int $people, int $relations, bool $payslipWithRelation): string
    {
        return $people > 0 && $relations === 0 && !$payslipWithRelation ? self::VARIANT_POHODA : self::VARIANT_PAMICA;
    }

    /**
     * Zdroj souboru mezd. Jeden průchod bez rozvinutí řádků; mzda se rozvine jen první.
     * Soubor, který převod už převedl do tvaru PAMICA, je PAMICA.
     */
    public static function detect(string $file): string
    {
        if (!is_file($file)) {
            throw new PohodaException('payroll_missing', 'Export neobsahuje mzdy (91_mzdy.xml).');
        }
        $reader = new \XMLReader();
        if (!$reader->open($file, null, LIBXML_NONET)) {
            throw new PohodaException('export_unreadable', 'Soubor exportu ' . basename($file) . ' nejde otevřít jako XML.');
        }
        $people = 0;
        $withRelation = false;
        $checked = false;
        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    throw new PohodaException('export_doctype', 'Soubor exportu ' . basename($file) . ' obsahuje DOCTYPE, což není povoleno.');
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 0 && $reader->getAttribute(self::ADAPTED_ATTRIBUTE) !== null) {
                    return self::VARIANT_PAMICA;
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 1) {
                    $ok = $reader->read();
                    continue;
                }
                if ($reader->localName === 'ZAMpomer') {
                    return self::VARIANT_PAMICA;
                }
                if ($reader->localName === 'ZAM') {
                    $people++;
                } elseif ($reader->localName === 'MZ' && !$checked) {
                    $node = $reader->expand();
                    $withRelation = $node instanceof \DOMElement && $node->getElementsByTagName('RefPomer')->length > 0;
                    $checked = true;
                }
                $ok = $reader->next();
            }
        } finally {
            $reader->close();
        }

        return self::variant($people, 0, $withRelation);
    }

    /**
     * Soubor mezd v tvaru PAMICA: u mezd z PAMICA `null` (převod čte původní soubor),
     * u mezd z POHODY převedený soubor vedle exportu a podklady pro protokol a kontrolu.
     *
     * @return ?array{file:string, months:array<string,array{payslips:int,metrics:array<string,int>,relations:list<string>}>,
     *   failures:array<string,list<string>>, unconverted:list<array{period:string,unconverted_items:array<string,array{code:string,name:string,kind:string,inputs:int,amount:float,hours:float}>}>,
     *   notes:array<string,int>}
     */
    public static function prepare(string $file): ?array
    {
        if (self::detect($file) !== self::VARIANT_POHODA) {
            return null;
        }
        $people = [];
        $payslips = [];
        /** @var array<string,list<array<string,mixed>>> $periods osoba => doby z karty */
        $periods = [];
        /** @var array<string,array<string,float>> $compensations mzda => doba na kartě => náhrada mzdy */
        $compensations = [];
        $notes = ['standing_deductions' => 0, 'posting_rows' => 0, 'duplicate_payslips' => 0, 'periods_outside_payslips' => 0];
        foreach (PohodaXml::scan($file, ['ZAM', 'MZ', 'ZAMpDov', 'MZnahr', 'ZAMpSra', 'MZzauct']) as $table => $row) {
            match ($table) {
                'ZAM' => $people[PohodaXml::text($row, 'ID')] = $row,
                'MZ' => $payslips[] = $row,
                'ZAMpDov' => $periods[PohodaXml::text($row, 'RefAg')][] = $row,
                'MZnahr' => $compensations[PohodaXml::text($row, 'RefAg')][PohodaXml::text($row, 'RefZAMpDov')]
                    = ($compensations[PohodaXml::text($row, 'RefAg')][PohodaXml::text($row, 'RefZAMpDov')] ?? 0.0) + PohodaXml::num($row, 'Kc'),
                'ZAMpSra' => $notes['standing_deductions']++,
                default => $notes['posting_rows']++,
            };
        }

        $maps = [];
        $months = [];
        $failures = [];
        $unconverted = [];
        $seen = [];
        $placed = [];
        $year = 0;
        foreach ($payslips as $mz) {
            $year = max($year, (int) PohodaXml::text($mz, 'Rok'));
            $id = PohodaXml::text($mz, 'ID');
            $personId = PohodaXml::text($mz, 'RefZAM');
            $period = sprintf('%04d-%02d', (int) PohodaXml::text($mz, 'Rok'), (int) PohodaXml::text($mz, 'RelMes'));
            if (isset($seen[$personId . '|' . $period])) {
                $notes['duplicate_payslips']++;
            }
            $seen[$personId . '|' . $period] = true;
            $pieces = [];
            foreach ($periods[$personId] ?? [] as $index => $card) {
                $piece = self::piece($card, $period);
                if ($piece !== null) {
                    $pieces[] = $piece;
                    $placed[$personId . '|' . $index] = true;
                }
            }
            $map = PohodaMzdyPayslip::map($mz, $pieces, $compensations[$id] ?? [], $people[$personId] ?? null);
            $maps[$id] = $map;
            $check = PohodaMzdyReconciliation::payslip($mz, $map, array_sum($compensations[$id] ?? []));
            $months[$period] ??= ['payslips' => 0, 'metrics' => array_fill_keys(array_keys(PohodaMzdyReconciliation::METRICS), 0), 'relations' => []];
            $months[$period]['payslips']++;
            foreach ($check['metrics'] as $metric => $value) {
                $months[$period]['metrics'][$metric] += $value;
            }
            $months[$period]['relations'][] = $personId;
            if ($check['failures'] !== []) {
                $failures[$period] ??= [];
                array_push($failures[$period], ...$check['failures']);
            }
            $unconverted[$period] ??= ['period' => $period, 'unconverted_items' => []];
            foreach ($map['unconverted'] as $key => $item) {
                $existing = $unconverted[$period]['unconverted_items'][$key] ?? null;
                $unconverted[$period]['unconverted_items'][$key] = $existing === null ? $item : [
                    'code' => $item['code'], 'name' => $item['name'], 'kind' => $item['kind'],
                    'inputs' => $existing['inputs'] + $item['inputs'],
                    'amount' => round($existing['amount'] + $item['amount'], 2),
                    'hours' => round($existing['hours'] + $item['hours'], 2),
                ];
            }
        }
        foreach ($periods as $personId => $cards) {
            foreach (array_keys($cards) as $index) {
                if (!isset($placed[$personId . '|' . $index])) {
                    $notes['periods_outside_payslips']++;
                }
            }
        }
        foreach ($months as $period => $month) {
            $months[$period]['relations'] = array_values(array_unique($month['relations']));
        }
        ksort($months);
        ksort($unconverted);
        foreach ($unconverted as $period => $month) {
            ksort($unconverted[$period]['unconverted_items']);
        }

        $target = dirname($file) . DIRECTORY_SEPARATOR . self::ADAPTED_FILE;
        self::write($file, $target, $people, $maps, $year);

        return ['file' => $target, 'months' => $months, 'failures' => $failures,
            'unconverted' => array_values($unconverted), 'notes' => $notes];
    }

    /**
     * Doba z karty zaměstnance oříznutá na měsíc mzdy, nebo `null`, když do něj nezasahuje.
     * Celá doba v měsíci nese počet dnů POHODY (`Dnu`), oříznutá pracovní dny své části.
     *
     * @param array<string,mixed> $card řádek `ZAMpDov`
     * @return ?array<string,mixed> řádek doplněný o `_id`, `_from`, `_to`, `_days`, `_calendar_days`
     */
    private static function piece(array $card, string $period): ?array
    {
        $from = PohodaXml::date($card, 'DatZac');
        $to = PohodaXml::date($card, 'DatKon');
        if ($from === null || $to === null || $to < $from || (int) substr($from, 0, 4) < 1901) {
            return null;
        }
        $first = $period . '-01';
        $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        if ($to < $first || $from > $last) {
            return null;
        }
        $clipped = $from < $first || $to > $last;
        $pieceFrom = max($from, $first);
        $pieceTo = min($to, $last);

        return $card + [
            '_id' => PohodaXml::text($card, 'ID') . ($clipped ? '-' . $period : ''),
            '_from' => $pieceFrom,
            '_to' => $pieceTo,
            '_days' => $clipped ? (float) PohodaMzdyPayslip::weekdays($pieceFrom, $pieceTo) : PohodaXml::num($card, 'Dnu'),
            '_calendar_days' => (float) ((new \DateTimeImmutable($pieceFrom))->diff(new \DateTimeImmutable($pieceTo))->days + 1),
        ];
    }

    /**
     * Zapíše soubor v tvaru PAMICA: tabulky, které převod čte, beze změny, mzdy a podání
     * s vazbou na poměr a k tomu poměry, číselníky, položky mezd a karty dovolené.
     *
     * @param array<string,array<string,mixed>> $people
     * @param array<string,array{components:list<array<string,mixed>>,absences:list<array<string,mixed>>,mz:array<string,string>}> $maps mzda => rozklad
     * @param int $payslipYear rok mezd exportu pro kartu dovolené, když ho kořen exportu nenese
     */
    private static function write(string $source, string $target, array $people, array $maps, int $payslipYear): void
    {
        $passthrough = array_fill_keys(self::PASSTHROUGH_TABLES, true);
        $relationColumns = array_combine(self::RELATION_TABLES, self::RELATION_COLUMNS);
        $reader = new \XMLReader();
        if (!$reader->open($source, null, LIBXML_NONET)) {
            throw new PohodaException('export_unreadable', 'Soubor exportu ' . basename($source) . ' nejde otevřít jako XML.');
        }
        // Vlastní dočasný soubor: souběžný náhled a převod téhož exportu si ho nepřepíšou.
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $writer = new \XMLWriter();
        if (!$writer->openUri($tmp)) {
            $reader->close();
            throw new \RuntimeException('Převedené mzdy nejde zapsat do ' . basename($target) . '.');
        }
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $year = null;
        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    throw new PohodaException('export_doctype', 'Soubor exportu ' . basename($source) . ' obsahuje DOCTYPE, což není povoleno.');
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 0) {
                    $writer->startElement($reader->localName);
                    while ($reader->moveToNextAttribute()) {
                        $writer->writeAttribute($reader->name, $reader->value);
                        if ($reader->name === 'year' && preg_match('/^\d{4}$/D', $reader->value) === 1) {
                            $year = (int) $reader->value;
                        }
                    }
                    $reader->moveToElement();
                    $writer->writeAttribute(self::ADAPTED_ATTRIBUTE, self::VARIANT_POHODA);
                    $ok = $reader->read();
                    continue;
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1) {
                    $name = $reader->localName;
                    if ($name === 'MZ') {
                        $node = $reader->expand();
                        if ($node instanceof \DOMElement) {
                            $id = self::childText($node, 'ID');
                            $writer->writeRaw("\n" . self::withColumns($node, $maps[$id]['mz'] ?? []));
                        }
                    } elseif (isset($relationColumns[$name])) {
                        $node = $reader->expand();
                        if ($node instanceof \DOMElement) {
                            $writer->writeRaw("\n" . self::withColumns($node, self::childText($node, 'RefPomer') === ''
                                ? ['RefPomer' => self::childText($node, $relationColumns[$name])] : []));
                        }
                    } elseif (isset($passthrough[$name])) {
                        $writer->writeRaw("\n" . $reader->readOuterXml());
                    }
                    $ok = $reader->next();
                    continue;
                }
                $ok = $reader->read();
            }

            foreach ($people as $id => $person) {
                self::row($writer, 'ZAMpomer', self::relation((string) $id, $person));
            }
            foreach (self::CATALOG as $table => $items) {
                foreach ($items as $number => [$name, $tax, $social, $health]) {
                    $row = ['ID' => self::catalogId($number), 'Cislo' => $number, 'Nazev' => $name];
                    $row += $table === 'sMZslozky' ? ['RelTpDan' => $tax, 'JeSoc' => $social, 'JeZdr' => $health] : ['JeNemDav' => $health];
                    self::row($writer, $table, $row);
                }
            }
            $item = 0;
            foreach ($maps as $payslipId => $map) {
                foreach ($map['components'] as $component) {
                    self::row($writer, 'MZslozky', [
                        'ID' => 'slozka-' . (++$item), 'RefAg' => (string) $payslipId, 'RefSlozka' => self::catalogId($component['number']),
                        'KcMzda' => self::number($component['amount']), 'PocHodin' => self::number($component['hours']),
                        'Hodnota1' => self::number($component['rate']), 'Hodnota3' => self::number($component['units']), 'Hodnota4' => '0',
                    ]);
                }
                foreach ($map['absences'] as $absence) {
                    self::row($writer, 'MZneprit', array_filter([
                        'ID' => $absence['id'], 'RefAg' => (string) $payslipId, 'RefSlozka' => self::catalogId($absence['number']),
                        'HodPrac' => self::number($absence['hours']), 'KcNahr' => self::number($absence['amount']),
                        'DnyPrac' => self::number($absence['days']), 'DnyKal' => self::number($absence['calendar_days']),
                        'DatZac' => $absence['from'] === null ? null : $absence['from'] . 'T00:00:00',
                        'DatKon' => $absence['to'] === null ? null : $absence['to'] . 'T00:00:00',
                    ], static fn (?string $value): bool => $value !== null));
                }
            }
            foreach ($people as $id => $person) {
                $leave = ['ID' => 'dovolena-' . $id, 'RefAg' => (string) $id, 'Rok' => (string) ($year ?? $payslipYear)];
                $any = false;
                foreach (self::LEAVE_COLUMNS as $index => $column) {
                    $leave[self::LEAVE_TARGETS[$index]] = self::number(PohodaXml::num($person, $column));
                    $any = $any || PohodaXml::num($person, $column) != 0.0;
                }
                if ($any) {
                    self::row($writer, 'Dovolena', $leave);
                }
            }
            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
        } catch (\Throwable $e) {
            $writer->flush();
            @unlink($tmp);
            throw $e;
        } finally {
            $reader->close();
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Převedené mzdy nejde zapsat do ' . basename($target) . '.');
        }
    }

    /**
     * Pracovní poměr PAMICA z karty zaměstnance POHODY: všechny údaje karty (nástup,
     * druh, úvazek, ID PPV a další mají v obou programech stejná jména) a k nim vazba
     * na osobu a sloupce, které POHODA jmenuje jinak.
     *
     * @param array<string,mixed> $person
     * @return array<string,string>
     */
    private static function relation(string $id, array $person): array
    {
        $row = [];
        foreach ($person as $column => $value) {
            if (is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', (string) $column) === 1) {
                $row[(string) $column] = $value;
            }
        }
        $isco = PohodaXml::text($person, 'ProfeseISCO');

        return array_merge($row, array_filter([
            'ID' => $id,
            'RefZAM' => $id,
            'Poradi' => '1',
            // Druh zaměstnání má v obou programech týž číselník; 108 je dohoda o provedení práce.
            'JeDPP' => trim(PohodaXml::text($person, 'RelDruhZ')) === '108' ? '1' : '0',
            'ResStr' => PohodaXml::text($person, 'RefStr'),
            'ResCisCZISCO' => preg_match('/^[0-9]{4,5}$/D', $isco) === 1 ? $isco : '',
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * Element řádku s doplněnými nebo přepsanými sloupci.
     *
     * @param array<string,string> $columns
     */
    private static function withColumns(\DOMElement $node, array $columns): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->importNode($node, true);
        $doc->appendChild($element);
        foreach ($columns as $column => $value) {
            $existing = null;
            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === $column) {
                    $existing = $child;
                    break;
                }
            }
            if ($existing !== null) {
                $existing->textContent = $value;
            } else {
                $added = $doc->createElement($column);
                $added->appendChild($doc->createTextNode($value));
                $element->appendChild($added);
            }
        }

        return (string) $doc->saveXML($element);
    }

    private static function childText(\DOMElement $node, string $column): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $column) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    /** @param array<string,string> $row */
    private static function row(\XMLWriter $writer, string $table, array $row): void
    {
        $writer->startElement($table);
        foreach ($row as $column => $value) {
            $writer->writeElement($column, $value);
        }
        $writer->endElement();
    }

    private static function catalogId(string $number): string
    {
        return 'pohoda-' . $number;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
