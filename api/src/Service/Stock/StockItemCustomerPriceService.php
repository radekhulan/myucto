<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemCustomerPriceRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Invoice\UnitPricePrecision;

/**
 * Individuální ceny zákazníků na skladové kartě (issue #17) — editor na záložce
 * Ceny. Seznam i uložení celé sady karty; výslednou cenu dopočítává
 * {@see EffectivePriceResolver::customerBaseline()}, aby editor a doklad nemohly
 * ukázat každý jiné číslo.
 */
final class StockItemCustomerPriceService
{
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemCustomerPriceRepository $prices,
        private readonly EffectivePriceResolver $resolver,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $itemId): array
    {
        $this->requireItem($supplierId, $itemId);
        $rows = $this->prices->listForItem($supplierId, $itemId);
        $standard = [];
        $out = [];
        foreach ($rows as $row) {
            $currency = (string) $row['currency_code'];
            $standard[$currency] ??= $this->resolver->standardPrices($supplierId, [$itemId], $currency)[$itemId] ?? null;
            $out[] = [
                'id'              => $row['id'],
                'client_id'       => $row['client_id'],
                'client_name'     => (string) $row['client_name'],
                'currency_code'   => $currency,
                'price_type'      => (string) $row['price_type'],
                'fixed_price'     => $row['fixed_price'],
                'discount_pct'    => $row['discount_pct'],
                'valid_from'      => $row['valid_from'],
                'valid_to'        => $row['valid_to'],
                'note'            => $row['note'],
                'resulting_price' => EffectivePriceResolver::customerBaseline($standard[$currency], $row),
            ];
        }
        return $out;
    }

    /**
     * Nahradí celou sadu zákaznických cen karty.
     *
     * @param mixed $body seznam řádků {client_id, currency_code, price_type, fixed_price?,
     *                    discount_pct?, valid_from?, valid_to?, note?}
     * @return list<array<string,mixed>>
     */
    public function save(int $supplierId, int $itemId, mixed $body): array
    {
        $this->requireItem($supplierId, $itemId);
        if (is_array($body) && isset($body['customer_prices']) && is_array($body['customer_prices'])) {
            $body = $body['customer_prices'];
        }
        if (!is_array($body) || !array_is_list($body) || count($body) > self::MAX_ROWS) {
            throw new StockException('validation_failed', 'Tělo musí být seznam nejvýše 500 zákaznických cen.', 422);
        }

        $rows = [];
        $seen = [];
        foreach ($body as $index => $raw) {
            if (!is_array($raw)) {
                throw new StockException('validation_failed', 'Neplatná zákaznická cena.', 422, ['index' => $index]);
            }
            $rows[] = $this->normalizeRow($raw, $index);
            $key = $rows[$index]['client_id'] . '|' . $rows[$index]['currency_code'];
            if (isset($seen[$key])) {
                throw new StockException(
                    'customer_price_duplicate',
                    'Pro jednoho odběratele a měnu smí mít karta jen jednu individuální cenu.',
                    422,
                    ['index' => $index, 'client_id' => $rows[$index]['client_id'], 'currency_code' => $rows[$index]['currency_code']],
                );
            }
            $seen[$key] = true;
        }

        $clients = $this->prices->clientsOfSupplier($supplierId, array_column($rows, 'client_id'));
        foreach ($rows as $index => $row) {
            if (!isset($clients[$row['client_id']])) {
                throw new StockException('invalid_client', 'Odběratel nepatří této firmě.', 422, ['index' => $index, 'client_id' => $row['client_id']]);
            }
        }

        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $this->prices->replaceForItem($supplierId, $itemId, $rows);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->list($supplierId, $itemId);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{client_id:int, currency_code:string, price_type:string, fixed_price:?string,
     *               discount_pct:?string, valid_from:?string, valid_to:?string, note:?string}
     */
    private function normalizeRow(array $raw, int $index): array
    {
        $fail = static fn (string $field, string $message): StockException
            => new StockException('validation_failed', $message, 422, ['index' => $index, 'field' => $field]);

        $clientId = (int) ($raw['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw $fail('client_id', 'Odběratel je povinný.');
        }
        $currency = strtoupper(trim((string) ($raw['currency_code'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw $fail('currency_code', 'Neplatný kód měny (ISO 4217, 3 znaky).');
        }
        $type = (string) ($raw['price_type'] ?? '');
        if (!in_array($type, ['fixed', 'discount_pct'], true)) {
            throw $fail('price_type', "price_type musí být 'fixed' nebo 'discount_pct'.");
        }
        $fixed = null;
        $pct = null;
        if ($type === 'fixed') {
            $fixed = self::unitPrice($raw['fixed_price'] ?? null);
            if ($fixed === null) {
                throw $fail('fixed_price', 'Pevná cena musí být nezáporné číslo s nejvýše šesti desetinnými místy.');
            }
        } else {
            $pct = self::decimal($raw['discount_pct'] ?? null, 3);
            if ($pct === null || bccomp($pct, '0', 3) < 0 || bccomp($pct, '100', 3) > 0) {
                throw $fail('discount_pct', 'Sleva musí být v rozsahu 0–100 % s nejvýše třemi desetinnými místy.');
            }
        }
        $from = self::date($raw['valid_from'] ?? null);
        $to = self::date($raw['valid_to'] ?? null);
        if ($from === false) {
            throw $fail('valid_from', 'Neplatné datum (formát RRRR-MM-DD).');
        }
        if ($to === false) {
            throw $fail('valid_to', 'Neplatné datum (formát RRRR-MM-DD).');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw $fail('valid_to', 'Platnost od musí být nejpozději v den platnosti do.');
        }
        $note = trim((string) ($raw['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            throw $fail('note', 'Poznámka má nejvýše 255 znaků.');
        }
        return [
            'client_id'     => $clientId,
            'currency_code' => $currency,
            'price_type'    => $type,
            'fixed_price'   => $fixed,
            'discount_pct'  => $pct,
            'valid_from'    => $from,
            'valid_to'      => $to,
            'note'          => $note !== '' ? $note : null,
        ];
    }

    /**
     * Nezáporné desetinné číslo s nejvýše `$scale` místy, jinak null. Sdílí ho
     * i {@see StockPriceLevelService} (pevné ceny cenových hladin; slevu a přirážku
     * hladiny čte {@see signedDecimal()}).
     */
    public static function decimal(mixed $v, int $scale): ?string
    {
        if (is_int($v) || is_float($v)) {
            $v = is_float($v) ? number_format($v, $scale, '.', '') : (string) $v;
        }
        $s = str_replace(',', '.', trim((string) $v));
        if (preg_match('/^[0-9]{1,10}(\.[0-9]{1,' . $scale . '})?$/D', $s) !== 1) {
            return null;
        }
        return bcadd($s, '0', $scale);
    }

    /**
     * Jednotková cena bez DPH: nezáporné číslo s nejvýše šesti desetinnými místy
     * (DECIMAL(16,6)), jinak null. Sdílí ji {@see StockPriceLevelService}.
     */
    public static function unitPrice(mixed $v): ?string
    {
        if (!UnitPricePrecision::isWithinPrecision($v)) {
            return null;
        }
        return self::decimal($v, UnitPricePrecision::DECIMALS);
    }

    /**
     * Jako {@see decimal()}, ale připouští záporné číslo (přirážka cenové hladiny). Minus
     * musí stát hned před číslicí; přijme i typografické minus (U+2212), ne pomlčku.
     */
    public static function signedDecimal(mixed $v, int $scale): ?string
    {
        if (is_int($v) || is_float($v)) {
            $v = is_float($v) ? number_format($v, $scale, '.', '') : (string) $v;
        }
        $s = str_replace([',', "\u{2212}"], ['.', '-'], trim((string) $v));
        if (preg_match('/^-?[0-9]{1,10}(\.[0-9]{1,' . $scale . '})?$/D', $s) !== 1) {
            return null;
        }
        $n = bcadd($s, '0', $scale);
        return bccomp($n, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $n;
    }

    /** @return string|null|false null = nezadáno, false = neplatné */
    private static function date(mixed $v): string|null|false
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s ? $s : false;
    }

    private function requireItem(int $supplierId, int $itemId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM stock_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId]);
        if ($stmt->fetchColumn() === false) {
            throw new StockException('not_found', 'Skladová karta nenalezena.', 404);
        }
    }
}
