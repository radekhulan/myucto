<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Náklad přijatých dokladů sčítaný z hlavičky musí jít přes {@see \MyInvoice\Support\Sql\PurchaseCostSql}.
 *
 * Konečná faktura s odpočtovým řádkem zálohy z DDKP má v hlavičce částku po odečtu zálohy.
 * Přehled, který by sečetl holé `pi.total_without_vat` / `pi.total_with_vat`, by náklad
 * podhodnotil o základ DDKP (DDKP se z nákladů vyřazuje vždy). Guard hledá v kódu
 * (komentáře a docblocky se odstraní, aby nestačil řetězec v komentáři) metody, které
 * nad `purchase_invoices pi` sčítají holou hlavičku, a ty musí být buď na helperu,
 * nebo ve výjimce s důvodem. Výjimka se uděluje METODĚ, nikdy souboru.
 */
final class PurchaseCostSqlGuardTest extends TestCase
{
    /** Metody, které náklad sčítají a musí jít přes helper (výraz nákladu i JOIN odpočtů). */
    private const COST_SYMBOLS = [
        'Action/Dashboard/PurchaseSummaryAction.php' => ['costCol', 'costJoin'],
        'Action/Dashboard/SummaryAction.php' => ['costCol', 'purchaseCostsByMonth'],
        'Service/Crm/CrmAggregationService.php' => ['documentRange', 'aggregateRange', 'monthlyHistory', 'yearlyHistory', 'topVendors', 'expenseBreakdown'],
        'Repository/ClientRepository.php' => ['list'],
        'Action/Client/GetClientAction.php' => ['__invoke'],
        'Repository/PurchaseInvoiceRepository.php' => ['monthSummaries'],
        'Repository/TaxProfileRepository.php' => ['monthExpenses'],
        'Service/Accounting/Reports/ProjectProfitService.php' => ['sumsFromDocuments'],
        'Service/Accounting/Closing/ClosingService.php' => ['estimatesSuggest'],
    ];

    /** Metody, které hlavičku přijatého dokladu sčítají záměrně, nikoli jako náklad. */
    private const NOT_COST = [
        'Action/Dashboard/PurchaseSummaryAction.php::cashflowOutYtd' => 'skutečně zaplacené částky (cashflow), ne náklad',
        'Repository/PurchaseInvoiceRepository.php::listGroupedRows' => 'sloupce řazení seznamu podle částek dokladu, ne součet nákladu',
        'Service/Tax/Return/DpfoReturnDataProvider.php::expenseModeTransitionWarning' => 'otevřené závazky (částka k úhradě), ne náklad',
    ];

    public function testCostSymbolsUseHelper(): void
    {
        $missing = [];
        foreach (self::COST_SYMBOLS as $rel => $methods) {
            $bodies = self::methodBodies($rel);
            foreach ($methods as $method) {
                $code = $bodies[$method] ?? null;
                if ($code === null) {
                    $missing[] = "{$rel}::{$method} (metoda neexistuje)";
                    continue;
                }
                if (!preg_match('/PurchaseCostSql::(cost|net|gross|vat|deductionJoin|deductionJoinFor)\(/', $code)
                    && !preg_match('/\$this->cost(Col|Join)\(/', $code)) {
                    $missing[] = "{$rel}::{$method}";
                }
            }
        }
        // Výraz nákladu bez JOINu odpočtů by skončil chybou SQL jen za běhu: soubor,
        // který helper používá, ho musí i připojovat.
        foreach (array_keys(self::COST_SYMBOLS) as $rel) {
            $code = implode("\n", self::methodBodies($rel));
            if (preg_match('/PurchaseCostSql::(cost|net|gross|vat)\(/', $code) && !preg_match('/PurchaseCostSql::deductionJoin(For)?\(/', $code)) {
                $missing[] = "{$rel}: výraz nákladu bez PurchaseCostSql::deductionJoin()";
            }
        }
        self::assertSame([], $missing, "Náklad přijatých dokladů z hlavičky musí jít přes PurchaseCostSql:\n  " . implode("\n  ", $missing));
    }

    public function testNoOtherMethodSumsRawPurchaseHeaderAsCost(): void
    {
        $known = [];
        foreach (self::COST_SYMBOLS as $rel => $methods) {
            foreach ($methods as $m) {
                $known["{$rel}::{$m}"] = true;
            }
        }
        $src = dirname(__DIR__, 2) . '/src/';
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src)));
            foreach (self::methodBodies($rel) as $method => $code) {
                $symbol = "{$rel}::{$method}";
                if (isset($known[$symbol]) || isset(self::NOT_COST[$symbol])) {
                    continue;
                }
                if (self::sumsRawPurchaseHeader($code)) {
                    $offenders[] = $symbol;
                }
            }
        }
        self::assertSame([], $offenders, "Metody sčítají holou hlavičku přijatého dokladu. Náklad patří přes PurchaseCostSql "
            . "(COST_SYMBOLS), jiný účel zdůvodněte v NOT_COST:\n  " . implode("\n  ", $offenders));
    }

    public function testDetectorCatchesRawSumAndIgnoresComments(): void
    {
        self::assertTrue(self::sumsRawPurchaseHeader(
            "\$sql = \"SELECT SUM(pi.total_without_vat) FROM purchase_invoices pi WHERE pi.supplier_id = ?\";",
        ));
        self::assertTrue(self::sumsRawPurchaseHeader(
            "\$cost = \$p ? 'pi.total_without_vat' : 'pi.total_with_vat'; \$sql = \"SELECT SUM(\$cost) FROM purchase_invoices pi\";",
        ));
        self::assertTrue(self::sumsRawPurchaseHeader(
            "\$amount = \$alias . (\$payer ? '.total_without_vat' : '.total_with_vat'); \$t = 'purchase_invoices';",
        ));
        self::assertFalse(self::sumsRawPurchaseHeader(
            "\$sql = \"SELECT SUM(\" . PurchaseCostSql::net('pi') . \") FROM purchase_invoices pi\";",
        ));
        $stripped = self::stripComments("<?php\n// SUM(pi.total_without_vat) FROM purchase_invoices pi\n\$x = 1;\n");
        self::assertFalse(self::sumsRawPurchaseHeader($stripped), 'Komentář se nesmí počítat jako kód.');
    }

    private static function sumsRawPurchaseHeader(string $code): bool
    {
        if (!preg_match('/\bpurchase_invoices\b/', $code)) {
            return false;
        }
        return preg_match('/SUM\((?:(?!SUM\().){0,120}?\bpi\.total_with(?:out)?_vat/s', $code) === 1
            || preg_match("/'pi\\.total_with(?:out)?_vat'/", $code) === 1
            // Alias skládaný za běhu (`\$alias . '.total_without_vat'`).
            || preg_match("/'\\.total_with(?:out)?_vat'/", $code) === 1;
    }

    /** @return array<string,string> metoda => kód bez komentářů */
    private static function methodBodies(string $relative): array
    {
        $path = dirname(__DIR__, 2) . '/src/' . $relative;
        if (!is_file($path)) {
            return [];
        }
        $tokens = token_get_all((string) file_get_contents($path));
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            // Název metody bývá i klíčové slovo (list), tokenizér ho pak nevrací jako T_STRING.
            if (!is_array($tokens[$j]) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tokens[$j][1]) !== 1) {
                continue; // closure
            }
            $name = $tokens[$j][1];
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if ($j >= $count || $tokens[$j] === ';') {
                continue;
            }
            $depth = 0;
            $code = '';
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $depth++;
                    }
                    $code .= $t[1];
                    continue;
                }
                $code .= $t;
                if ($t === '{') {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $out[$name] = ($out[$name] ?? '') . $code;
            $i = $j;
        }
        return $out;
    }

    private static function stripComments(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $t) {
            if (is_array($t)) {
                if ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT) {
                    $code .= $t[1];
                }
                continue;
            }
            $code .= $t;
        }
        return $code;
    }
}
