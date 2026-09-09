<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Service\Eshop\EshopException;

final class PriceWriteService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPriceRepository $prices,
        private readonly PriceCalculationService $calculation,
    ) {}

    public function save(int $supplierId, int $itemId, array $rows, bool $replace = false): array
    {
        $prepared = $this->prepareRows($rows);
        return $this->write($supplierId, $itemId, function () use ($supplierId, $itemId, $prepared, $replace): void {
            $this->savePrepared($supplierId, $itemId, $prepared, $replace);
        })['prices'];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{prices:list<array<string,mixed>>,row_version:int}
     */
    public function saveVersioned(
        int $supplierId,
        int $itemId,
        int $expectedVersion,
        array $rows,
        bool $replace = false,
    ): array {
        if ($expectedVersion <= 0) {
            throw new EshopException('version_required', 'Pro uložení je nutná verze karty.', 400);
        }
        $prepared = $this->prepareRows($rows);
        return $this->write(
            $supplierId,
            $itemId,
            function () use ($supplierId, $itemId, $prepared, $replace): void {
                $this->savePrepared($supplierId, $itemId, $prepared, $replace);
            },
            $expectedVersion,
        );
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function prepareRows(array $rows): array
    {
        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Neplatný cenový řádek.');
            }
            $currency = strtoupper(trim((string) ($row['currency_code'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/D', $currency) || isset($prepared[$currency])) {
                throw new \InvalidArgumentException('Neplatná nebo duplicitní měna.');
            }
            $mode = (string) ($row['price_mode'] ?? 'markup');
            $rounding = (string) ($row['rounding'] ?? 'none');
            if (!in_array($mode, ['fixed', 'markup'], true)
                || !in_array($rounding, ['none', '0.01', '0.10', '0.50', '1', '9_ending'], true)) {
                throw new \InvalidArgumentException('Neplatné cenové pravidlo.');
            }
            $markup = $this->decimal($row['markup_pct'] ?? null, 4, 3);
            $fixed = $this->decimal($row['fixed_price'] ?? null, 10, 2);
            if ($mode === 'fixed' && $fixed === null) {
                throw new \InvalidArgumentException('Pevná cena musí být zadaná.');
            }
            if (($fixed !== null && bccomp($fixed, '0', 2) < 0)
                || ($markup !== null && bccomp($markup, '-100', 4) < 0)) {
                throw new \InvalidArgumentException('Cena ani přirážka nesmí vytvářet zápornou cenu.');
            }
            $prepared[$currency] = [
                'price_mode' => $mode,
                'markup_pct' => $mode === 'markup' ? ($markup ?? '0.0000') : $markup,
                'fixed_price' => $fixed,
                'rounding' => $rounding,
                'is_manual_override' => (bool) ($row['is_manual_override'] ?? false),
            ];
        }
        return $prepared;
    }

    /** @param array<string,array<string,mixed>> $prepared */
    private function savePrepared(int $supplierId, int $itemId, array $prepared, bool $replace): void
    {
        if ($replace) {
            foreach ($this->prices->listForItem($supplierId, $itemId) as $existing) {
                if (!isset($prepared[$existing['currency_code']])) {
                    $this->prices->delete($supplierId, $itemId, $existing['currency_code']);
                    if ($existing['currency_code'] === 'CZK') {
                        $this->clearBasePrice($supplierId, $itemId);
                    }
                }
            }
        }
        foreach ($prepared as $currency => $row) {
            $this->prices->upsert($supplierId, $itemId, $currency, $row);
        }
    }

    public function delete(int $supplierId, int $itemId, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Neplatná měna.');
        }
        return $this->write($supplierId, $itemId, function () use ($supplierId, $itemId, $currency): void {
            $this->prices->delete($supplierId, $itemId, $currency);
            if ($currency === 'CZK') {
                $this->clearBasePrice($supplierId, $itemId);
            }
        })['prices'];
    }

    private function clearBasePrice(int $supplierId, int $itemId): void
    {
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = NULL WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $itemId]);
    }

    /** @return array{prices:list<array<string,mixed>>,row_version:int} */
    private function write(
        int $supplierId,
        int $itemId,
        callable $operation,
        ?int $expectedVersion = null,
    ): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT row_version FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $itemId]);
            $currentVersion = $lock->fetchColumn();
            if ($currentVersion === false) {
                throw new \InvalidArgumentException('Karta zboží nenalezena.');
            }
            if ($expectedVersion !== null && (int) $currentVersion !== $expectedVersion) {
                throw new EshopException(
                    'version_conflict',
                    'Kartu mezitím změnil jiný uživatel. Načtěte aktuální data.',
                    409,
                );
            }
            $operation();
            $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $itemId]);
            $prices = $this->calculation->recompute($supplierId, $itemId);
            $version = $pdo->prepare('SELECT row_version FROM stock_items WHERE supplier_id = ? AND id = ?');
            $version->execute([$supplierId, $itemId]);
            $rowVersion = (int) $version->fetchColumn();
            if ($owns) {
                $pdo->commit();
            }
            return ['prices' => $prices, 'row_version' => $rowVersion];
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function decimal(mixed $value, int $integerDigits, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException('Neplatná číselná hodnota ceny.');
        }
        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException('Neplatná číselná hodnota ceny.');
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        $normalized = str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
        if (strlen(ltrim(explode('.', $normalized)[0], '-0')) > $integerDigits) {
            throw new \InvalidArgumentException('Cena překračuje povolený rozsah.');
        }
        return $normalized;
    }
}
