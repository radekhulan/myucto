<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Pohyby Fio API ve formátu Fio XML (`periods/{token}/{od}/{do}/transactions.xml`).
 *
 * GPC má pevnou délku záznamu: u zahraniční platby nese IBAN protistrany jen v poli
 * názvu (20 znaků, ořízne se) a zprávu pro příjemce, BIC ani typ pohybu vůbec (#222).
 * XML je nese celé. Do výpisu se ale neukládá syrové XML (týden pohybů je zhruba
 * sedmkrát větší než GPC a stahuje se každých 15 minut), jen kompaktní řádky
 * z {@see rows()}, ze kterých {@see parse()} skládá pohyby.
 *
 * ID pohybu je totéž číslo, které GPC nese jako číslo dokladu, takže pohyb stažený
 * dřív přes GPC má stejný otisk a podruhé se nezaloží.
 */
final class FioTransactionParser
{
    /** Sloupce Fio XML (atribut `id`) → klíč kompaktního řádku. */
    private const COLUMNS = [
        22 => 'id',
        0 => 'date',
        1 => 'amount',
        14 => 'currency',
        2 => 'account',
        3 => 'bank',
        10 => 'name',
        4 => 'ks',
        5 => 'vs',
        6 => 'ss',
        7 => 'ident',
        16 => 'message',
        8 => 'type',
        25 => 'comment',
        26 => 'bic',
    ];

    /**
     * @return array{info:array<string,string>,transactions:list<array<string,string>>}
     */
    public function rows(#[\SensitiveParameter] string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw $this->invalid('xml_entity');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement?->localName !== 'AccountStatement') {
            throw $this->invalid('xml_invalid');
        }

        $info = [];
        $xpath = new \DOMXPath($dom);
        foreach (['accountId', 'bankId', 'currency', 'iban', 'bic', 'openingBalance', 'closingBalance', 'dateStart', 'dateEnd'] as $name) {
            $node = $xpath->query('/AccountStatement/Info/' . $name)?->item(0);
            $value = $node === null ? '' : trim($node->textContent);
            if ($value !== '') {
                $info[$name] = $value;
            }
        }
        if (!isset($info['accountId'], $info['currency'], $info['dateEnd'])) {
            throw $this->invalid('info_missing');
        }

        $transactions = [];
        foreach ($xpath->query('/AccountStatement/TransactionList/Transaction') ?: [] as $node) {
            $row = [];
            foreach ($node->childNodes as $column) {
                if (!$column instanceof \DOMElement) {
                    continue;
                }
                $key = self::COLUMNS[(int) $column->getAttribute('id')] ?? null;
                $value = trim($column->textContent);
                if ($key !== null && $value !== '' && $column->getAttribute('id') !== '') {
                    $row[$key] = $value;
                }
            }
            $transactions[] = $row;
        }

        return ['info' => $info, 'transactions' => $transactions];
    }

    /**
     * @param array{info:array<string,string>,transactions:list<array<string,string>>} $rows
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(array $rows): array
    {
        $info = $rows['info'];
        $account = (string) ($info['accountId'] ?? '');
        $currency = strtoupper((string) ($info['currency'] ?? ''));
        $statementDate = $this->date((string) ($info['dateEnd'] ?? ''));
        if (preg_match('/^\d{1,16}$/D', $account) !== 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $this->invalid('info_invalid');
        }

        $transactions = [];
        $seen = [];
        foreach ($rows['transactions'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^\d{1,40}$/D', $id) !== 1 || isset($seen[$id])) {
                throw $this->invalid('transaction_id_invalid');
            }
            $seen[$id] = true;
            $amount = (string) ($row['amount'] ?? '');
            if (!is_numeric($amount)) {
                throw $this->invalid('amount_invalid');
            }
            [$counterparty, $bank] = $this->counterparty((string) ($row['account'] ?? ''), (string) ($row['bank'] ?? ''));
            $ident = (string) ($row['ident'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $message = (string) ($row['message'] ?? '');

            $transactions[] = [
                'posted_at' => $this->date((string) ($row['date'] ?? '')),
                'amount' => round((float) $amount, 2),
                'currency' => strtoupper((string) ($row['currency'] ?? $currency)),
                'variable_symbol' => $this->text($row['vs'] ?? null, 20),
                'constant_symbol' => $this->text($row['ks'] ?? null, 10),
                'specific_symbol' => $this->text($row['ss'] ?? null, 20),
                'counterparty_account' => $counterparty,
                'counterparty_bank' => $bank,
                // Bez názvu protiúčtu nese GPC jméno z popisu, stejně tak tady.
                'counterparty_name' => $this->text($name !== '' ? $name : $this->firstOf(
                    CardNumberMask::stripMasked($ident), $message, (string) ($row['type'] ?? ''),
                ), 190),
                'card_last4' => CardNumberMask::last4FromText($ident . ' ' . ($row['comment'] ?? '')),
                'description' => $this->description($ident, $name, $message, (string) ($row['type'] ?? '')),
                'bank_ref' => $id,
            ];
        }

        return [
            'header' => [
                'account_number' => $account,
                'currency' => $currency,
                'statement_date' => $statementDate,
                'statement_number' => null,
                'prev_balance' => isset($info['openingBalance']) && is_numeric($info['openingBalance']) ? round((float) $info['openingBalance'], 2) : null,
                'curr_balance' => isset($info['closingBalance']) && is_numeric($info['closingBalance']) ? round((float) $info['closingBalance'], 2) : null,
                'debit_total' => null,
                'credit_total' => null,
            ],
            'transactions' => $transactions,
        ];
    }

    /**
     * Popis v pořadí, ve kterém ho skládá GPC Fio (uživatelská identifikace, název
     * protiúčtu, zpráva pro příjemce, typ), aby pravidla nad popisem dál platila.
     * Zpráva pro příjemce se připojí i tehdy, když popis tvoří jiné pole: nese číslo
     * faktury, podle kterého se platba páruje.
     */
    private function description(string $ident, string $name, string $message, string $type): ?string
    {
        $description = $this->firstOf($ident, $name, $message, $type);
        if ($message !== '' && !str_contains(mb_strtoupper($description), mb_strtoupper($message))) {
            $description .= ' | ' . $message;
        }

        return $this->text($description, 255);
    }

    private function firstOf(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** @return array{0:?string,1:?string} */
    private function counterparty(string $account, string $bank): array
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $account));
        if ($compact === '') {
            return [null, null];
        }
        if (preg_match('/^CZ\d{2}(\d{4})(\d{6})(\d{10})$/D', $compact, $m) === 1) {
            $number = ltrim($m[3], '0');
            $prefix = ltrim($m[2], '0');
            return [$number === '' ? null : ($prefix === '' ? $number : $prefix . '-' . $number), $m[1]];
        }
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/D', $compact) === 1) {
            return [$compact, null];
        }

        return [mb_substr($account, 0, 40), preg_match('/^\d{4}$/D', $bank) === 1 ? $bank : null];
    }

    private function date(string $value): string
    {
        $date = substr($value, 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw $this->invalid('date_invalid');
        }

        return $date;
    }

    private function text(?string $value, int $length): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function invalid(string $reason): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Banka vrátila neplatný výpis Fio XML (' . $reason . ').');
    }
}
