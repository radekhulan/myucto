<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Dashboard tržeb a dashboard nákladů čtou TYTÉŽ doklady a musí je uznávat stejně.
 *
 * Vzniklo z reklamace (2026-09), kde zákazník srovnával graf tržeb s jiným účetnictvím.
 * Data i evidence DPH seděly na haléř; rozdíl dělalo jen to, jak se čísla agregují.
 * Guard proto hlídá obě místa, kde se ta agregace předtím rozešla:
 *
 *  1. **Přepočet na CZK** žil ve dvou různých zápisech téhož výrazu (`COALESCE(IF(...))`
 *     u vydaných, `IF(... OR ... IS NULL)` u přijatých). Sémanticky totéž, ale dvě znění
 *     se rozejdou při první úpravě jednoho z nich. Jediná povolená cesta je
 *     {@see \MyInvoice\Support\Sql\CzkAmountExpr}.
 *  2. **Datum uznání nákladu** — `SummaryAction::purchaseCostsByMonth()` bralo
 *     `issue_date`, kdežto celá `PurchaseSummaryAction` `effective_cost_date`
 *     (= COALESCE(tax_date, issue_date), DUZP dle § 73). Nad týmiž doklady tak
 *     dashboard a stránka Náklady ukazovaly jiná čísla všude, kde DUZP spadlo do jiného
 *     měsíce než datum vystavení.
 *
 * Komentáře se před hledáním ZAHAZUJÍ. Guard, který prohledává syrový text souboru,
 * umí zezelenat nad větou v docblocku — a taková zelená nekontroluje nic.
 */
final class DashboardCurrencyParityTest extends TestCase
{
    /** Syrové kurzové výrazy, které nahradil CzkAmountExpr. */
    private const RAW_FX_PATTERNS = [
        "IF(cur.code = 'CZK'",
        "IF(cur.code = 'CZK' OR",
    ];

    public function testDashboardActionsConvertCurrencyOnlyThroughSsot(): void
    {
        foreach ([SummaryAction::class, PurchaseSummaryAction::class] as $class) {
            $code = $this->codeWithoutComments((string) (new \ReflectionClass($class))->getFileName());

            foreach (self::RAW_FX_PATTERNS as $raw) {
                self::assertStringNotContainsString($raw, $code, sprintf(
                    "%s: kurzový přepočet patří výhradně do CzkAmountExpr::amount()/rate().\n"
                    . 'Syrový výraz %s tu obchází SSOT a rozejde se s druhou stranou dashboardu.',
                    $class,
                    $raw,
                ));
            }

            self::assertStringContainsString('CzkAmountExpr::', $code, sprintf(
                '%s: agreguje napříč měnami, takže SSOT pro přepočet musí volat.',
                $class,
            ));
        }
    }

    /**
     * Výjimka je udělená METODĚ, ne souboru: `SummaryAction` používá `issue_date`
     * legitimně jinde (Ø doba úhrady = DATEDIFF(paid_at, issue_date)), takže allowlist
     * na úrovni souboru by kontrolu vypnul i tam, kde platit má.
     */
    public function testDashboardRecognisesPurchaseCostAtEffectiveCostDate(): void
    {
        foreach ([
            [SummaryAction::class, 'purchaseCostsByMonth'],
            [PurchaseSummaryAction::class, 'costsByMonth'],
        ] as [$class, $method]) {
            $body = $this->methodBody($class, $method);

            self::assertStringContainsString('effective_cost_date', $body, sprintf(
                '%s::%s(): náklad se uznává k effective_cost_date (DUZP má přednost, § 73).',
                $class,
                $method,
            ));
            self::assertStringNotContainsString('pi.issue_date', $body, sprintf(
                "%s::%s(): agregace nákladu podle pi.issue_date se rozchází s druhou stranou\n"
                . 'dashboardu u každého dokladu, kde DUZP padne do jiného měsíce.',
                $class,
                $method,
            ));
        }
    }

    /** Tělo metody bez komentářů — čte se ze zdrojáku podle rozsahu řádků. */
    private function methodBody(string $class, string $method): string
    {
        $m = new ReflectionMethod($class, $method);
        $file = (string) $m->getFileName();
        $lines = (array) file($file);
        $slice = array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);

        return $this->stripComments('<?php ' . implode('', $slice));
    }

    private function codeWithoutComments(string $file): string
    {
        return $this->stripComments((string) file_get_contents($file));
    }

    /**
     * Zahodí komentáře, zachová kód i řetězcové literály (SQL fragmenty tam žijí).
     * Nekompletní úryvek (tělo metody) tokenizer zvládne — případný parse warning
     * potlačujeme, protože nás zajímají jen tokeny, ne validita celku.
     */
    private function stripComments(string $code): string
    {
        $tokens = @token_get_all($code);
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
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
