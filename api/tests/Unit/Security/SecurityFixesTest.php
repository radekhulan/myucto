<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifikuje opravy z security report @andrejtomci (3.5.1 release).
 *
 * Nálezy:
 *   #1 BankTx cross-tenant IDOR (match/ignore/unmatch missing supplier scope)
 *   #2 LFI přes logo_path mass-assignment + unguarded preview
 *   #3 HTML injection via {{ intro|raw }} a varsymbol bez charset validace
 *   #4 WorkReport project_id cross-supplier
 *
 * Tento test ověřuje **negative cases** — že útok teď selže. Volá přímo služby
 * (ne přes HTTP), takže nepotřebuje běžící server ani DB.
 */
final class SecurityFixesTest extends TestCase
{
    /**
     * #2 — SettingsAction mass-assignment whitelist nesmí obsahovat logo_path / signature_path
     */
    public function testSettingsActionMassAssignDoesNotIncludeLogoPath(): void
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Action/Settings/SettingsAction.php');
        self::assertIsString($code);

        // Najdi $allowed array — najdeme jen relevantní řádky uvnitř updateSupplierById
        $start = strpos($code, 'updateSupplierById');
        $end   = strpos($code, "\n    }", $start);
        $methodBody = substr($code, $start, $end - $start);

        // V allowed seznamu (mass-assign whitelist) nesmí být logo_path ani signature_path
        // jako keys/values. Použijeme jednoduchou substring check.
        self::assertStringNotContainsString("'logo_path'", $methodBody,
            'logo_path nesmí být v mass-assign whitelist (LFI vektor, security #2)');
        self::assertStringNotContainsString("'signature_path'", $methodBody,
            'signature_path nesmí být v mass-assign whitelist (parity-sink LFI)');
    }

    /**
     * #3 — Email šablony invoice_send.*.html.twig už nesmí používat `intro|raw`
     */
    public function testEmailTemplatesDoNotUseIntroRaw(): void
    {
        $tpls = [
            dirname(__DIR__, 4) . '/api/templates/email/invoice_send.cs.html.twig',
            dirname(__DIR__, 4) . '/api/templates/email/invoice_send.en.html.twig',
        ];
        foreach ($tpls as $tpl) {
            $content = file_get_contents($tpl);
            self::assertIsString($content);
            self::assertStringNotContainsString('intro|raw', $content,
                "Šablona $tpl nesmí používat {{ intro|raw }} (HTML injection vektor, security #3)");
            // Bonus: varsymbol musí být v safe Twig kontextu (autoescape on)
            self::assertStringContainsString('{{ invoice.varsymbol }}', $content,
                "Šablona musí používat {{ invoice.varsymbol }} v autoescape kontextu");
        }
    }

    /**
     * #3 — InvoiceImportService musí validovat charset varsymbolu (gateway fix)
     *
     * Pravidlo se přestěhovalo do {@see PohodaXmlParser::VARSYMBOL_PATTERN}: parser podle
     * něj pozná, kdy sáhnout po náhradě VS (GUID ze SuperFaktury), a import podle TÉHOŽ
     * pravidla validuje. Guard proto hlídá obojí — že SSOT je pořád ten allowlist a že
     * ho volající skutečně volá. Druhá kopie regexu v importu je zakázaná: rozešly by se
     * a parser by nabízel náhradu za hodnoty, které import bere (nebo naopak).
     */
    public function testImportServiceValidatesVarsymbolCharset(): void
    {
        // Chování SSOT — silnější než grep: allowlist musí reálně odmítnout injection
        // vektory, kvůli kterým omezení vzniklo (varsymbol teče do e-mailů, PDF/ZIP
        // názvů a CSV buněk).
        foreach (['FA2026001', 'a', 'A-b_9', '12345678901234567890'] as $ok) {
            self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol($ok), "Legitimní VS '{$ok}' musí projít");
        }
        foreach ([
            '',
            '123456789012345678901',                // 21 znaků = přes limit DB sloupce
            '<a href=//evil>x</a>',
            '=cmd|\' /C calc\'!A0',
            "FA\n2026",
            'FA 2026',
            'FA/2026',
            '3f2a91c4-7b1d-4e52-9a08-2c6f5d0b1e77',  // GUID (36 znaků)
        ] as $bad) {
            self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol($bad),
                'Allowlist musí odmítnout ' . var_export($bad, true) . ' (security #3)');
        }

        // SSOT je pořád TEN allowlist (A-Z, a-z, 0-9, _, -, max 20) — ne uvolněný regex,
        // který by testům výše vyhověl jinou cestou.
        $parser = file_get_contents(dirname(__DIR__, 4) . '/api/src/Service/Import/PohodaXmlParser.php');
        self::assertIsString($parser);
        self::assertStringContainsString("'/^[A-Za-z0-9_-]{1,20}\$/'", $parser,
            'VARSYMBOL_PATTERN musí zůstat allowlistem A-Z, a-z, 0-9, _, - (security #3)');

        // A brána ho musí volat — import bez validace by SSOT obešel.
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Service/Import/InvoiceImportService.php');
        self::assertIsString($code);
        self::assertStringContainsString('PohodaXmlParser::isAcceptableVarsymbol($varsymbol)', $code,
            'processOne() musí validovat varsymbol přes sdílený allowlist (security #3)');
        self::assertStringNotContainsString("preg_match('/^[A-Za-z0-9_-]{1,20}\$/'", $code,
            'Import nesmí mít vlastní kopii regexu — dvě kopie pravidla se rozejdou (SSOT)');
    }

    /**
     * #1 — BankStatementAction::ignore musí volat ActivityLogger (forensic trace)
     */
    public function testBankIgnoreWritesActivityLog(): void
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        // Najdi `ignore` method
        $wrapperStart = strpos($code, 'public function ignore(');
        $wrapper = substr($code, $wrapperStart, strpos($code, "\n    }", $wrapperStart) - $wrapperStart);
        self::assertStringContainsString('withNoticeLock', $wrapper);
        self::assertStringContainsString('ignoreLocked', $wrapper);
        $start = strpos($code, 'private function ignoreLocked(');
        self::assertNotFalse($start, 'ignore() metoda musí existovat');
        $end = strpos($code, "\n    }", $start);
        $methodBody = substr($code, $start, $end - $start);

        self::assertStringContainsString('bank.tx_ignore', $methodBody,
            'ignore() musí logovat bank.tx_ignore action (forensic, security #1)');
        self::assertStringContainsString('logIgnoreChange(', $methodBody);
        $logStart = strpos($code, 'private function logIgnoreChange(');
        $logEnd = strpos($code, "\n    }", $logStart);
        self::assertStringContainsString('logger->log', substr($code, $logStart, $logEnd - $logStart));
        self::assertStringContainsString('lockOwnedTransaction(', $methodBody);
    }

    /**
     * #1 — match a unmatch také musí ověřit tx ownership
     */
    public function testBankMutationsCheckSupplierScope(): void
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        foreach (['manualMatch', 'unmatch', 'ignore'] as $method) {
            $wrapperStart = strpos($code, "public function $method(");
            self::assertNotFalse($wrapperStart);
            $wrapper = substr($code, $wrapperStart, strpos($code, "\n    }", $wrapperStart) - $wrapperStart);
            self::assertStringContainsString('withNoticeLock', $wrapper);
            self::assertStringContainsString($method . 'Locked', $wrapper);
            $start = strpos($code, "private function {$method}Locked(");
            self::assertNotFalse($start, "$method() metoda musí existovat");
            $end = strpos($code, "\n    }", $start);
            $body = substr($code, $start, $end - $start);
            self::assertStringContainsString(in_array($method, ['manualMatch', 'unmatch'], true) ? 'withCurrentBankSource(' : 'lockOwnedTransaction(', $body,
                "$method() musí uzamknout a ověřit vlastnictví pohybu");
        }
        $lockStart = strpos($code, 'private function lockOwnedTransaction(');
        $lockEnd = strpos($code, "\n    }", $lockStart);
        $lockBody = substr($code, $lockStart, $lockEnd - $lockStart);
        self::assertStringContainsString('bankPosting->lockTransaction(SupplierGuard::currentId($request), $txId)', $lockBody);
        $contextStart = strpos($code, 'private function withCurrentBankSource(');
        self::assertNotFalse($contextStart);
        $contextEnd = strpos($code, "\n    }", $contextStart);
        $contextBody = substr($code, $contextStart, $contextEnd - $contextStart);
        self::assertStringContainsString('bankPosting->lockTransaction($supplierId, $id)', $contextBody);
        self::assertStringContainsString('bankPosting->lockStatement($supplierId, $id)', $contextBody);
        foreach (['performManualMatch', 'performUnmatch'] as $method) {
            $start = strpos($code, "private function $method(");
            $end = strpos($code, "\n    }", $start);
            self::assertStringContainsString('txBelongsToCurrentSupplier(', substr($code, $start, $end - $start));
        }
    }

    /**
     * #2 (DiD) — InvoicePdfRenderer::resolveLogoPath musí použít SafeLogoPath
     */
    public function testPdfRendererUsesSafeLogoPath(): void
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Service/Pdf/InvoicePdfRenderer.php');
        self::assertIsString($code);

        $start = strpos($code, 'private function resolveLogoPath(');
        self::assertNotFalse($start, 'resolveLogoPath() musí existovat');
        $end = strpos($code, "\n    }", $start);
        $body = substr($code, $start, $end - $start);

        self::assertStringContainsString('SafeLogoPath::resolve', $body,
            'resolveLogoPath() musí použít SafeLogoPath (security #2 DiD)');
    }

    /**
     * #4 — SaveWorkReportAction musí validovat project ownership (project_id supplier scope)
     */
    public function testWorkReportValidatesProjectOwnership(): void
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/Action/WorkReport/SaveWorkReportAction.php');
        self::assertIsString($code);
        self::assertStringContainsString('ProjectRepository', $code,
            'SaveWorkReportAction musí mít DI na ProjectRepository (security #4)');
        self::assertStringContainsString('SupplierGuard::owns($request, $project)', $code,
            'Project ownership check musí být v SaveWorkReportAction (security #4)');
    }
}
