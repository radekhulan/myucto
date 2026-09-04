<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `reset.php` maže UŽIVATELSKÁ data. Globální číselník ani provozní údaj instance
 * uživatelská data nejsou — a hlavně je po smazání nikdo nevrátí: seedují je migrace,
 * které jsou evidované jako proběhlé, takže je `migrate.php` znovu nespustí.
 *
 * Nález z hostované instance: po `reset.php` zmizel `oss_member_state_rates` a import
 * i vystavení začaly odmítat KAŽDÝ doklad se sazbou vyšší než 0 % hláškou, která radí
 * spustit `migrate.php` — jenže ten číselník nevrátil. Instalace se opravila jen ručním
 * přehráním migrace 1319.
 *
 * Tenhle test hlídá obě poloviny nápravy:
 *   1. reset ty tabulky nemaže (prevence),
 *   2. `migrate.php` prázdný číselník dotáhne, takže rada v hlášce PLATÍ (léčba).
 */
final class ResetKeepsGlobalCodebooksTest extends TestCase
{
    /**
     * Tabulky bez `supplier_id` plněné seedem migrací. Ověřeno dotazem do schématu,
     * ne odhadem podle názvu.
     *
     * `activity_log_chain_head` a `cron_heartbeat` tu ZÁMĚRNĚ nejsou: hlava hash řetězu
     * musí padnout SPOLU s auditním logem (jinak by ukazovala na hash smazaných záznamů
     * a ověření řetězu by selhalo navždy) a heartbeat se sám obnoví při nejbližším běhu
     * plánovaných úloh.
     *
     * @return list<array{0:string, 1:string}> [tabulka, proč ji reset nesmí smazat]
     */
    public static function globalTables(): array
    {
        return [
            ['oss_member_state_rates', 'legislativní sazby DPH členských států (seed 1152/1292/1294)'],
            ['license', 'licence instance — znovuzaložení ji degraduje na trial'],
            ['backup_schedule_contract', 'parametry sjednané s poskytovatelem hostingu'],
            ['instance_storage_usage', 'podklad pro měření úložiště u poskytovatele'],
        ];
    }

    #[DataProvider('globalTables')]
    public function testResetKeepsGlobalTable(string $table, string $why): void
    {
        $source = $this->resetSource();
        $keepBlock = $this->keepBlock($source);

        self::assertStringContainsString(
            "'" . $table . "'",
            $keepBlock,
            "reset.php musí ponechat `{$table}` — {$why}. Po smazání ho migrate.php nevrátí.",
        );
    }

    /**
     * Číselník příjemců podání (ČSSZ e-Podání, zdravotní pojišťovny včetně ID datových
     * schránek) má `supplier_id`, ale globální řádky jsou legislativní údaj. Reset proto
     * smí smazat jen per-tenant override.
     */
    public function testResetDeletesOnlyTenantSubmissionRecipients(): void
    {
        self::assertMatchesRegularExpression(
            "/'submission_recipients'\s*=>\s*'supplier_id IS NOT NULL'/",
            $this->resetSource(),
            'reset.php musí u submission_recipients mazat jen per-tenant řádky, ne celý číselník.',
        );
    }

    /**
     * JÁDRO NÁLEZU: hlášky uživateli radí spustit `migrate.php`. Ta rada platí jen tehdy,
     * když migrate.php prázdný číselník opravdu dotáhne — migrace 1319 se sama znovu
     * nespustí, protože je evidovaná jako proběhlá.
     */
    public function testMigrateSelfHealsTheRateCodebook(): void
    {
        $migrate = file_get_contents(dirname(__DIR__, 2) . '/bin/migrate.php');
        self::assertIsString($migrate);

        self::assertStringContainsString(
            'backfill-oss-rates.php',
            $migrate,
            'migrate.php musí umět dotáhnout číselník sazeb, jinak jeho doporučení v hláškách lže.',
        );
        self::assertStringContainsString(
            'oss_member_state_rates WHERE is_custom = 0',
            $migrate,
            'Podmínka se musí ptát na SEEDOVANÉ řádky — vlastní sazba uživatele nesmí díru zamaskovat.',
        );

        $script = dirname(__DIR__, 2) . '/bin/backfill-oss-rates.php';
        self::assertFileExists($script, 'Skript dotahu musí existovat, jinak auto-backfill spadne.');

        $body = (string) file_get_contents($script);
        self::assertStringContainsString(
            '1319_oss_member_state_rates_self_heal.sql',
            $body,
            'Dotah musí použít sebeopravnou migraci, ne vlastní kopii 85 řádků sazeb.',
        );
    }

    /** Blok `$keep = [...]` ze zdrojáku — aby se shoda nechytla na komentář jinde v souboru. */
    private function keepBlock(string $source): string
    {
        $start = strpos($source, '$keep = [');
        self::assertNotFalse($start, 'reset.php musí mít keep-list.');
        $end = strpos($source, '];', $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    private function resetSource(): string
    {
        $path = dirname(__DIR__, 2) . '/bin/reset.php';
        $source = file_get_contents($path);
        self::assertIsString($source, 'Reset skript musí existovat a jít načíst.');

        return $source;
    }
}
