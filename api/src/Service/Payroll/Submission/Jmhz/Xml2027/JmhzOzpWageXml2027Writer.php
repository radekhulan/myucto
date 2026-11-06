<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Bloky `vydelekOZP` a `srazky` čisté mzdy ve verzi 2027
 * (`vydelekOsobOZPType`, `srazkyType` v `formCommonTypes.xsd` 1.5.0.3).
 *
 * Proti verzi 2026 ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOzpWageXmlWriter})
 * zanikl 10347 a `vydelekOZP` nese v tomto pořadí datum úhrady mzdy 10589,
 * datum odvodu sociálního pojištění 10570 a zdravotního pojištění 10571
 * (ZP 128), teprve potom 10348 a 10349. Všech pět je při IN28 povinných
 * a žádné datum se nedomýšlí. Srážky (IN24, kontrola 258) beze změny.
 * Blok vedou jen formuláře bez příznaku a odloženého příjmu.
 */
final class JmhzOzpWageXml2027Writer
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
        if (!in_array($form, ['bezPriznaku', 'odlozenyPrijem'], true)) {
            self::invalid(
                'jmhz_xml_ozp_wage_form_unsupported',
                'Výdělek osob OZP a srážky ve prospěch zaměstnavatele (10589, 10570, 10571,'
                    . ' 10348 až 10353) formulář tohoto druhu nevede.',
            );
        }
        if (!is_array($wage)) {
            self::unresolved('10589');
        }
        $dates = [
            'form:mzdaOzpUhradaDatum' => self::date($wage['payment_date'] ?? null, '10589'),
            'form:mzdaOzpSocPojDatum' => self::date(
                $wage[Jmhz2027DocumentContract::OZP_SOCIAL_PAID_ON] ?? null,
                '10570',
            ),
            'form:mzdaOzpZdravPojDatum' => self::date(
                $wage[Jmhz2027DocumentContract::OZP_HEALTH_PAID_ON] ?? null,
                '10571',
            ),
        ];
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

        $block = $dom->createElementNS(Jmhz2027Schema::NS_FORM, 'form:vydelekOZP');
        foreach ($dates as $element => $value) {
            self::text($dom, $block, $element, $value);
        }
        self::text($dom, $block, 'form:mzdaOzpHotovost', $wage['cash_paid'] ? 'true' : 'false');
        self::text($dom, $block, 'form:mzdaOzpSrazka', $made ? 'true' : 'false');
        $net->appendChild($block);
        if ($deductions === []) {
            return;
        }
        $block = $dom->createElementNS(Jmhz2027Schema::NS_FORM, 'form:srazky');
        foreach (self::DEDUCTION_ELEMENTS as $target => $element) {
            if (isset($deductions[$target])) {
                self::text($dom, $block, $element, (string) $deductions[$target]);
            }
        }
        $net->appendChild($block);
    }

    private static function date(mixed $value, string $attributeId): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            self::unresolved($attributeId);
        }

        return $value;
    }

    private static function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $node = $dom->createElementNS(Jmhz2027Schema::NS_FORM, $name);
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
