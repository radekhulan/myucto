<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Ukázkový export docházky pro PAMICA (XML „Docházka zaměstnanců") ke stažení
 * u Vzoru PAMICA. Tři smyšlené osoby za zvolený měsíc ukazují prvky schématu,
 * které import převádí: rozvrh podle pracovních dnů a svátků, dovolená, nemoc,
 * OČR, zkrácený den nepřítomnosti, přesčas a nadúvazek (i v obalu `slozka`),
 * příplatky za noc a víkend, prémie, osobní ohodnocení, stravenkový paušál,
 * úkolová mzda a dohoda o provedení práce. Žádná data firmy ani skutečných
 * osob; vše vzniká tady z kódu.
 */
final class AttendancePamicaTemplate
{
    public const MIME = 'application/xml';
    public const FILENAME_PREFIX = 'pamica-dochazka-vzor-';

    private const WAGE_ORDER = [
        'mzda', 'premie', 'osobni_ohodnoceni', 'priplatek', 'stravenkovy_pausal',
        'stravenkovy_pausal_bez_limitu', 'osvobozene_prijmy', 'obecna_slozka',
    ];

    /** @return array{bytes:string,filename:string,mime:string} */
    public function build(string $period): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $period, $match) !== 1) {
            throw new \InvalidArgumentException('Období šablony musí být ve tvaru RRRR-MM.');
        }
        $year = (int) $match[1];
        $month = (int) $match[2];
        $holidays = CzechWorkingDays::holidaysForYear($year);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $days = [];
        for ($day = $first; (int) $day->format('n') === $month; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }
        $workdays = array_values(array_filter(
            $days,
            static fn (\DateTimeImmutable $day): bool => (int) $day->format('N') <= 5 && !isset($holidays[$day->format('Y-m-d')]),
        ));
        $pick = static fn (int $index): string => $workdays[min($index, count($workdays) - 1)]->format('Y-m-d');

        $people = [
            [
                'first' => 'Eva', 'last' => 'Ukázková', 'relation' => 'Pracovní poměr', 'number' => '90001',
                'hours' => static fn (\DateTimeImmutable $day): int => (int) $day->format('N') <= 5 ? 480 : 0,
                // Placené volno jen 4 hodiny (zkrácený jednodenní záznam).
                'absences' => [['V01', $pick(2), $pick(3)], ['V03', $pick(8), $pick(8), '04:00']],
                'presence' => [['prescas_pracovni_den', 150, false]],
                'wages' => [
                    ['priplatek', ['kod' => 'P07', 'nazev' => 'Příplatek za práci v noci', 'hodiny' => '16:00']],
                    ['premie', ['castka' => '1500.00']],
                    ['stravenkovy_pausal', ['jednic' => '19', 'castka' => '150.00']],
                ],
            ],
            [
                'first' => 'Adam', 'last' => 'Příkladný', 'relation' => 'Pracovní poměr', 'number' => '90002',
                'hours' => static fn (\DateTimeImmutable $day): int => (int) $day->format('N') <= 5 ? 480 : 0,
                'absences' => [['N01', $pick(5), $pick(9)], ['N05', $pick(14), $pick(15)]],
                'presence' => [['prescas_den_klidu', 240, true]],
                'wages' => [
                    ['priplatek', ['kod' => 'P04', 'nazev' => 'Příplatek za práci v sobotu a neděli', 'hodiny' => '04:00']],
                    ['osobni_ohodnoceni', ['castka' => '2000.00']],
                ],
            ],
            [
                'first' => 'Jana', 'last' => 'Vzorová', 'relation' => 'Dohoda o provedení práce', 'number' => '90003',
                'hours' => static fn (\DateTimeImmutable $day): int => in_array((int) $day->format('N'), [1, 3], true) ? 240 : 0,
                'absences' => [],
                'presence' => [['naduvazek_pracovni_den', 120, true]],
                'wages' => [['mzda', ['nazev' => 'Úkolová mzda', 'castka' => '12.50', 'jednic' => '120']]],
            ],
        ];

        $document = new \DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(AttendancePamicaExport::NAMESPACE, AttendancePamicaExport::ROOT);
        $root->setAttribute('version', '1.0');
        $document->appendChild($root);
        foreach ($people as $person) {
            $employee = $this->append($root, 'dochazka_zamestnance');
            $header = $this->append($employee, 'hlavicka');
            $this->append($header, 'mesic', (string) $month);
            $this->append($header, 'rok', (string) $year);
            $this->append($header, 'jmeno', $person['first']);
            $this->append($header, 'prijmeni', $person['last']);
            $this->append($header, 'pracovni_pomer', $person['relation']);
            $this->append($header, 'cislo_pracovniho_pomeru', $person['number']);

            $schedule = $this->append($employee, 'rozvrh');
            $holidayDays = [];
            foreach ($days as $day) {
                $minutes = $person['hours']($day);
                $date = $day->format('Y-m-d');
                $this->append($schedule, 'uvazek', self::clock($minutes))->setAttribute('datum', $date);
                if ($minutes > 0 && isset($holidays[$date])) {
                    $holidayDays[] = $date;
                }
            }

            $absences = $this->append($employee, 'nepritomnosti');
            $items = $person['absences'];
            foreach ($holidayDays as $date) {
                $items[] = ['V02', $date, $date];
            }
            usort($items, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
            foreach ($items as $item) {
                [$code, $from, $to] = $item;
                $absence = $this->append($absences, 'nepritomnost');
                $this->append($absence, 'kod', $code);
                $start = $this->append($absence, 'od', $from);
                if (isset($item[3])) {
                    $start->setAttribute('nepritomnost', $item[3]);
                }
                $this->append($absence, 'do', $to);
            }

            $presence = $this->append($employee, 'pritomnost');
            foreach ($person['presence'] as [$kind, $minutes, $wrapped]) {
                $overtime = $this->append($wrapped ? $this->append($presence, 'slozka') : $presence, $kind);
                $this->append($overtime, 'hodiny', self::clock($minutes));
                $this->append($overtime, 'interni_poznamka', 'Ukázka');
            }
            $wages = $this->append($employee, 'mzdy');
            // Schéma bez obalu `slozka` vyžaduje pevné pořadí druhů složek.
            $wageItems = $person['wages'];
            usort($wageItems, static fn (array $a, array $b): int => array_search($a[0], self::WAGE_ORDER, true) <=> array_search($b[0], self::WAGE_ORDER, true));
            foreach ($wageItems as [$kind, $fields]) {
                $wage = $this->append($wages, $kind);
                foreach ($fields as $field => $value) {
                    $this->append($wage, $field, $value);
                }
            }
        }

        return [
            'bytes' => "\u{FEFF}" . (string) $document->saveXML(),
            'filename' => self::FILENAME_PREFIX . $period . '.xml',
            'mime' => self::MIME,
        ];
    }

    private function append(\DOMElement $parent, string $name, ?string $text = null): \DOMElement
    {
        $element = $parent->ownerDocument?->createElementNS(AttendancePamicaExport::NAMESPACE, $name)
            ?? throw new \LogicException('Prvek šablony nemá dokument.');
        if ($text !== null) {
            $element->appendChild(new \DOMText($text));
        }
        $parent->appendChild($element);

        return $element;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
