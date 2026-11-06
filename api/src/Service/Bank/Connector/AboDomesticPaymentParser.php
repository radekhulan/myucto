<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Rozbor tuzemské ABO dávky (jedna skupina, jedno datum splatnosti, jen CZK) na
 * strukturované položky pro bankovní API, která místo souboru chtějí jednotlivé platby.
 * Kontroluje tvar, kontrolní součet skupiny a čísla účtů (mod 11) a převádí účty na IBAN.
 *
 * Používají ho KB+ a Česká spořitelna. MONETA má vlastní rozbor, protože její hlavička
 * připouští jiné číslo klienta a banka plátce je pevně 0600.
 */
final class AboDomesticPaymentParser
{
    /**
     * @return array{payer_iban:string,execution_date:string,total_minor:int,items:list<array{
     *     line_no:int,line:string,creditor_iban:string,amount_minor:int,
     *     variable_symbol:string,constant_symbol:string,specific_symbol:string,message:string}>}
     */
    public function parse(#[\SensitiveParameter] string $abo, int $maxItems): array
    {
        if ($abo === '' || strlen($abo) > 2 * 1024 * 1024 || preg_match('/[^\x0D\x0A\x20-\x7E]/', $abo)) {
            throw self::invalid();
        }
        $lines = preg_split('/\r\n/', $abo);
        if (!is_array($lines) || array_pop($lines) !== '' || count($lines) < 6
            || !preg_match('/^UHL1\d{6}.{20}\d{10}000999\d{12}$/D', $lines[0])
            || !preg_match('/^1 1501 \d{6} (\d{4})$/D', $lines[1], $file)
            || !preg_match('/^2 (\d{6})-(\d{10}) (\d{14}) (\d{6})$/D', $lines[2], $group)
            || $lines[count($lines) - 2] !== '3 +' || $lines[count($lines) - 1] !== '5 +'
        ) {
            throw self::invalid();
        }
        $payerIban = self::czechIban($group[1], $group[2], $file[1]);
        $executionDate = self::date($group[4]);
        $itemLines = array_slice($lines, 3, -2);
        if ($itemLines === [] || count($itemLines) > $maxItems) {
            throw self::invalid();
        }
        $items = [];
        $total = 0;
        foreach ($itemLines as $index => $line) {
            if (!preg_match('/^(\d{6})-(\d{10}) (\d{12}) (\d{1,10}) (\d{8}) (\d{10}) AV:(.{0,32})$/D', $line, $item)) {
                throw self::invalid();
            }
            $minor = (int) $item[3];
            if ($minor < 1) {
                throw self::invalid();
            }
            $total += $minor;
            $items[] = [
                'line_no' => $index + 1,
                'line' => $line,
                'creditor_iban' => self::czechIban($item[1], $item[2], substr($item[5], 0, 4)),
                'amount_minor' => $minor,
                'variable_symbol' => ltrim($item[4], '0'),
                'constant_symbol' => ltrim(substr($item[5], 4), '0'),
                'specific_symbol' => ltrim($item[6], '0'),
                'message' => trim($item[7]),
            ];
        }
        if ($total !== (int) $group[3]) {
            throw self::invalid();
        }
        return ['payer_iban' => $payerIban, 'execution_date' => $executionDate, 'total_minor' => $total, 'items' => $items];
    }

    /**
     * Symboly ve tvaru, který chtějí KB+ i ČS (`VS:…`, `KS:…`, `SS:…`); nulové se vynechají.
     *
     * @param array{variable_symbol:string,constant_symbol:string,specific_symbol:string} $item
     * @return list<string>
     */
    public static function references(array $item): array
    {
        return array_values(array_filter([
            $item['variable_symbol'] === '' ? null : 'VS:' . $item['variable_symbol'],
            $item['constant_symbol'] === '' ? null : 'KS:' . $item['constant_symbol'],
            $item['specific_symbol'] === '' ? null : 'SS:' . $item['specific_symbol'],
        ], static fn (?string $value): bool => $value !== null));
    }

    public static function czechIban(string $prefix, string $number, string $bankCode): string
    {
        if (!preg_match('/^\d{4}$/D', $bankCode) || !self::validCzechPart($prefix) || !self::validCzechPart($number)) {
            throw self::invalid();
        }
        $bban = $bankCode . str_pad(ltrim($prefix, '0'), 6, '0', STR_PAD_LEFT)
            . str_pad(ltrim($number, '0'), 10, '0', STR_PAD_LEFT);
        $remainder = 0;
        foreach (str_split($bban . '123500') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        return 'CZ' . str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT) . $bban;
    }

    private static function validCzechPart(string $part): bool
    {
        if (!preg_match('/^\d{1,10}$/D', $part)) {
            return false;
        }
        $digits = str_pad($part, 10, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach ([6, 3, 7, 9, 10, 5, 8, 4, 2, 1] as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }
        return $sum % 11 === 0;
    }

    private static function date(string $ddmmyy): string
    {
        $date = \DateTimeImmutable::createFromFormat('!dmy', $ddmmyy);
        if ($date === false || $date->format('dmy') !== $ddmmyy) {
            throw self::invalid();
        }
        return $date->format('Y-m-d');
    }

    private static function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'ABO dávku nelze bezpečně převést pro bankovní API.');
    }
}
