<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;

/**
 * Bloky `vydelekOZP` (10347 až 10349) a `srazky` (10350 až 10353) v čisté mzdě
 * souhrnných dat zaměstnance.
 *
 * XSD 1.4.3.6 je vede jen v `mzdaCistaBezPriznakuType` a
 * `mzdaCistaOdlozenyPrijemType` (formBezPriznaku.xsd, formOdlozenyPrijem.xsd),
 * obojí `minOccurs=0`, v pořadí za `srazkyZeMzdyEvidovany`. Formulář vězně ani
 * činnosti KS je nemá (matice scénářů 1.4.0.2 tyto atributy nevede).
 *
 *  - `vydelekOZP` jen při interakci IN28 (podpora zaměstnávání OZP); všechny
 *    tři prvky jsou v něm povinné, datum úhrady 10347 se nikdy nedomýšlí.
 *  - `srazky` jen při IN24 (10349 = ANO), a s aspoň jednou částkou: kontrola
 *    258 katalogu MH 1.4.2.10 („10349 = ANO, pak musí být uvedena alespoň
 *    jedna hodnota z 10350 až 10353"). Nulová částka se nevypisuje.
 */
final class JmhzOzpWageXmlWriter
{
    private const DEDUCTION_ELEMENTS = [
        '10350' => 'form:srazkaPlneniZel',
        '10351' => 'form:srazkaSkodaZec',
        '10352' => 'form:srazkaStrava',
        '10353' => 'form:srazkaZavazkyZec',
    ];

    /**
     * @param array<string,mixed> $summary souhrn osoby z normalizovaného dokumentu
     */
    public static function append(DOMDocument $dom, DOMElement $net, array $summary, string $form): void
    {
        $wage = $summary['ozp_wage'] ?? null;
        if ($wage === null) {
            return;
        }
        if ($form !== 'bezPriznaku') {
            self::invalid(
                'jmhz_xml_ozp_wage_form_unsupported',
                'Výdělek osob OZP a srážky ve prospěch zaměstnavatele (10347 až 10353)'
                    . ' formulář tohoto druhu nevede.',
            );
        }
        if (!is_array($wage)) {
            self::unresolved('10347');
        }
        $paymentDate = $wage['payment_date'] ?? null;
        $date = is_string($paymentDate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $paymentDate) {
            self::unresolved('10347');
        }
        if (!is_bool($wage['cash_paid'] ?? null)) {
            self::unresolved('10348');
        }
        $made = $wage['employer_deductions_made'] ?? null;
        $amounts = $wage['employer_deductions_czk'] ?? null;
        if (!is_bool($made) || !is_array($amounts)) {
            self::unresolved('10349');
        }
        $deductions = [];
        foreach ($amounts as $target => $amount) {
            $target = (string) $target;
            if (!isset(self::DEDUCTION_ELEMENTS[$target]) || !is_int($amount) || $amount < 0) {
                self::unresolved($target);
            }
            if ($amount > 0) {
                $deductions[$target] = $amount;
            }
        }
        if ($made !== ($deductions !== [])) {
            self::invalid(
                'jmhz_xml_ozp_deductions_inconsistent',
                'Příznak srážek ve prospěch zaměstnavatele (10349) neodpovídá částkám 10350 až 10353'
                    . ' (kontrola 258).',
            );
        }

        $block = $dom->createElementNS(JmhzSchemaCatalog::NS_FORM, 'form:vydelekOZP');
        self::text($dom, $block, 'form:mzdaOzpUhradaDatum', $paymentDate);
        self::text($dom, $block, 'form:mzdaOzpHotovost', $wage['cash_paid'] ? 'true' : 'false');
        self::text($dom, $block, 'form:mzdaOzpSrazka', $made ? 'true' : 'false');
        $net->appendChild($block);
        if ($deductions === []) {
            return;
        }
        $block = $dom->createElementNS(JmhzSchemaCatalog::NS_FORM, 'form:srazky');
        foreach (self::DEDUCTION_ELEMENTS as $target => $element) {
            if (isset($deductions[$target])) {
                self::text($dom, $block, $element, (string) $deductions[$target]);
            }
        }
        $net->appendChild($block);
    }

    private static function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $node = $dom->createElementNS(JmhzSchemaCatalog::NS_FORM, $name);
        $node->appendChild($dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private static function unresolved(string $attributeId): never
    {
        self::invalid(
            'jmhz_xml_attribute_unresolved',
            "Atribut {$attributeId} není ve zmrazeném dokumentu doložený, "
                . 'a nesmí se proto doplnit nulou ani nepravdou.',
        );
    }

    private static function invalid(string $code, string $message): never
    {
        throw new JmhzXmlException($code, $message);
    }
}
