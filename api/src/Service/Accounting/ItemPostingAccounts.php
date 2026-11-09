<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Zaúčtování zadané už na řádku faktury: protiúčet řádku a předkontace řádku
 * (migrace 2265). Řádek tím nese úplnou dvojici účtů:
 *
 *   přijatá faktura: MD = účet nákladu řádku (`expense_account_code`), Dal = protiúčet
 *   vydaná faktura:  MD = protiúčet, Dal = výnosový účet řádku (`revenue_account_code`)
 *
 * Předkontace řádku (`posting_rule_key`) doplní účty, které řádek sám nemá. Vyplněný účet
 * má vždy přednost. Prázdné nové sloupce = dosavadní chování (protiúčet z předkontace
 * dokladu), proto firma, která pole nepoužije, nepozná změnu.
 *
 * Protiúčet (závazek / pohledávka) musí být v rámci dokladu JEDEN: úhrada z banky
 * i pokladny odúčtuje závazek z živého zápisu dokladu ({@see PurchasePayableAccount::forPayment},
 * u vydané strany `BankPostingService::predpisSaldo`) a doklad se dvěma různými závazky
 * by úhrada nedokázala uzavřít. Řádky se proto ve zápisu nedělí podle protiúčtu, jen se
 * nahradí účet saldokonta celého dokladu. Pro budoucí párový zápis nese každý řádek dvojici
 * i tak, takže z něj jde dvojici sestavit přímo.
 */
final class ItemPostingAccounts
{
    /** Syntetiky, které nejsou saldokontem dokladu: DPH a zálohy. */
    private const NOT_COUNTER_SYNTHETICS = ['343', '314', '324'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $rules,
    ) {}

    /**
     * Tvar vstupu z editoru nebo API. Prázdné hodnoty → NULL, neplatný tvar → výjimka
     * (akce ji vrací jako 400). Existenci účtu a předkontace ověřuje až zaúčtování.
     *
     * @param array<string,mixed> $item
     * @return array{counter_account_code:?string, posting_rule_key:?string}
     * @throws \InvalidArgumentException
     */
    public static function normalizeInput(array $item): array
    {
        $counter = self::text($item['counter_account_code'] ?? null);
        if ($counter !== null && preg_match('/^[0-9][0-9A-Za-z.]{0,9}$/D', $counter) !== 1) {
            throw new \InvalidArgumentException('Protiúčet řádku „' . $counter . '" není platné číslo účtu.');
        }
        $rule = self::text($item['posting_rule_key'] ?? null);
        if ($rule !== null && preg_match('/^[a-z0-9_.\-]{1,64}$/D', $rule) !== 1) {
            throw new \InvalidArgumentException('Předkontace řádku „' . $rule . '" není platný klíč předkontace.');
        }

        return ['counter_account_code' => $counter, 'posting_rule_key' => $rule];
    }

    /**
     * Účet řádku z předkontace řádku: přijatá faktura bere stranu MD (náklad), vydaná
     * stranu Dal (výnos). NULL = řádek předkontaci nemá nebo ta účet neuvádí.
     *
     * @param 'purchase'|'sale' $direction
     */
    public function ruleItemAccount(int $supplierId, ?string $ruleKey, string $direction): ?string
    {
        $rule = $this->rule($supplierId, $ruleKey);
        if ($rule === null) {
            return null;
        }
        $code = $direction === 'purchase' ? $rule['debit_account_code'] : $rule['credit_account_code'];
        return self::text($code);
    }

    /**
     * Protiúčet celého dokladu z řádků: vlastní protiúčet řádku, jinak strana saldokonta
     * z jeho předkontace. NULL = žádný řádek protiúčet neurčuje (protiúčet z předkontace
     * dokladu, dosavadní chování).
     *
     * @param list<array{counter_account_code:mixed, posting_rule_key:mixed}> $rows
     * @param 'purchase'|'sale' $direction
     * @throws PostingException 422 různé protiúčty, neplatný účet
     */
    public function documentCounterAccount(int $supplierId, array $rows, string $direction, string $entryDate, bool $currentRead): ?string
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = self::text($row['counter_account_code'] ?? null);
            if ($code === null) {
                $rule = $this->rule($supplierId, self::text($row['posting_rule_key'] ?? null));
                if ($rule !== null) {
                    $code = self::text($direction === 'purchase' ? $rule['credit_account_code'] : $rule['debit_account_code']);
                }
            }
            if ($code !== null) {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return null;
        }
        if (count($codes) > 1) {
            throw new PostingException(
                'mixed_counter_accounts',
                'Řádky dokladu mají různé protiúčty (' . implode(', ', array_keys($codes)) . '). '
                    . 'Doklad musí mít jeden ' . ($direction === 'purchase' ? 'závazek' : 'pohledávku')
                    . ', aby ho úhrada mohla uzavřít.',
                422,
            );
        }

        return $this->validateCounter($supplierId, (string) array_key_first($codes), $direction, $entryDate, $currentRead);
    }

    /**
     * Protiúčet musí být aktivní účet třídy 3 v osnově firmy k datu zápisu: u přijaté
     * faktury závazek (pasivum), u vydané pohledávka (aktivum). DPH ani zálohy
     * saldokontem dokladu nejsou.
     *
     * @param 'purchase'|'sale' $direction
     */
    private function validateCounter(int $supplierId, string $code, string $direction, string $entryDate, bool $currentRead): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code, account_type FROM '
                . AccountDefinitionRepository::relation($entryDate, $supplierId, $currentRead)
                . ' a WHERE supplier_id = ? AND account_code = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new PostingException('invalid_counter_account', 'Protiúčet ' . $code . ' není aktivní v účtové osnově firmy.', 422);
        }
        $expectedType = $direction === 'purchase' ? 'liability' : 'asset';
        if ((string) $row['account_type'] !== $expectedType
            || !str_starts_with($code, '3')
            || in_array(substr($code, 0, 3), self::NOT_COUNTER_SYNTHETICS, true)
        ) {
            throw new PostingException(
                'invalid_counter_account',
                $direction === 'purchase'
                    ? 'Protiúčet ' . $code . ' není účet závazku. Zvolte závazkový účet třídy 3, například 321 nebo 325.'
                    : 'Protiúčet ' . $code . ' není účet pohledávky. Zvolte pohledávkový účet třídy 3, například 311 nebo 315.',
                422,
            );
        }

        // Úhrada vydané faktury v pokladně, zápočtem a vyrovnáním odúčtuje pohledávku podle
        // předkontace úhrady, ne podle zápisu dokladu (na rozdíl od přijaté strany, která má
        // {@see PurchasePayableAccount::forPayment}). Protiúčet proto smí jen na tutéž
        // syntetiku (typicky analytika 311), jinak by saldo zůstalo otevřené.
        if ($direction === 'sale') {
            $payment = $this->rules->resolve($supplierId, 'payment.receivable.settlement');
            $receivable = self::text($payment['credit_account_code'] ?? null) ?? '311';
            if (substr($code, 0, 3) !== substr($receivable, 0, 3)) {
                throw new PostingException(
                    'invalid_counter_account',
                    'Protiúčet vydané faktury musí být na pohledávce ' . substr($receivable, 0, 3)
                        . ' nebo její analytice, jinak by ji úhrada neuzavřela.',
                    422,
                );
            }
        }

        return (string) $row['account_code'];
    }

    /** @return array<string,mixed>|null */
    private function rule(int $supplierId, ?string $ruleKey): ?array
    {
        if ($ruleKey === null) {
            return null;
        }
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        if ($rule === null) {
            throw new PostingException('invalid_posting_rule', 'Předkontace řádku „' . $ruleKey . '" ve firmě neexistuje.', 422);
        }

        return $rule;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
