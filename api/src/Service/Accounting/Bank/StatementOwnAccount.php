<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;

/**
 * Vlastní bankovní účet, na jehož výpisu pohyb leží: podmínka „vlastní účet“ pravidla
 * účtování ({@see BankRuleMatcher::OWN_ACCOUNT}). Účet se hledá stejně jako bankovní
 * noha zápisu ({@see BankAnalyticResolver}) a výchozí dimenze účtu
 * ({@see \MyInvoice\Service\Accounting\Dimension\DimensionDefaults}), takže pravidlo
 * omezené na účet platí přesně pro pohyby účtované na jeho analytiku 221.xxx.
 */
final class StatementOwnAccount
{
    /**
     * Číslo a banka výpisu => id účtu. Ušetří dotazy jen tomu, kdo jednu instanci použije
     * pro víc pohybů (učení pravidel, import Pohody, seznam konfliktů). Instanci drž jen po
     * dobu jednoho průchodu: v dlouhém procesu by cache po archivaci nebo přidání účtu
     * zastarala. Proto ji BankPostingService (singleton v workeru) zakládá při každém volání
     * a platí jeden dotaz po unikátním indexu účtu.
     *
     * @var array<string,?int>
     */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $tx řádek pohybu s číslem účtu výpisu v `recipient_account`
     *        a kódem banky v `recipient_bank` ({@see \MyInvoice\Service\Bank\BankTransactionContext})
     */
    public function idFor(int $supplierId, array $tx): ?int
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $bank = trim((string) ($tx['recipient_bank'] ?? ''));
        $key = $supplierId . '|' . $account . '|' . $bank;
        if (!array_key_exists($key, $this->cache)) {
            $own = (new SupplierBankAccountRepository($this->db, new BankStatementOwnershipResolver($this->db)))
                ->matchCounterparty($supplierId, $account, $bank === '' ? null : $bank);
            $this->cache[$key] = $own === null ? null : (int) $own['id'];
        }
        return $this->cache[$key];
    }

    /**
     * Pohyb pro {@see BankRuleMatcher::matching()} doplněný o vlastní účet výpisu.
     *
     * @param array<string,mixed> $match
     * @param array<string,mixed> $tx
     * @return array<string,mixed>
     */
    public function withOwnAccount(int $supplierId, array $match, array $tx): array
    {
        return $match + [BankRuleMatcher::OWN_ACCOUNT => $this->idFor($supplierId, $tx)];
    }
}
