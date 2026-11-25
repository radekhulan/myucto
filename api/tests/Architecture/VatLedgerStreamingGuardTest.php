<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Evidence DPH se v aplikačním kódu čte proudem, ne materializovaná v poli.
 *
 * Normalizovaný řádek {@see \MyInvoice\Service\Report\VatLedgerService} drží kolem 3 KB.
 * Firma s 30 000 fakturami v jednom měsíci (138 tisíc položek) potřebovala na náhled
 * přiznání přes 900 MB a na zaúčtování jedné faktury celou roční knihu; na hostingu
 * s limitem 256 MB pak vystavení faktury, dashboard i výkazy DPH končily HTTP 500.
 * `rows()`, `returnRows()` a `ossSelectedSupplyRows()` zůstávají pro testy a nástroje,
 * aplikační kód čte přes `iterateRows()` / `iterateReturnRows()`, případně
 * `documentRows()` pro jeden doklad.
 */
final class VatLedgerStreamingGuardTest extends TestCase
{
    public function testApplicationCodeDoesNotMaterializeLedgerPeriod(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = self::withoutComments((string) file_get_contents($file->getPathname()));
            if (preg_match_all('/->\s*(?:ledger|vatLedger)\s*->\s*(rows|returnRows|ossSelectedSupplyRows)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[1] as [$method, $offset]) {
                    $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                    $offenders[] = basename($file->getPathname()) . ":{$line} {$method}()";
                }
            }
        }

        self::assertSame([], $offenders, 'Evidenci DPH čti proudem (iterateRows/iterateReturnRows): ' . implode(', ', $offenders));
    }

    public function testPostingReadsOnlyThePostedDocument(): void
    {
        $code = self::withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Accounting/PostingService.php'));
        $start = strpos($code, 'function ledgerTotals(');
        self::assertNotFalse($start);
        $body = substr($code, $start, 1500);

        self::assertStringContainsString('->documentRows(', $body, 'Zaúčtování dokladu čte jen jeho řádky evidence.');
    }

    public function testSalesVoucherAggregationReadsOnlyTheRequestedPeriodAndDocuments(): void
    {
        $code = self::withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Report/VatLedgerService.php'));
        $joined = preg_match('/LEFT JOIN \(SELECT vii\.invoice_id,(.*?)\) vtot ON vtot\.invoice_id = i\.id/s', $code, $parts);
        self::assertSame(1, $joined);
        self::assertStringContainsString('vi.supplier_id = ? AND vi.effective_tax_date BETWEEN ? AND ?', $parts[1]);
        self::assertStringContainsString('{$voucherDocumentFilter}', $parts[1]);
    }

    private static function withoutComments(string $raw): string
    {
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
