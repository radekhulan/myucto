<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

use MyInvoice\Service\Eet\EetDelegation;

/**
 * Sestaví element `Trzba` (XSD 4.1) z údajů tržby a hlavičky pokusu.
 *
 * Formáty podle kap. 3.3.3 popisu rozhraní: čas vždy jako místní čas ČR s offsetem
 * (`+01:00` SEČ / `+02:00` SELČ, bez zlomků sekund), částky z haléřů s právě dvěma
 * desetinnými místy bez floatů, bez vedoucích nul a bez „-0.00“. Nevyplněné
 * nepovinné položky se vynechají — prázdný atribut zpráva mít nesmí.
 */
final class EetMessageBuilder
{
    public const NAMESPACE = 'http://fs.gov.cz/eet/schema/v4';
    public const PREFIX = 'v4';

    private const EIC_PATTERN = '/^CZ[0-9]{8,10}$/D';
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/D';
    private const CODE_CHARS = '0-9a-zA-Z.,:;\/#\-_ ';
    /** |částka| < 100 000 000 Kč, tedy nejvýš 9 999 999 999 haléřů. */
    private const MAX_ABS_CENTS = 9_999_999_999;
    private const MIN_UNIT_ID = 1;
    private const MAX_UNIT_ID = 999_999_999;

    public function build(EetSaleData $data, EetMessageHeader $header): \DOMDocument
    {
        $this->validate($data, $header);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $trzba = $document->createElementNS(self::NAMESPACE, self::PREFIX . ':Trzba');
        $document->appendChild($trzba);

        $hlavicka = $document->createElementNS(self::NAMESPACE, self::PREFIX . ':Hlavicka');
        $hlavicka->setAttribute('uuid_zpravy', strtolower($header->uuid));
        $hlavicka->setAttribute('dat_odesl', self::formatDateTime($header->sentAt));
        $hlavicka->setAttribute('prvni_zaslani', $header->firstAttempt ? 'true' : 'false');
        if ($header->verification) {
            $hlavicka->setAttribute('overeni', 'true');
        }
        $trzba->appendChild($hlavicka);

        $polozky = $document->createElementNS(self::NAMESPACE, self::PREFIX . ':Data');
        $polozky->setAttribute('eic_popl', $data->eic);
        if ($data->delegatingEic !== null) {
            $polozky->setAttribute('eic_poverujiciho', $data->delegatingEic);
        }
        // Situace 4 (pověření jedním poplatníkem) příznak neuvádí, „false“ by byla nerelevantní položka.
        if ($data->multipleDelegators === true) {
            $polozky->setAttribute('povereni_vice_popl', 'true');
        }
        $polozky->setAttribute('id_jednotky', (string) $data->unitId);
        $polozky->setAttribute('id_pokl', $data->deviceCode);
        $polozky->setAttribute('porad_cis', $data->sequenceNumber);
        $polozky->setAttribute('dat_trzby', self::formatDateTime($data->saleAt));
        $polozky->setAttribute('celk_trzba', self::formatAmount($data->totalCents));
        if ($data->creditTopUpCents !== null) {
            $polozky->setAttribute('urceno_cerp_zuct', self::formatAmount($data->creditTopUpCents));
        }
        if ($data->creditDrawCents !== null) {
            $polozky->setAttribute('cerp_zuct', self::formatAmount($data->creditDrawCents));
        }
        $trzba->appendChild($polozky);

        return $document;
    }

    /** Čas ve tvaru `rrrr-mm-ddThh:mm:ss±hh:mm` v časové zóně Europe/Prague. */
    public static function formatDateTime(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d\TH:i:sP');
    }

    /** Částka z haléřů: `1210.00`, `-0.01`, `0.00`. */
    public static function formatAmount(int $cents): string
    {
        if ($cents > self::MAX_ABS_CENTS || $cents < -self::MAX_ABS_CENTS) {
            throw new EetValidationException('amount', 'Částka musí být v absolutní hodnotě menší než 100 000 000 Kč.');
        }
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function validate(EetSaleData $data, EetMessageHeader $header): void
    {
        if (preg_match(self::UUID_PATTERN, $header->uuid) !== 1) {
            throw new EetValidationException('uuid_zpravy', 'UUID zprávy musí mít tvar RFC 9562 verze 1 až 5 (doporučená verze 4).');
        }
        if (preg_match(self::EIC_PATTERN, $data->eic) !== 1) {
            throw new EetValidationException('eic_popl', 'EIČ poplatníka musí mít tvar CZ a 8 až 10 číslic.');
        }
        if ($data->delegatingEic !== null && preg_match(self::EIC_PATTERN, $data->delegatingEic) !== 1) {
            throw new EetValidationException('eic_poverujiciho', 'EIČ pověřujícího poplatníka musí mít tvar CZ a 8 až 10 číslic.');
        }
        // Pravidla situací 1 až 5 metodiky (shoda s eic_popl, povinnost u pověření jedním poplatníkem).
        $delegation = EetDelegation::fromItems($data->delegatingEic, $data->multipleDelegators === true);
        if (($violation = $delegation->violation($data->eic)) !== null) {
            throw new EetValidationException('eic_poverujiciho', $violation['message']);
        }
        if ($data->unitId < self::MIN_UNIT_ID || $data->unitId > self::MAX_UNIT_ID) {
            throw new EetValidationException('id_jednotky', 'Označení evidenční jednotky musí být celé číslo 1 až 999 999 999.');
        }
        self::assertCode('id_pokl', $data->deviceCode, 20, 'Označení pokladního zařízení');
        self::assertCode('porad_cis', $data->sequenceNumber, 25, 'Pořadové číslo tržby');
        foreach ([
            'celk_trzba' => $data->totalCents,
            'urceno_cerp_zuct' => $data->creditTopUpCents,
            'cerp_zuct' => $data->creditDrawCents,
        ] as $field => $cents) {
            if ($cents !== null && ($cents > self::MAX_ABS_CENTS || $cents < -self::MAX_ABS_CENTS)) {
                throw new EetValidationException($field, 'Částka musí být v absolutní hodnotě menší než 100 000 000 Kč.');
            }
        }
    }

    private static function assertCode(string $field, string $value, int $maxLength, string $label): void
    {
        if ($value === '' || strlen($value) > $maxLength) {
            throw new EetValidationException($field, sprintf('%s musí mít 1 až %d znaků.', $label, $maxLength));
        }
        if (preg_match('/^[' . self::CODE_CHARS . ']+$/D', $value) !== 1) {
            throw new EetValidationException(
                $field,
                sprintf('%s smí obsahovat jen písmena bez diakritiky, číslice, mezeru a znaky . , : ; / # - _', $label),
            );
        }
    }
}
