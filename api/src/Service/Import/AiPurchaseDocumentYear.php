<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use DateTimeImmutable;
use MyInvoice\Http\IsoDate;

final class AiPurchaseDocumentYear
{
    private const DOCUMENT_DATES = ['issue_date', 'tax_date', 'received_at'];
    private const OTHER_DATES = ['due_date', 'delivery_date'];
    private const FUTURE_TOLERANCE_DAYS = 3;

    /**
     * Kontroluje datum vystavení, DUZP a datum přijetí proti dni nahrání.
     * Podezřelé je datum starší než 12 měsíců, před známým začátkem účetnictví
     * nebo více než tři dny v budoucnosti. Rok opraví ve všech vytěžených datech
     * se stejným chybným rokem, pokud čitelné datum se shodným dnem a měsícem
     * v textu dokladu nebo názvu souboru potvrzuje právě jeden přípustný rok
     * a upravená data jsou platná. Datum vystavení, DUZP ani datum přijetí
     * po opravě nesmí zůstat mimo uvedené meze.
     * Každý podezřelý rok dostane upozornění ke kontrole. Den nahrání určuje
     * časové meze, sám o sobě opravu neodůvodňuje.
     *
     * @param array<string,mixed> $data
     * @param callable(): ?string $documentText
     * @return array{data:array<string,mixed>, warning:?string}
     */
    public static function check(
        array $data,
        string $uploadedOn,
        callable $documentText,
        ?string $originalFilename = null,
        ?string $accountingStart = null,
    ): array {
        $upload = new DateTimeImmutable($uploadedOn);
        $previousYear = $upload->modify('-1 year');
        if ($previousYear->format('m') !== $upload->format('m')) {
            $previousYear = $previousYear->modify('last day of previous month');
        }
        $oldest = $previousYear->format('Y-m-d');
        $earliest = max($oldest, $accountingStart ?? $oldest);
        $latest = $upload->modify('+' . self::FUTURE_TOLERANCE_DAYS . ' days')->format('Y-m-d');

        $suspect = [];
        foreach (self::DOCUMENT_DATES as $field) {
            $date = $data[$field] ?? null;
            if (is_string($date) && IsoDate::isValid($date) && ($date < $earliest || $date > $latest)) {
                $suspect[substr($date, 0, 4)][substr($date, 5)] = $date;
            }
        }
        if ($suspect === []) {
            return ['data' => $data, 'warning' => null];
        }

        $candidates = [];
        foreach ([[$documentText() ?? '', 'na dokladu'], [$originalFilename ?? '', 'v názvu souboru']] as [$text, $source]) {
            foreach (self::datesIn($text) as $date) {
                if ($date >= $earliest && $date <= $latest) {
                    $year = substr($date, 0, 4);
                    $monthDay = substr($date, 5);
                    foreach ($suspect as $wrongYear => $dates) {
                        if ((string) $wrongYear !== $year && isset($dates[$monthDay])) {
                            $candidates[$wrongYear][$year] ??= [$date, $source];
                        }
                    }
                }
            }
        }

        $corrected = $data;
        $warnings = [];
        foreach ($suspect as $wrongYear => $dates) {
            $years = $candidates[$wrongYear] ?? [];
            if (count($years) === 1) {
                [$date, $source] = reset($years);
                $year = substr($date, 0, 4);
                $replacements = self::replaceYear($data, (string) $wrongYear, $year, $earliest, $latest);
                if ($replacements !== null) {
                    $corrected = array_replace($corrected, $replacements);
                    $warnings[] = sprintf(
                        'Rok data dokladu AI načetla jako %s, podle data %s %s je opraven na %s. Zkontrolujte prosím data.',
                        $wrongYear, (new DateTimeImmutable($date))->format('j.n.Y'), $source, $year,
                    );
                    continue;
                }
            }
            $date = reset($dates);
            $reason = match (true) {
                $date < $oldest => 'je o víc než rok starší než nahrání dokladu',
                $date < $earliest => 'předchází začátku účetnictví firmy',
                default => 'je více než tři dny po nahrání dokladu',
            };
            $warnings[] = sprintf(
                'Datum dokladu %s %s, zkontrolujte prosím rok.',
                (new DateTimeImmutable($date))->format('j. n. Y'), $reason,
            );
        }

        return ['data' => $corrected, 'warning' => implode("\n\n", $warnings)];
    }

    /** @return list<string> */
    private static function datesIn(string $text): array
    {
        preg_match_all('/(?<!\d)(?:\d{4}-\d{2}-\d{2}|\d{1,2}\h*\.\h*\d{1,2}\h*\.\h*\d{4})(?!\d)/u', $text, $matches);
        $dates = [];
        foreach ($matches[0] ?? [] as $date) {
            if (str_contains($date, '.')) {
                [$day, $month, $year] = explode('.', $date);
                $date = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
            }
            if (IsoDate::isValid($date)) {
                $dates[] = $date;
            }
        }
        return $dates;
    }

    /** @return array<string,string>|null */
    private static function replaceYear(array $data, string $wrongYear, string $year, string $earliest, string $latest): ?array
    {
        $replacements = [];
        foreach ([...self::DOCUMENT_DATES, ...self::OTHER_DATES] as $field) {
            $date = $data[$field] ?? null;
            if (!is_string($date) || substr($date, 0, 4) !== $wrongYear || !IsoDate::isValid($date)) {
                continue;
            }
            $replacement = $year . substr($date, 4);
            if (!IsoDate::isValid($replacement)
                || (in_array($field, self::DOCUMENT_DATES, true) && ($replacement < $earliest || $replacement > $latest))
            ) {
                return null;
            }
            $replacements[$field] = $replacement;
        }
        return $replacements;
    }
}
