<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tajemství zabezpečeného doručení nesmí uniknout do logu ani do odpovědi API.
 *
 * Je to strážce, ne jen dokumentace: lokátor odkazu a jednorázový kód jsou jediné
 * dvě věci, které chrání cizí výplatní pásku. Jakmile se objeví v aplikačním logu,
 * má k nim přístup kdokoli, kdo čte logy — a to je typicky víc lidí než těch,
 * kdo smějí vidět mzdy. Regrese tímhle směrem je jednořádková a jinak neviditelná,
 * proto je tu test.
 */
final class PayrollSecureDeliverySecretsTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function deliverySources(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $files = [
            'Service/Payroll/Document/Delivery/PayrollSecureDeliveryService.php',
            'Service/Payroll/Document/Delivery/PayrollDocumentAccessService.php',
            'Service/Payroll/Document/Delivery/PayrollDeliveryRecipientResolver.php',
            'Action/Payroll/PublicPayrollDocumentAccessAction.php',
            'Action/Payroll/PayrollDocumentDeliveryAction.php',
            'Repository/Payroll/PayrollDocumentAccessLinkRepository.php',
        ];
        foreach ($files as $file) {
            yield $file => [$root . $file];
        }
    }

    #[DataProvider('deliverySources')]
    public function testNoLogCallCarriesATokenCodeOrEmail(string $path): void
    {
        self::assertFileExists($path);
        $lines = explode("\n", (string) file_get_contents($path));

        foreach ($lines as $number => $line) {
            if (!str_contains($line, 'logger->')
                && !str_contains($line, 'activity->log')
            ) {
                continue;
            }
            // Zajímá jen samotné volání a jeho argumenty na témže řádku; kontext
            // logu se v téhle codebase píše na dalších řádcích jako pole a ten
            // hlídá druhý test níž.
            foreach (['$token', '$code,', '$sessionToken', '$email', '$plaintext'] as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $line,
                    sprintf(
                        'Tajemství %s se loguje na řádku %d v %s.',
                        $secret,
                        $number + 1,
                        basename($path),
                    ),
                );
            }
        }
    }

    #[DataProvider('deliverySources')]
    public function testSecretsNeverAppearInsideLogContextArrays(string $path): void
    {
        $source = (string) file_get_contents($path);

        // Kontext logu se píše jako `['klic' => hodnota]` hned za názvem události.
        // Hledáme v něm proměnné, které nesou tajemství.
        preg_match_all(
            '/(?:logger->\w+|activity->log)\((.*?)\);/s',
            $source,
            $matches,
        );
        // Soubor bez jediného volání loggeru je v pořádku — je to nejlepší možný
        // výsledek, ne chybějící pokrytí.
        self::assertIsArray($matches[1]);
        foreach ($matches[1] as $arguments) {
            foreach (['$token', '$sessionToken', '$email', '$plaintext'] as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $arguments,
                    sprintf('Tajemství %s je v kontextu logu v %s.', $secret, basename($path)),
                );
            }
        }
    }

    /**
     * Účetní API nesmí vracet odkaz ani jeho otisk. Odkaz patří do schránky
     * zaměstnance — kdyby ho vracelo API, stačilo by kompromitované účetní
     * přihlášení ke čtení cizích výplatnic cestou určenou pro ně.
     */
    public function testAccountantApiNeverReturnsTheLinkItself(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Action/Payroll/PayrollDocumentDeliveryAction.php',
        );
        $start = strpos($source, 'private static function publicLink');
        self::assertIsInt($start);
        $projection = substr($source, $start);

        foreach (['token_hash', 'recipient_email_hash', 'lease_token', "'url'", "'token'"] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $projection,
                "Projekce odkazu pro účetní nesmí obsahovat {$forbidden}.",
            );
        }
    }

    /** Lokátor i kód se ukládají výhradně jako otisk, nikdy v plaintextu. */
    public function testSecretsAreOnlyEverPersistedAsHashes(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2)
            . '/src/Service/Payroll/Document/Delivery/PayrollDocumentAccessService.php',
        );

        self::assertStringContainsString("insertCode(", $source);
        self::assertMatchesRegularExpression(
            "/insertCode\(\s*\\\$supplierId,\s*\\\$linkId,\s*hash\('sha256', \\\$code\)/",
            $source,
            'Jednorázový kód se musí ukládat jen jako sha256.',
        );
        self::assertMatchesRegularExpression(
            "/createSession\(\s*\\\$supplierId,\s*\\\$linkId,\s*hash\('sha256', \\\$sessionToken\)/",
            $source,
            'Session token se musí ukládat jen jako sha256.',
        );
    }

    /** Veřejná cesta nesmí obcházet vypínač instance ani release bránu. */
    public function testDispatchRechecksTheChannelSwitchInsideTheWorker(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2)
            . '/src/Service/Payroll/Document/Delivery/PayrollSecureDeliveryService.php',
        );

        $dispatch = substr($source, (int) strpos($source, 'public function dispatchOne'));
        self::assertStringContainsString(
            'isChannelEnabled()',
            $dispatch,
            'Worker musí přepínač instance číst znovu, ne se spoléhat na zařazení do fronty.',
        );
        self::assertStringContainsString(
            'assertDispatchAllowed(',
            $dispatch,
            'Worker musí bránu ověřit znovu těsně před odesláním.',
        );
    }
}
