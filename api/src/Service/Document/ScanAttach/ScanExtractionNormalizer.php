<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Z odpovědi {@see \MyInvoice\Service\Import\LlmGatewayInterface::extractInvoice()}
 * vybere a znormalizuje údaje, podle kterých se sken páruje s dokladem a později
 * kontroluje proti zaúčtování. Výstup odpovídá sloupcům `document_extractions`.
 *
 * Model vrací volný text (IČO s mezerami, SPZ s pomlčkou, celé číslo karty,
 * datum mimo kalendář), a proto se každé pole čistí zvlášť. Co se vyčistit nedá,
 * je null — raději chybějící údaj než údaj, který by párování svedl.
 */
final class ScanExtractionNormalizer
{
    /** Zvýšit, když se změní vytěžovaná pole — dokumenty se pak vytěží znovu. */
    public const SCHEMA_VERSION = 1;

    public const ROLES = ['buyer', 'vendor', 'both', 'none'];

    /**
     * @param array<string,mixed> $data dekódovaný JSON z extrakce
     * @return array{company_role:?string,document_kind:?string,barcode:?string,vendor_name:?string,vendor_ico:?string,vendor_dic:?string,buyer_name:?string,buyer_ico:?string,buyer_dic:?string,document_number:?string,variable_symbol:?string,issue_date:?string,tax_date:?string,total_with_vat:?float,amount_due:?float,currency:?string,license_plate:?string,card_last4:?string}
     */
    public static function normalize(array $data): array
    {
        $vendor = is_array($data['vendor'] ?? null) ? $data['vendor'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $role = $data['company_role'] ?? null;

        return [
            'company_role'    => is_string($role) && in_array($role, self::ROLES, true) ? $role : null,
            'document_kind'   => self::text($data['document_kind'] ?? null, 32),
            'barcode'         => self::code($data['barcode'] ?? null),
            'vendor_name'     => self::text($vendor['company_name'] ?? null, 255),
            'vendor_ico'      => self::ico($vendor['ic'] ?? null),
            'vendor_dic'      => self::dic($vendor['dic'] ?? null),
            'buyer_name'      => self::text($customer['company_name'] ?? null, 255),
            'buyer_ico'       => self::ico($customer['ic'] ?? null),
            'buyer_dic'       => self::dic($customer['dic'] ?? null),
            'document_number' => self::text($data['vendor_invoice_number'] ?? null, 64),
            'variable_symbol' => self::text($data['varsymbol'] ?? ($payment['variable_symbol'] ?? null), 32),
            'issue_date'      => self::date($data['issue_date'] ?? null),
            'tax_date'        => self::date($data['tax_date'] ?? null),
            'total_with_vat'  => self::amount($data['total_with_vat'] ?? null),
            'amount_due'      => self::amount($data['total_with_vat_rounded'] ?? null),
            'currency'        => self::currency($data['currency'] ?? null),
            'license_plate'   => self::plate($data['license_plate'] ?? null),
            'card_last4'      => self::card($data['card_last4'] ?? null),
        ];
    }

    /**
     * Text pro fulltext dokumentu. Sken bez textové vrstvy jinak hledáním nenajdeš;
     * takhle ho najde dodavatel, číslo, VS i částka.
     *
     * @param array<string,mixed> $n výstup {@see normalize()}
     */
    public static function fulltext(array $n): string
    {
        $party = static function (mixed $name, mixed $ico, mixed $dic): string {
            return trim(implode(' ', array_filter([
                trim((string) $name),
                trim((string) $ico) !== '' ? 'IČO ' . trim((string) $ico) : '',
                trim((string) $dic),
            ], static fn (string $s): bool => $s !== '')));
        };
        $parts = [
            'Odběratel'         => $party($n['buyer_name'] ?? null, $n['buyer_ico'] ?? null, $n['buyer_dic'] ?? null),
            'Dodavatel'         => $party($n['vendor_name'] ?? null, $n['vendor_ico'] ?? null, $n['vendor_dic'] ?? null),
            'Číslo dokladu'     => $n['document_number'] ?? null,
            'Variabilní symbol' => $n['variable_symbol'] ?? null,
            'Čárový kód'        => $n['barcode'] ?? null,
            'Datum vystavení'   => $n['issue_date'] ?? null,
            'DUZP'              => $n['tax_date'] ?? null,
            'Celkem'            => isset($n['total_with_vat']) ? $n['total_with_vat'] . ' ' . ($n['currency'] ?? '') : null,
            'SPZ'               => $n['license_plate'] ?? null,
        ];
        $lines = ['[vytěženo ze skenu]'];
        foreach ($parts as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }
        return implode("\n", $lines);
    }

    private static function text(mixed $v, int $max): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** IČO jen z číslic; „CZ12345678" i „123 45 678" dají totéž. */
    private static function ico(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $d = (string) preg_replace('/\D/', '', (string) $v);
        return $d === '' || strlen($d) > 20 ? null : $d;
    }

    private static function dic(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $v));
        return $s === '' || strlen($s) > 20 ? null : $s;
    }

    private static function code(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $v));
        return $s === '' || strlen($s) > 64 ? null : $s;
    }

    private static function plate(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $v));
        return strlen($s) < 2 || strlen($s) > 20 ? null : $s;
    }

    /**
     * Uloží se jen koncovka — ani omylem vrácené celé číslo karty se neukládá.
     * Číslu karty rozumí jediné místo v aplikaci, {@see CardNumberMask}.
     */
    private static function card(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $last4 = CardNumberMask::last4FromText((string) $v) ?? CardNumberMask::normalizeLast4($v);
        return CardNumberMask::isValidLast4($last4) ? $last4 : null;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m) !== 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($v) : null;
    }

    private static function amount(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private static function currency(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = strtoupper(trim($v));
        return preg_match('/^[A-Z]{3}$/', $s) === 1 ? $s : null;
    }
}
