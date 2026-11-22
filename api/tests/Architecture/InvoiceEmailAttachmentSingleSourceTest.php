<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Název přiloženého PDF faktury má jediný zdroj:
 * {@see \MyInvoice\Service\Mail\InvoiceEmailVarsBuilder::pdfAttachment()}.
 *
 * Klient si může předepsat název souboru (myinvoice#277), protože ho zpracovává
 * automat. Dřív si každá cesta e-mailu skládala přílohu sama (`basename($pdfPath)`)
 * — šest kopií na odeslání, test, automatické odeslání, upomínky a poděkování za
 * úhradu. Kdyby se kterákoli vrátila k vlastnímu poli, klient by u ní dostal
 * výchozí název a jeho automat by fakturu nepřiřadil.
 *
 * Kontroluje se kód bez komentářů (tokenizer), výjimka se uděluje metodě, ne souboru.
 */
#[Group('architecture')]
final class InvoiceEmailAttachmentSingleSourceTest extends TestCase
{
    /** Metody, které smí PDF přílohu e-mailu sestavit samy — s důvodem. */
    private const ALLOWED = [
        'InvoiceEmailVarsBuilder::pdfAttachment'   => 'jediný zdroj přílohy s PDF faktury',
        'RequestApprovalAction::__invoke'          => 'příloha je PDF výkazu práce, ne faktura',
        'RequestApprovalTestAction::__invoke'      => 'příloha je PDF výkazu práce, ne faktura',
        'MonthlyReportAction::send'                => 'příloha je měsíční účetní výkaz, ne faktura',
        'QuoteMailer::send'                        => 'příloha je archivovaná verze cenové nabídky',
    ];

    /** Cesty, kterými odchází e-mail s PDF faktury; každá musí jít přes pdfAttachment(). */
    private const INVOICE_SENDERS = [
        'Action/Invoice/SendEmailAction.php'        => 'SendEmailAction::__invoke',
        'Action/Invoice/SendTestEmailAction.php'    => 'SendTestEmailAction::__invoke',
        'Action/Invoice/SendTestReminderAction.php' => 'SendTestReminderAction::__invoke',
        'Service/Invoice/AutoIssueAndSendService.php' => 'AutoIssueAndSendService::run',
        'Service/Invoice/ReminderService.php'       => 'ReminderService::send',
        'Service/Mail/PaymentThanksMailer.php'      => 'PaymentThanksMailer::sendCommitted',
    ];

    public function testPdfPrilohuEmailuSestavujeJenPovolenaMetoda(): void
    {
        $offenders = [];
        $found = [];
        foreach ($this->sourceFiles() as $path => $tokens) {
            foreach ($this->methodsWithPdfAttachmentArray($tokens) as $symbol) {
                $found[$symbol] = true;
                if (!isset(self::ALLOWED[$symbol])) {
                    $offenders[] = "{$path}: {$symbol}";
                }
            }
        }

        self::assertArrayHasKey('InvoiceEmailVarsBuilder::pdfAttachment', $found, 'Guard nenašel ani zdroj — hledá špatně.');
        self::assertSame([], $offenders, sprintf(
            "PDF příloha e-mailu sestavená mimo InvoiceEmailVarsBuilder::pdfAttachment():\n  %s\n\n"
                . 'Jde-li o PDF faktury, použij pdfAttachment() (název podle klienta). Jiný dokument'
                . ' přidej do ALLOWED i s důvodem.',
            implode("\n  ", $offenders),
        ));
    }

    public function testKazdaCestaEmailuSFakturouPouzivaPdfAttachment(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $missing = [];
        foreach (self::INVOICE_SENDERS as $relative => $symbol) {
            $tokens = \PhpToken::tokenize((string) file_get_contents($root . $relative));
            if (!in_array($symbol, $this->methodsCalling($tokens, 'pdfAttachment'), true)) {
                $missing[] = "{$relative}: {$symbol}";
            }
        }

        self::assertSame([], $missing, "Cesta e-mailu s fakturou bez pdfAttachment():\n  " . implode("\n  ", $missing));
    }

    public function testPrimeIFrontovanePodekovaniPouzivaStejnyCommittedSender(): void
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Mail/PaymentThanksMailer.php'));
        $callers = $this->methodsCalling($tokens, 'sendCommitted');
        self::assertContains('PaymentThanksMailer::sendForInvoice', $callers);
        self::assertContains('PaymentThanksMailer::flushDeferred', $callers);
    }

    /**
     * Metody, ve kterých kód (ne komentář) obsahuje `'contentType' => 'application/pdf'`.
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private function methodsWithPdfAttachmentArray(array $tokens): array
    {
        $symbols = [];
        foreach ($this->codeTokensWithSymbol($tokens) as $i => [$token, $symbol, $code]) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && $token->text === "'contentType'"
                && ($code[$i + 1] ?? null)?->is(T_DOUBLE_ARROW)
                && ($code[$i + 2] ?? null)?->text === "'application/pdf'") {
                $symbols[] = $symbol;
            }
        }
        return array_values(array_unique($symbols));
    }

    /**
     * Metody, které volají `->$method(`.
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private function methodsCalling(array $tokens, string $method): array
    {
        $symbols = [];
        foreach ($this->codeTokensWithSymbol($tokens) as $i => [$token, $symbol, $code]) {
            if ($token->is(T_OBJECT_OPERATOR)
                && ($code[$i + 1] ?? null)?->text === $method
                && ($code[$i + 2] ?? null)?->text === '(') {
                $symbols[] = $symbol;
            }
        }
        return array_values(array_unique($symbols));
    }

    /**
     * Tokeny kódu (bez komentářů a bílých znaků) s názvem metody, ve které leží.
     *
     * @param list<\PhpToken> $tokens
     * @return iterable<int, array{\PhpToken, string, list<\PhpToken>}>
     */
    private function codeTokensWithSymbol(array $tokens): iterable
    {
        $code = array_values(array_filter($tokens, static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $class = '';
        $function = '';
        $functionDepth = null;
        $depth = 0;
        $pendingFunction = null;
        foreach ($code as $i => $token) {
            if ($token->is(T_CLASS) && ($code[$i + 1] ?? null)?->is(T_STRING)) {
                $class = $code[$i + 1]->text;
            } elseif ($token->is(T_FUNCTION) && ($code[$i + 1] ?? null)?->is(T_STRING)) {
                $pendingFunction = $code[$i + 1]->text;
            } elseif ($token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
                if ($pendingFunction !== null) {
                    $function = $pendingFunction;
                    $functionDepth = $depth;
                    $pendingFunction = null;
                }
            } elseif ($token->text === '}') {
                if ($functionDepth === $depth) {
                    $function = '';
                    $functionDepth = null;
                }
                $depth--;
            } elseif ($token->text === ';' && $pendingFunction !== null) {
                $pendingFunction = null; // abstraktní / interface metoda bez těla
            }
            yield $i => [$token, $class . '::' . $function, $code];
        }
    }

    /** @return iterable<string, list<\PhpToken>> relativní cesta → tokeny */
    private function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!str_contains($code, 'application/pdf')) {
                continue;
            }
            yield substr($file->getPathname(), strlen($root) + 1) => \PhpToken::tokenize($code);
        }
    }
}
