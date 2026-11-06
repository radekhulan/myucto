<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ForeignCurrencyAccountPolicy;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Validace a normalizace pravidla účtování bankovních pohybů: existence účtů v osnově,
 * 221 strana dle směru (R6), saldokontní blacklist (H2), aspoň 1 kritérium, cizoměnové
 * pravidlo jen na výsledkový nebo vlastní účet. Jediné místo pravidel pro REST API
 * pravidel i pro import profilu firmy.
 */
final class BankPostingRuleValidator
{
    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly OtherItemService $otherItems,
    ) {}

    /**
     * Nové pravidlo. `mode` je vždy 'suggest' (R7, H4e); automatiku zapíná jen
     * auditovaný krok povýšení.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function normalizeRule(int $supplierId, array $body): array
    {
        $direction = (string) ($body['direction'] ?? '');
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw self::err('rule_criteria_missing', 'Neplatný směr pravidla.');
        }
        $debit = trim((string) ($body['debit_account_code'] ?? ''));
        $credit = trim((string) ($body['credit_account_code'] ?? ''));
        $currency = strtoupper(self::nn($body['applies_currency'] ?? null) ?? 'CZK');
        $otherItemId = $this->otherItemTarget($supplierId, $direction, $body['other_item_id'] ?? null, $debit, $credit, $currency);
        if ($otherItemId === null) {
            $this->assertAccounts($supplierId, $direction, $debit, $credit);
        }

        $account = self::nn($body['counterparty_account'] ?? null, static fn (string $v): string => AccountNumberNormalizer::normalize($v));
        $vs = self::nn($body['variable_symbol'] ?? null, static fn (string $v): string => VariableSymbolNormalizer::digits($v));
        $fragment = self::nn($body['message_contains'] ?? null, static fn (string $v): string => BankMessageNormalizer::normalize($v));
        $bank = self::nn($body['counterparty_bank'] ?? null);
        $prefix = self::nn($body['counterparty_prefix'] ?? null, static fn (string $v): string => ltrim(preg_replace('/\D/', '', $v) ?? '', '0'));
        if ($account === null && $bank === null && $prefix === null && $vs === null && $fragment === null) {
            throw self::err('rule_criteria_missing', 'Pravidlo musí mít alespoň jedno kritérium.');
        }

        $priority = (int) ($body['priority'] ?? 100);
        if ($priority < 0 || $priority > 999) {
            throw self::err('invalid_priority', 'Priorita musí být v rozsahu 0 až 999.');
        }
        $operationType = self::nn($body['operation_type'] ?? null);
        if ($operationType !== null && !in_array($operationType, OperationType::all(), true)) {
            throw self::err('invalid_operation_type', 'Neplatný typ operace.');
        }
        $this->assertFxAccounts($direction, $debit, $credit, $currency);

        return [
            'name'                 => self::nn($body['name'] ?? null) ?? 'Pravidlo',
            'direction'            => $direction,
            // Vlastnictví účtu ověřuje volající (REST API), import ho dohledá podle čísla.
            'bank_account_id'      => self::positiveId($body['bank_account_id'] ?? null),
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_prefix'  => $prefix,
            'variable_symbol'      => $vs,
            'message_contains'     => $fragment,
            'amount_min'           => self::amount($body['amount_min'] ?? null),
            'amount_max'           => self::amount($body['amount_max'] ?? null),
            'debit_account_code'   => $debit,
            'credit_account_code'  => $credit,
            'description'          => self::nn($body['description'] ?? null),
            'mode'                 => 'suggest',
            'priority'             => $priority,
            'operation_type'       => $operationType,
            'auto_amount_cap'      => self::amount($body['auto_amount_cap'] ?? null),
            'applies_currency'     => $currency,
            'other_item_id'        => $otherItemId,
        ];
    }

    /**
     * Cílová ostatní položka pravidla: patří firmě, je potvrzená nebo zaúčtovaná, v CZK,
     * pohledávka u příchozí a závazek u odchozí platby. Pravidlo pak účtuje na účet položky
     * (ne-bankovní strana = účet pohledávky či závazku položky), aby přiřazení úhrady
     * nevyžadovalo druhý zápis. Saldokontní účet (325, ...) tu zákaz H2 neporušuje: platbu
     * páruje přiřazení k položce, pravidlo jen určuje kterou. Ověří i účty v osnově a bankovní
     * stranu ({@see assertAccounts}). Prázdná hodnota cíl ruší.
     */
    public function otherItemTarget(int $supplierId, string $direction, mixed $raw, string $debit, string $credit, string $currency): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw self::err('rule_other_item_invalid', 'Neplatná ostatní položka pravidla.');
        }
        try {
            $item = $this->otherItems->get($supplierId, $id);
            $account = $this->otherItems->settlementAccountCode($supplierId, $item);
        } catch (OtherItemException) {
            throw self::err('rule_other_item_invalid', 'Ostatní položka pravidla nebyla nalezena nebo nemá platný zápis.');
        }
        if (!in_array((string) $item['status'], ['confirmed', 'posted'], true)) {
            throw self::err('rule_other_item_invalid', 'Pravidlo může přiřazovat jen potvrzené nebo zaúčtované položce.');
        }
        if ((string) $item['currency'] !== 'CZK' || $currency !== 'CZK') {
            throw self::err('rule_other_item_currency', 'Pravidlo s ostatní položkou pracuje jen v CZK.');
        }
        if ((string) $item['side'] !== ($direction === 'incoming' ? 'receivable' : 'payable')) {
            throw self::err('rule_other_item_direction', $direction === 'incoming'
                ? 'Příchozí platbu lze přiřadit jen pohledávce.'
                : 'Odchozí platbu lze přiřadit jen závazku.');
        }
        $this->assertAccounts($supplierId, $direction, $debit, $credit, $account ?? false);
        $nonBank = $direction === 'incoming' ? $credit : $debit;
        if ($account !== null && $nonBank !== $account) {
            throw self::err('rule_other_item_account', 'Pravidlo s ostatní položkou musí účtovat na její účet ' . $account . '.');
        }
        return $id;
    }

    /** Volitelné id (vlastní účet pravidla): prázdné nebo 0 = bez omezení. */
    public static function positiveId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0' || $raw === false) {
            return null;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw self::err('invalid_bank_account', 'Neplatný vlastní bankovní účet pravidla.');
        }
        return $id;
    }

    /** @param string|false|null $otherItemAccount účet cílové položky pravidla (false = položka bez účtu), null = bez položky */
    public function assertAccounts(int $supplierId, string $direction, string $debit, string $credit, string|false|null $otherItemAccount = null): void
    {
        $map = $this->accounts->codeToIdMap($supplierId);
        foreach ([$debit, $credit] as $code) {
            if ($code === '' || !isset($map[$code])) {
                throw self::err('account_not_found', 'Účet ' . $code . ' není v účtové osnově.');
            }
        }
        // R6: bankovní strana musí být 221* dle směru.
        $bankSide = $direction === 'incoming' ? $debit : $credit;
        if (!str_starts_with($bankSide, '221')) {
            throw self::err('rule_bank_side_required', 'Bankovní strana musí být účet 221 dle směru platby.');
        }
        // H2: ne-bankovní strana nesmí být saldokontní. Výjimkou je účet cílové položky pravidla.
        $nonBank = $direction === 'incoming' ? $credit : $debit;
        if (is_string($otherItemAccount) && $nonBank === $otherItemAccount) {
            return;
        }
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($nonBank, $prefix)) {
                throw self::err('rule_saldo_forbidden', 'Platby faktur se párují, ne účtují pravidlem.');
            }
        }
    }

    public function assertFxAccounts(string $direction, string $debit, string $credit, string $currency): void
    {
        if ($currency === 'CZK') {
            return;
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw self::err('invalid_currency', 'Neplatný kód měny.');
        }
        $nonBank = $direction === 'incoming' ? $credit : $debit;
        if (!ForeignCurrencyAccountPolicy::allowsRuleCounterAccount($nonBank)) {
            throw self::err(
                'fx_rule_account_forbidden',
                'Cizoměnové pravidlo smí účtovat jen na výsledkový účet 5xx/6xx, vlastní účet 221 '
                    . 'nebo jiné pohledávky a závazky 378/379.',
            );
        }
    }

    /** Oříznutý text, prázdný = null; volitelná normalizace. */
    public static function nn(mixed $v, ?callable $transform = null): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = $transform !== null ? $transform($s) : $s;
        return $s === '' ? null : $s;
    }

    public static function amount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return round((float) $v, 2);
    }

    private static function err(string $code, string $message): PostingException
    {
        return new PostingException($code, $message, 422);
    }
}
