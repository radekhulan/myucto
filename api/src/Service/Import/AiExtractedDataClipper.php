<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Ořez textů z AI vytěžení na šířku cílových sloupců.
 *
 * Model občas vrátí delší text, než se vejde do databáze (číslo účtenky místo
 * variabilního symbolu, název firmy s celou adresou, dlouhý kód zboží). Při
 * striktním `sql_mode` by INSERT spadl a doklad by se nezaložil vůbec. Proto se
 * výstup modelu ořízne hned po vytěžení, ještě než ho cokoli dalšího použije.
 * Šířky se čtou z `information_schema`, takže drží krok s migracemi.
 *
 * Výjimkou je platební VS: delší než 10 číslic není variabilní symbol a oříznutý
 * by tiskl jiný symbol, než jaký doklad nese. Takový se zahodí a platební VS se
 * odvodí z čísla dokladu ({@see VariableSymbolNormalizer::forPurchasePayment()}).
 */
final class AiExtractedDataClipper
{
    private const PAYMENT_VS = 'payment.variable_symbol';

    /** @var array<string,array<string,int>> šířky sloupců podle tabulky */
    private array $widths = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $data výstup modelu pro přijatý doklad
     * @return array<string,mixed>
     */
    public function clipPurchase(array $data): array
    {
        return self::clip($data, $this->limits('purchase_invoices', 'purchase_invoice_items'));
    }

    /**
     * @param array<string,mixed> $data výstup modelu pro vydaný doklad
     * @return array<string,mixed>
     */
    public function clipIssued(array $data): array
    {
        return self::clip($data, $this->limits('invoices', 'invoice_items'));
    }

    /**
     * Ořízne pole podle mapy cesta → šířka. Cesta `items.*.unit` platí pro každý řádek.
     *
     * @param array<string,mixed> $data
     * @param array<string,int>   $limits
     * @return array<string,mixed>
     */
    public static function clip(array $data, array $limits): array
    {
        $vs = $data['payment']['variable_symbol'] ?? null;
        if (is_array($data['payment'] ?? null) && is_scalar($vs)) {
            $vs = (string) $vs;
            $digits = VariableSymbolNormalizer::digits($vs);
            if (strlen($digits) > VariableSymbolNormalizer::MAX_LENGTH) {
                $data['payment']['variable_symbol'] = null;
            } elseif (mb_strlen($vs) > ($limits[self::PAYMENT_VS] ?? PHP_INT_MAX)) {
                $data['payment']['variable_symbol'] = $digits !== '' ? $digits : null;
            }
        }
        unset($limits[self::PAYMENT_VS]);

        foreach ($limits as $path => $width) {
            $keys = explode('.', $path);
            if ($keys[0] === 'items') {
                if (!is_array($data['items'] ?? null)) {
                    continue;
                }
                foreach ($data['items'] as $i => $line) {
                    if (is_array($line)) {
                        $data['items'][$i] = self::clipPath($line, array_slice($keys, 2), $width);
                    }
                }
                continue;
            }
            $data = self::clipPath($data, $keys, $width);
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $keys
     * @return array<string,mixed>
     */
    private static function clipPath(array $data, array $keys, int $width): array
    {
        $key = array_shift($keys);
        if ($key === null || !array_key_exists($key, $data)) {
            return $data;
        }
        if ($keys !== []) {
            if (is_array($data[$key])) {
                $data[$key] = self::clipPath($data[$key], $keys, $width);
            }
            return $data;
        }
        if (is_string($data[$key]) && mb_strlen($data[$key]) > $width) {
            $data[$key] = rtrim(mb_substr($data[$key], 0, $width));
        }
        return $data;
    }

    /**
     * Mapa polí výstupu modelu na sloupce, do kterých se zapisují.
     *
     * @return array<string,int>
     */
    private function limits(string $docTable, string $itemTable): array
    {
        $map = [];
        foreach (['vendor', 'customer'] as $party) {
            foreach ([
                'company_name' => 'company_name', 'ic' => 'ic', 'dic' => 'dic', 'vat_dic' => 'dic',
                'street' => 'street', 'city' => 'city', 'zip' => 'zip',
                'email' => 'main_email', 'phone' => 'phone',
            ] as $field => $column) {
                $map[$party . '.' . $field] = ['clients', $column];
            }
        }
        $map['vendor_invoice_number']    = $docTable === 'purchase_invoices' ? [$docTable, 'vendor_invoice_number'] : [$docTable, 'varsymbol'];
        $map['corrected_invoice_number'] = $map['vendor_invoice_number'];
        $map['varsymbol']                = [$docTable, 'varsymbol'];
        $map[self::PAYMENT_VS]           = [$docTable, 'payment_variable_symbol'];
        foreach (['unit', 'seller_item_code', 'item_ean', 'intrastat_cn8_code', 'intrastat_country_of_origin'] as $column) {
            $map['items.*.' . $column] = [$itemTable, $column];
        }

        $limits = [];
        foreach ($map as $path => [$table, $column]) {
            $width = $this->widthsOf($table)[$column] ?? null;
            if ($width !== null) {
                $limits[$path] = $width;
            }
        }
        return $limits;
    }

    /** @return array<string,int> */
    private function widthsOf(string $table): array
    {
        if (!isset($this->widths[$table])) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                    AND DATA_TYPE IN ('varchar', 'char') AND EXTRA NOT LIKE '%GENERATED%'"
            );
            $stmt->execute([$table]);
            $widths = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$column, $length]) {
                $widths[(string) $column] = (int) $length;
            }
            $this->widths[$table] = $widths;
        }
        return $this->widths[$table];
    }
}
