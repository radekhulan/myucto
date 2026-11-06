<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Eet\EetCashDocumentRecorder;
use MyInvoice\Service\Eet\EetInvoicePaymentGate;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PHPUnit\Framework\TestCase;

/**
 * Mapa vstupů plateb vůči EET (EET-05).
 *
 * Každé místo, které zakládá účetní úhradu faktury přes
 * {@see InvoicePaymentService::recordPayment()}, musí být vědomě zařazené: buď
 * evidenci tržeb nepodléhá (banka jen ověřuje již zachycenou platbu, zápočet ani
 * vzorová data nejsou nová platba), nebo je napojené na EET přes společný příkaz
 * ({@see EetPaymentCommand}, {@see EetInvoicePaymentGate}) či pokladnu
 * ({@see EetCashDocumentRecorder}). Nový neklasifikovaný volající shodí test dřív,
 * než by tiše založil hotovostní úhradu bez evidence.
 *
 * Výjimky se udělují SYMBOLU (třída::metoda), ne souboru. Volající se hledají
 * tokenizérem, takže zmínka v komentáři nic nezapočítá ani neskryje.
 */
final class EetPaymentEntryPointGuardTest extends TestCase
{
    private const NOT_SUBJECT = 'not_subject';
    private const CONNECTED = 'connected';

    /**
     * symbol => [zařazení, důvod, (u napojených) třída EET, kterou volající dostává v konstruktoru]
     *
     * @var array<string,array{0:string,1:string,2?:class-string}>
     */
    private const CALLERS = [
        'MyInvoice\Service\Eet\EetPaymentCommand::recordInvoicePayment' => [self::CONNECTED,
            'Úhrada faktury společného platebního příkazu (accept, rychlá účtenka přes acceptWithinTransaction i tržba '
                . 'evidovaná externím systémem): událost, úhrada a EET záznam v jedné transakci.',
            InvoicePaymentService::class],
        'MyInvoice\Action\Invoice\CreatePaymentAction::__invoke' => [self::CONNECTED,
            'Ruční úhrada: u aktivní EET rozhoduje EetInvoicePaymentGate; přímý zápis jen bez evidence nebo u platby mimo EET.',
            EetInvoicePaymentGate::class],
        'MyInvoice\Action\Invoice\MarkPaidAction::__invoke' => [self::CONNECTED,
            'Označení zaplaceno: u aktivní EET rozhoduje EetInvoicePaymentGate; přímý zápis jen bez evidence nebo u platby mimo EET.',
            EetInvoicePaymentGate::class],
        'MyInvoice\Service\Accounting\Cash\CashDocumentService::applySideEffects' => [self::CONNECTED,
            'Úhrada z PPD: tržbu se stejnou platební událostí zakládá EetCashDocumentRecorder při zaúčtování.',
            EetCashDocumentRecorder::class],
        'MyInvoice\Service\Bank\StatementMatcher::doMatchCurrent' => [self::NOT_SUBJECT,
            'Banka: automatické i sloučené párování výpisu jen ověřuje již zachycenou platbu (QR příkaz vypořádá PaymentEventReconciliation).'],
        'MyInvoice\Service\Bank\Match\MatchSuggestionService::applyCandidate' => [self::NOT_SUBJECT,
            'Banka: přijetí návrhu párování, žádná nová tržba.'],
        'MyInvoice\Action\Bank\BankStatementAction::performManualMatch' => [self::NOT_SUBJECT,
            'Banka: ruční párování pohybu, žádná nová tržba.'],
        'MyInvoice\Action\Bank\BankStatementAction::manualMatchSplit' => [self::NOT_SUBJECT,
            'Banka: ruční sloučené párování, žádná nová tržba.'],
        'MyInvoice\Service\Bank\MatchedInvoicePaymentRepair::repair' => [self::NOT_SUBJECT,
            'Banka: obnova úhrady ztracené vazby k již spárovanému pohybu.'],
        'MyInvoice\Service\Import\IdokladBankTransactionImporter::matchPairedIssuedInvoice' => [self::NOT_SUBJECT,
            'Banka: import bankovních plateb z iDokladu (převzatá historie).'],
        'MyInvoice\Service\Accounting\OffsetService::settleInvoice' => [self::NOT_SUBJECT,
            'Vzájemný zápočet: zánik pohledávek bez nové platby.'],
        'MyInvoice\Service\Invoice\CreditNoteOffsetService::applyForInvoice' => [self::NOT_SUBJECT,
            'Zápočet dobropisu: snížení pohledávky bez nové platby.'],
        'MyInvoice\Service\Accounting\InvoiceSettlementService::create' => [self::NOT_SUBJECT,
            'Vypořádání proti účtu (zápočet na 311): bez nové platby.'],
        'MyInvoice\Service\Accounting\InvoiceSettlementService::settleVoucherRedemption' => [self::NOT_SUBJECT,
            'Uplatnění poukazu v daňové evidenci: vyrovnání faktury bez nové platby, tržbu (čerpání kreditu) eviduje volající EetPaymentCommand.'],
        'MyInvoice\Service\Sample\SampleDataGenerator::seedBankStatements' => [self::NOT_SUBJECT,
            'Vzorová data demo firmy, ne skutečná platba.'],
        'MyInvoice\Service\Sample\SampleWorkflowGenerator::generate' => [self::NOT_SUBJECT,
            'Vzorová data demo firmy, ne skutečná platba.'],
    ];

    /**
     * Vrácení peněz k dokladu k vyplacení ({@see InvoicePaymentService::markRefunded()}) je
     * záporná tržba, když vrací peníze evidované tržby kontaktně (EET-08). Zápočet dobropisu
     * ({@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}) peníze nevrací a vrácení
     * nevolá vůbec.
     *
     * @var array<string,array{0:string,1:string,2?:class-string}>
     */
    private const REFUND_CALLERS = [
        'MyInvoice\Action\Invoice\MarkPaidAction::__invoke' => [self::CONNECTED,
            'Označení dobropisu jako vráceného: o záporné tržbě rozhoduje EetCreditNoteRefundGate podle způsobu vrácení.',
            \MyInvoice\Service\Eet\EetCreditNoteRefundGate::class],
        'MyInvoice\Service\Accounting\Cash\CashDocumentService::applySideEffects' => [self::CONNECTED,
            'Výdajový doklad k dobropisu: hotovost z pokladny, záporné opravy zakládá EetCashDocumentRecorder::afterRefundPost.',
            EetCashDocumentRecorder::class],
        'MyInvoice\Action\Bank\BankStatementAction::recordRefundMatch' => [self::NOT_SUBJECT,
            'Banka: vrácení převodem na účet je bezkontaktní, evidovat ho nemusí (metodika k § 8 ZoET).'],
        'MyInvoice\Service\Accounting\GoPay\GoPayService::reconcileCreditNoteStatuses' => [self::NOT_SUBJECT,
            'Platební brána: vrácení online platby na kartu zákazníka je bezkontaktní.'],
        'MyInvoice\Service\Invoice\InvoicePaymentService::markCreditNoteRefunded' => [self::NOT_SUBJECT,
            'Zastaralý alias markRefunded(); zařazení nesou jeho volající.'],
    ];

    public function testEveryRefundCallerIsClassified(): void
    {
        $found = self::callers(dirname(__DIR__, 2) . '/src', ['markRefunded', 'markCreditNoteRefunded']);
        self::assertNotEmpty($found, 'Skener nenašel žádné vrácení peněz — je rozbitý.');
        $unknown = array_values(array_diff(array_keys($found), array_keys(self::REFUND_CALLERS)));
        self::assertSame([], $unknown, "Nové vrácení peněz bez zařazení vůči EET:\n  " . implode("\n  ", array_map(
            static fn (string $s): string => $s . ' (' . $found[$s] . ')', $unknown)));
        $stale = array_values(array_diff(array_keys(self::REFUND_CALLERS), array_keys($found)));
        self::assertSame([], $stale, 'Zastaralé zařazení vrácení: ' . implode(', ', $stale));
        foreach (self::REFUND_CALLERS as $symbol => $entry) {
            if ($entry[0] !== self::CONNECTED) {
                continue;
            }
            [$class] = explode('::', $symbol);
            $types = array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? []);
            self::assertContains($entry[2], $types, $symbol . ' je zařazený jako napojený na EET, ale nedostává ' . $entry[2] . '.');
        }
    }

    public function testEveryRecordPaymentCallerIsClassified(): void
    {
        $found = self::callers(dirname(__DIR__, 2) . '/src');
        self::assertNotEmpty($found, 'Skener nenašel žádné volání recordPayment() — je rozbitý.');

        $unknown = array_values(array_diff(array_keys($found), array_keys(self::CALLERS)));
        self::assertSame([], $unknown,
            "Nový vstup plateb bez zařazení vůči EET:\n  " . implode("\n  ", array_map(
                static fn (string $s): string => $s . ' (' . $found[$s] . ')', $unknown))
            . "\nZařaďte ho do EetPaymentEntryPointGuardTest::CALLERS: nepodléhá EET (s důvodem), "
            . 'nebo ho napojte přes EetPaymentCommand / EetInvoicePaymentGate / EetCashDocumentRecorder.');

        $stale = array_values(array_diff(array_keys(self::CALLERS), array_keys($found)));
        self::assertSame([], $stale, 'Zastaralé zařazení (symbol už recordPayment() nevolá): ' . implode(', ', $stale));
    }

    public function testConnectedCallersReallyDependOnEet(): void
    {
        foreach (self::CALLERS as $symbol => $entry) {
            self::assertContains($entry[0], [self::NOT_SUBJECT, self::CONNECTED], $symbol);
            self::assertNotSame('', trim($entry[1]), $symbol . ' nemá důvod zařazení.');
            if ($entry[0] !== self::CONNECTED) {
                continue;
            }
            [$class] = explode('::', $symbol);
            $constructor = (new \ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor, $symbol);
            $types = array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                $constructor->getParameters(),
            );
            self::assertContains($entry[2], $types,
                $symbol . ' je zařazený jako napojený na EET, ale nedostává ' . $entry[2] . '.');
        }
        self::assertSame(
            [CreatePaymentAction::class, MarkPaidAction::class, CashDocumentService::class],
            array_values(array_intersect([CreatePaymentAction::class, MarkPaidAction::class, CashDocumentService::class],
                array_map(static fn (string $s): string => explode('::', $s)[0], array_keys(self::CALLERS)))),
        );
    }

    /** Skener sám: najde volání v metodě a ignoruje komentář i řetězec. */
    public function testScannerFindsCallsByTokensOnly(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Demo;
            final class Probe {
                // $this->payments->recordPayment(1, 1.0, '2026-01-01');
                public function real(): void { $x = fn () => 1; $this->payments->recordPayment(1, 1.0, '2026-01-01'); }
                public function text(): string { return '->recordPayment('; }
            }
            PHP;
        self::assertSame(['Demo\Probe::real'], array_keys(self::callersInCode($code, 'probe.php')));
    }

    /**
     * @param list<string> $methods
     * @return array<string,string> symbol => soubor:řádek
     */
    private static function callers(string $root, array $methods = ['recordPayment']): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (array_filter($methods, static fn (string $m): bool => str_contains($code, $m)) === []) {
                continue;
            }
            $out += self::callersInCode($code, str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)), $methods);
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<string> $methods
     * @return array<string,string>
     */
    private static function callersInCode(string $code, string $label, array $methods = ['recordPayment']): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $class = null;
        $classDepth = null;
        $method = null;
        $methodDepth = null;
        $depth = 0;
        $pendingClass = false;
        $pendingMethod = null;
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '{') {
                    $depth++;
                    if ($pendingClass !== false && $classDepth === null) {
                        $classDepth = $depth;
                        $pendingClass = false;
                    } elseif ($pendingMethod !== null && $methodDepth === null) {
                        $method = $pendingMethod;
                        $methodDepth = $depth;
                        $pendingMethod = null;
                    }
                } elseif ($t === '}') {
                    if ($methodDepth === $depth) {
                        $method = null;
                        $methodDepth = null;
                    }
                    if ($classDepth === $depth) {
                        $class = null;
                        $classDepth = null;
                    }
                    $depth--;
                } elseif ($t === ';' && $pendingMethod !== null && $methodDepth === null) {
                    $pendingMethod = null; // abstraktní metoda bez těla
                }
                continue;
            }
            [$id, $text, $line] = $t;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++; // "{$x}" / "${x}" se zavírá obyčejnou '}'
            } elseif ($id === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            } elseif (in_array($id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true) && $class === null) {
                $prev = self::previousSignificant($tokens, $i);
                if ($prev !== null && is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                $name = self::nextSignificant($tokens, $i);
                if (is_array($name) && $name[0] === T_STRING) {
                    $class = ($namespace !== '' ? $namespace . '\\' : '') . $name[1];
                    $pendingClass = true;
                }
            } elseif ($id === T_FUNCTION && $class !== null && $method === null && $depth === $classDepth) {
                $name = self::nextSignificant($tokens, $i);
                if (is_array($name) && $name[0] === T_STRING) {
                    $pendingMethod = $name[1];
                }
            } elseif (($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) && $class !== null) {
                $name = self::nextSignificant($tokens, $i);
                if (is_array($name) && $name[0] === T_STRING && in_array($name[1], $methods, true)) {
                    $symbol = $class . '::' . ($method ?? '?');
                    $out[$symbol] ??= $label . ':' . $line;
                }
            }
        }
        return $out;
    }

    /** @param list<mixed> $tokens */
    private static function nextSignificant(array $tokens, int $i): mixed
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousSignificant(array $tokens, int $i): mixed
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }
}
