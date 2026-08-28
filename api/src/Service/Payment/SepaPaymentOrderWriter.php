<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Service\Export\ExportFilename;

/**
 * Generátor **SEPA Credit Transfer** XML (ISO 20022 `pain.001.001.03`) — pro EUR
 * (obecně SEPA) platby dodavatelům, vedle tuzemského ABO/KPC (`AboPaymentOrderWriter`,
 * CZK-only). Ověřeno proti oficiálnímu schématu `api/xsd/pain.001.001.03.xsd`.
 *
 * Na rozdíl od ABO (číslo účtu + kód banky) SEPA scheme identifikuje účty výhradně
 * přes **IBAN** — BIC je od r. 2016 v rámci SEPA/EHP nepovinný (doplní se, jen je-li
 * znám); plátce i každý příjemce v dávce musí mít platný IBAN, jinak writer dávku/
 * položku defenzivně odmítne výjimkou (stejný vzor jako AboPaymentOrderWriter).
 *
 * Volba `pain.001.001.03` (ne novější `.09`/`.10`) — nejrozšířenější, univerzálně
 * přijímaná verze u českých bank (ČS, KB, ČSOB, Raiffeisenbank …) přes internetové
 * bankovnictví; novější verze (ISO 20022 CBPR+/pacs migrace) banky pro klientský
 * import příkazů zatím prakticky nevyžadují.
 */
final class SepaPaymentOrderWriter
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';
    private const MAX_AMOUNT_MINOR = 999_999_999_999_999_999;

    public function __construct(private readonly IbanValidator $iban) {}

    /**
     * @param array{
     *     order_id?: int|string,
     *     initiator_name?: ?string,
     *     payer_name?: ?string,
     *     payer_iban: string,
     *     payer_bic?: ?string,
     *     payment_date: string|\DateTimeInterface,
     *     creation_datetime?: string|\DateTimeInterface,
     *     currency?: string,
     *     items: list<array{
     *         payee_name?: ?string, iban: ?string, bic?: ?string,
     *         amount?: int|float, amount_minor?: int,
     *         variable_symbol?: ?string, specific_symbol?: ?string,
     *         constant_symbol?: ?string, end_to_end_id?: ?string,
     *         message?: ?string,
     *     }>
     * } $order
     *
     * @throws \InvalidArgumentException při prázdné dávce nebo chybějícím/neplatném IBAN plátce či příjemce
     */
    public function build(array $order): string
    {
        $items = $this->items($order);

        $payerIban = $this->iban->normalize(
            $this->requiredText($order, 'payer_iban'),
        );
        if ($payerIban === '' || !$this->iban->isValid($payerIban)) {
            throw new \InvalidArgumentException('Účet plátce nemá platný IBAN — SEPA export vyžaduje IBAN.');
        }
        $payerBic = $this->normalizedBic((string) ($order['payer_bic'] ?? ''));

        $execDate = $this->requiredDate($order, 'payment_date');
        $currency = strtoupper((string) ($order['currency'] ?? 'EUR'));
        $createdAt = isset($order['creation_datetime'])
            ? $this->normalizeDateTime($order['creation_datetime'])
            : new \DateTimeImmutable();
        // Deterministický z order_id (BEZ timestampu) — stejná dávka musí mít při
        // opakovaném stažení STEJNÉ MsgId napříč re-exporty, jinak banka nemůže
        // uplatnit vlastní duplicate-detection (riziko dvojí platby při 2× uploadu).
        $msgId = 'MYUCTO-' . substr(
            hash('sha256', (string) ($order['order_id'] ?? '1')),
            0,
            28,
        );

        $totalMinor = 0;
        foreach ($items as $index => $item) {
            $minor = $this->minorUnits($item, (int) $index);
            if ($totalMinor > self::MAX_AMOUNT_MINOR - $minor) {
                throw new \InvalidArgumentException(
                    'Součet SEPA dávky překračuje 18 číslic XSD pole.',
                );
            }
            $totalMinor += $minor;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $document = $dom->createElementNS(self::NS, 'Document');
        $dom->appendChild($document);
        $cstmr = $dom->createElementNS(self::NS, 'CstmrCdtTrfInitn');
        $document->appendChild($cstmr);

        // ── GrpHdr (hlavička zprávy) ──
        $grpHdr = $dom->createElementNS(self::NS, 'GrpHdr');
        $cstmr->appendChild($grpHdr);
        $this->el($dom, $grpHdr, 'MsgId', $msgId);
        $this->el($dom, $grpHdr, 'CreDtTm', $createdAt->format('Y-m-d\TH:i:s'));
        $this->el($dom, $grpHdr, 'NbOfTxs', (string) count($items));
        $this->el($dom, $grpHdr, 'CtrlSum', $this->formatMinor($totalMinor));
        $initgPty = $dom->createElementNS(self::NS, 'InitgPty');
        $grpHdr->appendChild($initgPty);
        $this->el($dom, $initgPty, 'Nm', $this->text((string) ($order['initiator_name'] ?? $order['payer_name'] ?? ''), 140) ?: 'Platce');

        // ── PmtInf (platební instrukce dávky) ──
        $pmtInf = $dom->createElementNS(self::NS, 'PmtInf');
        $cstmr->appendChild($pmtInf);
        $this->el($dom, $pmtInf, 'PmtInfId', $msgId);
        $this->el($dom, $pmtInf, 'PmtMtd', 'TRF');
        $this->el($dom, $pmtInf, 'NbOfTxs', (string) count($items));
        $this->el($dom, $pmtInf, 'CtrlSum', $this->formatMinor($totalMinor));

        $pmtTpInf = $dom->createElementNS(self::NS, 'PmtTpInf');
        $pmtInf->appendChild($pmtTpInf);
        $svcLvl = $dom->createElementNS(self::NS, 'SvcLvl');
        $pmtTpInf->appendChild($svcLvl);
        $this->el($dom, $svcLvl, 'Cd', 'SEPA');

        $this->el($dom, $pmtInf, 'ReqdExctnDt', $execDate->format('Y-m-d'));

        $dbtr = $dom->createElementNS(self::NS, 'Dbtr');
        $pmtInf->appendChild($dbtr);
        $this->el($dom, $dbtr, 'Nm', $this->text((string) ($order['payer_name'] ?? ''), 140) ?: 'Platce');

        $dbtrAcct = $dom->createElementNS(self::NS, 'DbtrAcct');
        $pmtInf->appendChild($dbtrAcct);
        $dbtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $dbtrAcct->appendChild($dbtrAcctId);
        $this->el($dom, $dbtrAcctId, 'IBAN', $payerIban);

        // DbtrAgt je v pain.001.001.03 POVINNÝ element (na rozdíl od CdtrAgt níže,
        // který je minOccurs="0") — bez něj banka celou dávku odmítne jako
        // strukturálně vadnou. BIC uvnitř je nepovinný (SEPA/EHP od r. 2016), ale
        // element FinInstnId musí být vyplněný něčím → EPC guideline fallback
        // Othr/Id=NOTPROVIDED, když BIC není znám.
        $dbtrAgt = $dom->createElementNS(self::NS, 'DbtrAgt');
        $pmtInf->appendChild($dbtrAgt);
        $finInstnId = $dom->createElementNS(self::NS, 'FinInstnId');
        $dbtrAgt->appendChild($finInstnId);
        if ($payerBic !== '') {
            $this->el($dom, $finInstnId, 'BIC', $payerBic);
        } else {
            $othr = $dom->createElementNS(self::NS, 'Othr');
            $finInstnId->appendChild($othr);
            $this->el($dom, $othr, 'Id', 'NOTPROVIDED');
        }

        $this->el($dom, $pmtInf, 'ChrgBr', 'SLEV');

        foreach ($items as $i => $item) {
            $pmtInf->appendChild($this->buildTransaction($dom, $item, (int) $i, $currency));
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nepodařilo se serializovat SEPA XML.');
        }
        return $xml;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function buildTransaction(\DOMDocument $dom, array $item, int $index, string $currency): \DOMElement
    {
        $iban = $this->iban->normalize(
            $this->optionalText($item, 'iban'),
        );
        if ($iban === '' || !$this->iban->isValid($iban)) {
            throw new \InvalidArgumentException(
                'Položka #' . ($index + 1) . ' nemá platný IBAN — do SEPA příkazu ji nelze zařadit.'
            );
        }
        $amountMinor = $this->minorUnits($item, $index);

        $tx = $dom->createElementNS(self::NS, 'CdtTrfTxInf');

        $pmtId = $dom->createElementNS(self::NS, 'PmtId');
        $tx->appendChild($pmtId);
        $vs = $this->optionalText($item, 'variable_symbol');
        $explicitEndToEnd = $this->optionalText(
            $item,
            'end_to_end_id',
        );
        $endToEnd = $explicitEndToEnd !== ''
            ? $this->identifier($explicitEndToEnd, 'EndToEndId')
            : ($vs !== ''
                ? $this->identifier($vs, 'variabilní symbol')
                : 'NOTPROVIDED');
        $this->el($dom, $pmtId, 'EndToEndId', $endToEnd);

        $amt = $dom->createElementNS(self::NS, 'Amt');
        $tx->appendChild($amt);
        $instdAmt = $dom->createElementNS(
            self::NS,
            'InstdAmt',
            $this->formatMinor($amountMinor),
        );
        $instdAmt->setAttribute('Ccy', $currency);
        $amt->appendChild($instdAmt);

        $bic = $this->normalizedBic(
            $this->optionalText($item, 'bic'),
        );
        if ($bic !== '') {
            $cdtrAgt = $dom->createElementNS(self::NS, 'CdtrAgt');
            $tx->appendChild($cdtrAgt);
            $finInstnId = $dom->createElementNS(self::NS, 'FinInstnId');
            $cdtrAgt->appendChild($finInstnId);
            $this->el($dom, $finInstnId, 'BIC', $bic);
        }

        $cdtr = $dom->createElementNS(self::NS, 'Cdtr');
        $tx->appendChild($cdtr);
        $this->el(
            $dom,
            $cdtr,
            'Nm',
            $this->text(
                $this->optionalText($item, 'payee_name'),
                140,
            ) ?: 'Prijemce',
        );

        $cdtrAcct = $dom->createElementNS(self::NS, 'CdtrAcct');
        $tx->appendChild($cdtrAcct);
        $cdtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $cdtrAcct->appendChild($cdtrAcctId);
        $this->el($dom, $cdtrAcctId, 'IBAN', $iban);

        $remittance = $this->text(
            $this->remittanceInformation(
                $vs,
                $this->optionalText($item, 'specific_symbol'),
                $this->optionalText($item, 'constant_symbol'),
                $this->optionalText($item, 'message', $vs),
            ),
            140,
        );
        if ($remittance !== '') {
            $rmtInf = $dom->createElementNS(self::NS, 'RmtInf');
            $tx->appendChild($rmtInf);
            $this->el($dom, $rmtInf, 'Ustrd', $remittance);
        }

        return $tx;
    }

    /**
     * Zpráva pro příjemce (`RmtInf/Ustrd`) včetně českých platebních symbolů.
     *
     * SEPA scheme české symboly nezná — `pain.001.001.03` má jen strukturovanou
     * referenci `RmtInf/Strd/CdtrRefInf` (jedna hodnota, typicky ISO 11649 RF),
     * do které se tři samostatná čísla nevejdou. Tuzemské banky proto symboly
     * přenášejí v NEstrukturované zprávě v ustáleném tvaru `/VS/…/SS/…/KS/…`
     * (stejná konvence jako u SWIFT pole 70 a u přeshraničních příkazů) a při
     * konverzi na tuzemský formát si je z ní zpátky vytáhnou.
     *
     * Bez toho odcházela eurová platba úplně bez identifikace — odvod bez VS
     * příjemce nespáruje. Symboly proto stojí na ZAČÁTKU zprávy: `Ustrd` má
     * jen 140 znaků a při ořezu musí přežít identifikace, ne popisný text.
     */
    private function remittanceInformation(
        string $variableSymbol,
        string $specificSymbol,
        string $constantSymbol,
        string $message,
    ): string {
        $reference = '';
        foreach ([
            'VS' => $variableSymbol,
            'SS' => $specificSymbol,
            'KS' => $constantSymbol,
        ] as $tag => $value) {
            $digits = (string) preg_replace('/\D+/', '', $value);
            if ($digits !== '') {
                $reference .= '/' . $tag . '/' . $digits;
            }
        }
        if ($reference === '') {
            return $message;
        }
        // Zprávu neduplikujeme, když je to jen opsaný variabilní symbol —
        // v referenci už je.
        $message = trim($message);
        if ($message === '' || $message === trim($variableSymbol)) {
            return $reference;
        }

        return $reference . ' ' . $message;
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    /** @param array<string,mixed> $item */
    private function minorUnits(array $item, int $index): int
    {
        if (array_key_exists('amount_minor', $item)) {
            $minor = $item['amount_minor'];
            if (!is_int($minor)) {
                throw new \InvalidArgumentException(
                    'Položka #' . ($index + 1)
                    . ' nemá částku v celých minor jednotkách.',
                );
            }
        } else {
            $amount = $item['amount'] ?? 0;
            if (!is_int($amount) && !is_float($amount)) {
                throw new \InvalidArgumentException(
                    'Položka #' . ($index + 1)
                    . ' nemá platnou částku.',
                );
            }
            $minor = (int) round($amount * 100);
        }
        if ($minor <= 0) {
            throw new \InvalidArgumentException(
                'Položka #' . ($index + 1) . ' má nekladnou částku.',
            );
        }
        if ($minor > self::MAX_AMOUNT_MINOR) {
            throw new \InvalidArgumentException(
                'Položka #' . ($index + 1)
                . ' překračuje 18 číslic XSD částky SEPA.',
            );
        }

        return $minor;
    }

    /** @param array<string,mixed> $item */
    private function optionalText(
        array $item,
        string $field,
        string $default = '',
    ): string {
        $value = $item[$field] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Pole {$field} SEPA položky musí být text.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $order
     * @return non-empty-list<array<string,mixed>>
     */
    private function items(array $order): array
    {
        $value = $order['items'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException(
                'Platební příkaz neobsahuje platný seznam položek.',
            );
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException(
                    'SEPA položka musí být objekt.',
                );
            }
            $normalized = [];
            foreach ($item as $key => $fieldValue) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException(
                        'SEPA položka má neplatný klíč.',
                    );
                }
                $normalized[$key] = $fieldValue;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    /** @param array<string,mixed> $order */
    private function requiredText(array $order, string $field): string
    {
        $value = $order[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(
                "SEPA příkaz nemá platné pole {$field}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $order */
    private function requiredDate(
        array $order,
        string $field,
    ): \DateTimeImmutable {
        $value = $order[$field] ?? null;
        if (!is_string($value) && !$value instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException(
                "SEPA příkaz nemá platné pole {$field}.",
            );
        }

        return $this->normalizeDate($value);
    }

    private function formatMinor(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = $minor % 100;

        return $whole . '.' . str_pad(
            (string) $fraction,
            2,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * SEPA "Latin character set" — transliteruj diakritiku (stejně jako ABO writer),
     * sluč whitespace, ořízni na maximální délku pole (Max35Text/Max70Text/Max140Text).
     */
    private function text(string $s, int $maxLen): string
    {
        $s = ExportFilename::transliterate($s);
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return mb_substr($s, 0, $maxLen);
    }

    private function identifier(string $value, string $label): string
    {
        $normalized = ExportFilename::transliterate($value);
        $normalized = trim(
            (string) preg_replace('/\s+/', ' ', $normalized),
        );
        if ($normalized === ''
            || mb_strlen($normalized, 'UTF-8') > 35
        ) {
            throw new \InvalidArgumentException(
                "{$label} musí mít 1 až 35 znaků.",
            );
        }

        return $normalized;
    }

    private function normalizedBic(string $bic): string
    {
        $bic = strtoupper((string) preg_replace('/\s+/', '', $bic));
        return $bic !== '' && $this->iban->isValidBic($bic) ? $bic : '';
    }

    private function normalizeDate(string|\DateTimeInterface $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }
        return new \DateTimeImmutable($date);
    }

    private function normalizeDateTime(
        string|\DateTimeInterface $dateTime,
    ): \DateTimeImmutable {
        if ($dateTime instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($dateTime);
        }

        return new \DateTimeImmutable($dateTime);
    }
}
