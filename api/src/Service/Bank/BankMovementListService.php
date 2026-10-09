<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;

/**
 * Stránkovaný přehled bankovních pohybů napříč účty a roky. Sdílí ho záložka
 * „Všechny pohyby" (`GET /api/bank-transactions`, každá firma s výpisy) a fronta
 * „K zaúčtování" (`GET /api/accounting/bank-posting-unposted`, jen podvojné účetnictví).
 *
 * Stav zaúčtování doplňuje {@see BankPostingService::transactionPostingInfo()}, který
 * sám rozhoduje podle režimu firmy stejně jako detail výpisu. Mimo podvojné
 * účetnictví proto řádek nenese kontace ani návrhy, jen mzdové párování.
 */
final class BankMovementListService
{
    private const MAX_PER_PAGE = 100;
    private const MATCH_STATUSES = ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'];

    public function __construct(
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly BankPostingService $posting,
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string,mixed> $query query parametry požadavku
     * @param 'all'|'unposted'    $scope
     * @return array<string,mixed>
     */
    public function list(int $supplierId, array $query, string $scope): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($query['per_page'] ?? 50)));
        $doubleEntry = $this->isDoubleEntry($supplierId);
        $matchStatus = in_array($query['status'] ?? null, self::MATCH_STATUSES, true) ? (string) $query['status'] : null;
        $result = $this->suggestions->paginateUnposted(
            $supplierId,
            $perPage,
            ($page - 1) * $perPage,
            [
                'scope' => $scope,
                'status' => $matchStatus,
                // Filtr i řazení podle zápisu v deníku dávají smysl jen v podvojném účetnictví.
                'posting_status' => $doubleEntry ? ($query['posting_status'] ?? null) : null,
                'year' => isset($query['year']) && (int) $query['year'] > 0 ? (int) $query['year'] : null,
                'q' => isset($query['q']) ? mb_substr(trim((string) $query['q']), 0, 100) : null,
                'account' => isset($query['account']) && $query['account'] !== '' ? (string) $query['account'] : null,
                'sort' => isset($query['sort']) && is_string($query['sort'])
                    && ($doubleEntry || $query['sort'] !== 'posting') ? $query['sort'] : null,
                'direction' => isset($query['direction']) && is_string($query['direction']) ? $query['direction'] : null,
            ],
        );
        $txIds = array_map('intval', array_column($result['items'], 'id'));
        $postingByTx = $this->posting->transactionPostingInfo($supplierId, $txIds);
        $dimensionsByTx = (new DimensionAssignmentRepository($this->db))
            ->headerDimensionsIfEnabled($supplierId, 'bank_transaction', $txIds);
        $items = array_map(function (array $item) use ($supplierId, $postingByTx, $dimensionsByTx, $doubleEntry): array {
            $item['posting'] = $postingByTx[$item['id']] ?? null;
            // Uzavřené účetní období existuje jen v podvojném účetnictví.
            $item['period_closed'] = $doubleEntry && $item['period_closed'];
            if ($dimensionsByTx !== null) {
                $item['dimensions'] = (object) ($dimensionsByTx[(int) $item['id']] ?? []);
            }
            return $this->withCzkAmount($supplierId, $item);
        }, $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'scope' => $scope,
            'years' => $this->suggestions->transactionYears($supplierId),
            'accounts' => $this->suggestions->transactionAccounts($supplierId),
        ];
    }

    /**
     * Korunový ekvivalent a kurz. Počítá se tady, ne v SQL repository: kurz musí být
     * TENTÝŽ, jakým se pohyb nakonec zaúčtuje, a to umí jen BankPostingService (pevný kurz
     * firmy dle §24/7 → teprve pak ČNB). JOIN na `exchange_rates` by pevný kurz tiše minul a
     * UI by uživateli předvyplnilo jinou částku, než jakou by zápis dostal.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function withCzkAmount(int $supplierId, array $item): array
    {
        $rate = $this->posting->czkRateFor(
            $supplierId,
            isset($item['currency']) ? (string) $item['currency'] : null,
            (string) $item['posted_at'],
        );
        $item['fx_rate'] = $rate;
        $item['amount_czk'] = $rate === null ? null : round((float) $item['amount'] * $rate, 2);
        return $item;
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry';
    }
}
