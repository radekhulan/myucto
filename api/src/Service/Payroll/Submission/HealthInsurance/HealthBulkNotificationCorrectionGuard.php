<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Pozná, že nové hromadné oznámení (HOZ) téhož období a pojišťovny opravuje
 * větu, kterou pojišťovna už dostala.
 *
 * XSD rev. 08 a poučení k HOZ: chybné číslo pojištěnce se ruší kódem X (a nová
 * věta P), chybné datum přihlášky (P, A, E, C) se opravuje kódem Y, chybné
 * datum odhlášky (O, Q) kódem Z. Aplikace tyto kódy nevyrábí: dokumenty neříkají,
 * které datum oprava nese. Bez téhle kontroly ale oprava na kartě vyrobila
 * nové HOZ s druhou větou P nebo O, tedy duplicitní přihlášku či odhlášku.
 * Proto se taková příprava zastaví a účetní podá opravu ručně.
 */
final class HealthBulkNotificationCorrectionGuard
{
    private const ARRIVAL_CODES = ['P', 'A', 'E', 'C'];
    private const DEPARTURE_CODES = ['O', 'Q'];

    /**
     * Věty `zmenaZamestance` z XML dříve doručeného HOZ.
     *
     * @return list<array{code:string,date:string,number:string,first:string,last:string}>
     */
    public static function linesFromXml(string $xml): array
    {
        $document = new \DOMDocument();
        if ($xml === '' || !@$document->loadXML($xml, LIBXML_NONET)) {
            throw new HealthNotificationException(
                'zp_bulk_notification_previous_unreadable',
                'Dříve odeslané hromadné oznámení téhož období a pojišťovny nejde přečíst; '
                    . 'nové proto nelze porovnat a nesestaví se.',
            );
        }
        $lines = [];
        foreach ($document->getElementsByTagNameNS('*', 'zmenaZamestance') as $element) {
            $value = static function (string $name) use ($element): string {
                $nodes = $element->getElementsByTagNameNS('*', $name);

                return $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : '';
            };
            $lines[] = [
                'code' => $value('kodzmeny'),
                'date' => $value('datumZmeny'),
                'number' => $value('cisloPojistence'),
                'first' => $value('jmeno'),
                'last' => $value('prijmeni'),
            ];
        }

        return $lines;
    }

    /**
     * Rozpory nových vět s doručenými: osoba (příjmení a jméno) se stejným
     * kódem změny a jiným číslem pojištěnce (oprava X) nebo jiným datem
     * (oprava Y, resp. Z).
     *
     * @param list<array{code:string,date:string,number:string,first:string,last:string}> $delivered
     * @param list<HealthNotificationChange> $changes
     * @return list<array{last:string,first:string,code:string,correction:string,was:string,now:string}>
     */
    public static function conflicts(array $delivered, array $changes): array
    {
        $conflicts = [];
        foreach ($delivered as $old) {
            foreach ($changes as $change) {
                if ($change->changeCode !== $old['code']
                    || mb_strtolower($change->lastName) !== mb_strtolower($old['last'])
                    || mb_strtolower($change->firstName) !== mb_strtolower($old['first'])
                ) {
                    continue;
                }
                $sameDate = $change->changedOn === $old['date'];
                $sameNumber = $change->insuranceNumber === $old['number'];
                if ($sameDate && $sameNumber) {
                    continue 2;
                }
                if ($sameDate) {
                    $conflicts[] = self::conflict($old, 'X', $old['number'], $change->insuranceNumber);
                } elseif ($sameNumber) {
                    $correction = in_array($old['code'], self::ARRIVAL_CODES, true)
                        ? 'Y'
                        : (in_array($old['code'], self::DEPARTURE_CODES, true) ? 'Z' : '');
                    $conflicts[] = self::conflict($old, $correction, $old['date'], $change->changedOn);
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{last:string,first:string,code:string,correction:string,was:string,now:string}> $conflicts
     */
    public static function exception(array $conflicts): HealthNotificationException
    {
        $parts = array_map(
            static fn (array $c): string => sprintf(
                '%s %s (věta %s: dříve „%s“, nyní „%s“%s)',
                $c['last'],
                $c['first'],
                $c['code'],
                $c['was'],
                $c['now'],
                $c['correction'] === '' ? '' : ', oprava kódem ' . $c['correction'],
            ),
            $conflicts,
        );

        return new HealthNotificationException(
            'zp_bulk_notification_correction_required',
            'Pojišťovna už dostala hromadné oznámení za toto období s jinými údaji: '
                . implode('; ', $parts) . '. Nové oznámení by poslalo druhou přihlášku nebo '
                . 'odhlášku. Opravu podejte ručně opravnou větou X, Y nebo Z podle poučení '
                . 'pojišťovny; aplikace opravné kódy nevyrábí.',
        );
    }

    /**
     * @param array{code:string,date:string,number:string,first:string,last:string} $old
     * @return array{last:string,first:string,code:string,correction:string,was:string,now:string}
     */
    private static function conflict(array $old, string $correction, string $was, string $now): array
    {
        return [
            'last' => $old['last'],
            'first' => $old['first'],
            'code' => $old['code'],
            'correction' => $correction,
            'was' => $was,
            'now' => $now,
        ];
    }
}
