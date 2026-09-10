<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Pojistka proti díře na KONCI číselné řady. Jediné místo pro vydané doklady
 * (`invoice_counters`: faktury, zálohy, dobropisy) i přijaté faktury
 * (`purchase_invoice_counters`).
 *
 * Počítadlo řady smí stát nejvýš na nejvyšším skutečně použitém čísle období, případně
 * na ručně nastaveném začátku řady (`invoice_counters.floor_number`). Stojí-li výš,
 * nějaká cesta si čísla vzala a doklad s nimi nevznikl. Příští doklad by pak dostal
 * číslo s dírou před sebou. Guard počítadlo srovná dolů a zapíše do logu i do
 * activity_log, z jaké hodnoty, aby šlo dohledat, odkud díry přicházejí.
 *
 * Díry UPROSTŘED řady (smazaný doklad, který nebyl poslední) se nezaplňují, ty jsou
 * auditní stopou a hlásí je {@see InvoiceSeriesCompletenessService}. Ta odpovídá na
 * jinou otázku (která vydaná čísla chybí), proto se na ni guard nenapojuje; sdílí
 * s ní jen princip, že do řady patří čísla podle shody se šablonou.
 *
 * Souběh: srovnání smí proběhnout jen uvnitř transakce volajícího, která na doklad
 * zároveň zapíše přidělené číslo. Řádek počítadla se zamkne FOR UPDATE až do commitu,
 * takže dvě vystavení téže řady jdou za sebou. Zbývá jedna past: v REPEATABLE READ
 * čte transakce vydaná čísla ze snímku, který mohl vzniknout dřív, než jiné vystavení
 * commitlo. Nejvyšší použité číslo by pak bylo zastaralé a srovnání by přidělilo číslo
 * znovu. Proto se počítadlo přečte ještě bez zámku: liší-li se od zamčené hodnoty,
 * snímek je starší než poslední zápis do řady a srovnání se vynechá.
 *
 * Volající, kteří transakci přidělení otevírají sami, ji otevírají v READ COMMITTED.
 * MariaDB 11.8 má zapnutou snapshot isolation a pod REPEATABLE READ by zámek
 * počítadla po souběžném vystavení skončil chybou 1020 místo čekání na číslo.
 */
final class NumberSeriesGapGuard
{
    /** Tabulky počítadel a sloupce jejich primárního klíče (pořadí = pořadí parametrů). */
    private const TABLES = [
        'invoice_counters'          => ['supplier_id', 'client_id', 'revenue_category_id', 'invoice_type', 'period'],
        'purchase_invoice_counters' => ['supplier_id', 'period'],
    ];

    public function __construct(
        private readonly Connection $db,
        // Povinné: autowiring PHP-DI volitelné parametry přeskakuje a záznam do
        // activity_log i logu by se tiše ztratil.
        private readonly ActivityLogger $activity,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Srovná počítadlo řady na max(nejvyšší použité číslo, ruční začátek řady), pokud je
     * výš. Mimo transakci volajícího nedělá nic (viz docblock třídy).
     *
     * @param array<string,int|string> $key          hodnoty sloupců PK řady
     * @param callable():int            $highestUsed nejvyšší skutečně použitý counter řady v období
     * @return bool true = počítadlo bylo srovnáno
     */
    public function clamp(string $table, array $key, callable $highestUsed): bool
    {
        $columns = self::TABLES[$table] ?? throw new \InvalidArgumentException("Neznámá tabulka počítadla: {$table}");
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            return false;
        }

        $where  = implode(' AND ', array_map(static fn (string $c): string => "{$c} = ?", $columns));
        $params = array_map(static fn (string $c): int|string => $key[$c], $columns);
        $floorColumn = $this->hasFloor($table) ? 'floor_number' : '0';

        $locked = $pdo->prepare("SELECT last_number, {$floorColumn} AS floor_number FROM {$table} WHERE {$where} FOR UPDATE");
        $locked->execute($params);
        $row = $locked->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $counter = (int) $row['last_number'];
        $floor   = (int) $row['floor_number'];
        if ($counter <= $floor) {
            return false;
        }

        $plain = $pdo->prepare("SELECT last_number FROM {$table} WHERE {$where}");
        $plain->execute($params);
        if ((int) $plain->fetchColumn() !== $counter) {
            return false;
        }

        $highest = $highestUsed();
        $target  = max($highest, $floor);
        if ($counter <= $target) {
            return false;
        }

        $pdo->prepare("UPDATE {$table} SET last_number = ? WHERE {$where}")->execute([$target, ...$params]);

        $context = $key + [
            'counter_table' => $table,
            'from_counter'  => $counter,
            'to_counter'    => $target,
            'highest_used'  => $highest,
            'floor_number'  => $floor,
        ];
        $this->logger?->warning('číselná řada: počítadlo utíkalo před vydanými čísly, srovnáno dolů', $context);
        $supplierId = (int) $key['supplier_id'];
        $this->activity?->log('number_series.counter_clamped', null, 'supplier', $supplierId, $context, null, 'system', $supplierId);

        return true;
    }

    public function hasFloor(string $table): bool
    {
        return $table === 'invoice_counters' && $this->db->hasColumn('invoice_counters', 'floor_number');
    }
}
