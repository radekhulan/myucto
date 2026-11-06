<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Převod ABO dávky na platby Premium Payments v1 České spořitelny. Každá položka se
 * zakládá zvlášť (POST /my/payments) a dávka (POST /my/batchpayments) je pak jen
 * seskupí. Identifikátory jsou deterministické: stejné ABO dá po přerušení stejné
 * položky, takže pokračování z evidence pozná, co už je založené.
 */
final class CsasAboBatchMapper
{
    /** Limit položek dávky podle specifikace Premium Payments v1. */
    public const MAX_ITEMS = 400;

    public function __construct(private readonly AboDomesticPaymentParser $parser = new AboDomesticPaymentParser()) {}

    /**
     * @return array{exchange_identification:string,payments:list<array{line_no:int,instruction_identification:string,
     *     amount_minor:int,creditor_iban:string,body:array<string,mixed>}>}
     */
    public function map(#[\SensitiveParameter] string $abo, string $expectedPayerIban): array
    {
        try {
            $batch = $this->parser->parse($abo, self::MAX_ITEMS);
        } catch (BankConnectorException) {
            throw $this->invalid();
        }
        if (!hash_equals(strtoupper($expectedPayerIban), $batch['payer_iban'])) {
            throw new BankConnectorException('payment_order_account_mismatch', 'Účet plátce neodpovídá připojenému účtu České spořitelny.');
        }
        self::assertNoDuplicates($batch['items']);
        $fingerprint = hash('sha256', $abo);
        $payments = [];
        foreach ($batch['items'] as $item) {
            $instruction = sprintf('MU%03d%s', $item['line_no'], substr(hash('sha256', $fingerprint . ':' . $item['line']), 0, 30));
            $body = [
                'paymentIdentification' => ['instructionIdentification' => $instruction],
                'paymentTypeInformation' => ['instructionPriority' => 'NORM'],
                'amount' => ['instructedAmount' => ['value' => $item['amount_minor'] / 100, 'currency' => 'CZK']],
                'requestedExecutionDate' => $batch['execution_date'],
                'debtorAccount' => ['identification' => ['iban' => $batch['payer_iban']], 'currency' => 'CZK'],
                'creditorAccount' => ['identification' => ['iban' => $item['creditor_iban']], 'currency' => 'CZK'],
            ];
            $references = AboDomesticPaymentParser::references($item);
            if ($item['message'] !== '' || $references !== []) {
                $body['remittanceInformation'] = [];
                if ($item['message'] !== '') {
                    $body['remittanceInformation']['unstructured'] = $item['message'];
                }
                if ($references !== []) {
                    $body['remittanceInformation']['structured'] = ['creditorReferenceInformation' => ['reference' => $references]];
                }
            }
            $payments[] = [
                'line_no' => $item['line_no'],
                'instruction_identification' => $instruction,
                'amount_minor' => $item['amount_minor'],
                'creditor_iban' => $item['creditor_iban'],
                'variable_symbol' => $item['variable_symbol'],
                'body' => $body,
            ];
        }
        return ['exchange_identification' => 'MU' . substr($fingerprint, 0, 14), 'payments' => $payments];
    }

    /**
     * Stejný účet příjemce, částka a symboly ve dvou řádcích banka v dávce odmítne
     * (BATCH_PAYMENT_CONTAINS_DUPLICITIES), ale až ve chvíli, kdy jsou platby založené.
     * Proto se odmítnou předem a chyba nese čísla řádků příkazu.
     *
     * @param list<array{line_no:int,creditor_iban:string,amount_minor:int,variable_symbol:string,constant_symbol:string,specific_symbol:string}> $items
     */
    private static function assertNoDuplicates(array $items): void
    {
        $groups = [];
        foreach ($items as $item) {
            $key = implode('|', [$item['creditor_iban'], $item['amount_minor'], $item['variable_symbol'], $item['constant_symbol'], $item['specific_symbol']]);
            $groups[$key][] = $item['line_no'];
        }
        $duplicates = array_values(array_filter($groups, static fn (array $lines): bool => count($lines) > 1));
        if ($duplicates !== []) {
            throw new BankConnectorException('payment_order_duplicate_items',
                'Příkaz obsahuje platby se stejným účtem, částkou a symboly.', details: ['duplicate_lines' => $duplicates]);
        }
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'ABO dávku nelze bezpečně převést pro Českou spořitelnu.');
    }
}
