<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Rozpis časového rozlišení k datu (inventurní soupis položek agendy po účtech 381 až 385
 * s odsouhlasením na hlavní knihu). Podklad exportu XLSX a PDF; položky čte po stránkách
 * seznamu, ne celé najednou.
 */
final class AccrualBookService
{
    private const PAGE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemService $items,
        private readonly AccrualReconciliationService $reconciliation,
    ) {}

    /**
     * @param array<string,mixed> $query filtry seznamu ({@see AccrualItemService::list()})
     * @param array<string,mixed> $user
     * @return array{meta: array<string,mixed>, rows: list<array<string,mixed>>, reconciliation: array<string,mixed>}
     */
    public function build(int $supplierId, array $query, array $user): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rows = [];
        $page = 1;
        do {
            $result = $this->items->list($supplierId, ['page' => $page, 'per_page' => self::PAGE, 'sort_by' => 'accrual_account_code'] + $query);
            foreach ($result['items'] as $item) {
                $rows[] = $item;
            }
            $page++;
        } while (count($result['items']) === self::PAGE);
        $asOf = $result['as_of'];
        $name = trim((string) ($user['name'] ?? ''));
        return [
            'meta' => [
                'title' => 'Rozpis časového rozlišení',
                'as_of' => $asOf,
                'supplier' => ['company_name' => (string) ($supplier['company_name'] ?? ''), 'ic' => (string) ($supplier['ic'] ?? '')],
                'printed_by' => $name !== '' ? $name : (string) ($user['email'] ?? ''),
                'printed_at' => date('Y-m-d H:i:s'),
            ],
            'rows' => $rows,
            'totals' => [
                'basis_amount' => round(array_sum(array_column($rows, 'basis_amount')), 2),
                'released' => round(array_sum(array_column($rows, 'released')), 2),
                'balance' => round(array_sum(array_column($rows, 'balance')), 2),
            ],
            'reconciliation' => $this->reconciliation->reconcile($supplierId, $asOf),
        ];
    }
}
