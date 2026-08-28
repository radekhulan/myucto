<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use PHPUnit\Framework\TestCase;

/**
 * W1/P-01 a P-02 — nešifrovaná identita nesmí unikat legacy cestami.
 *
 * Testy jsou úmyslně vedené nad zdrojovým textem: obě opravy jsou o tom, co se
 * v kódu VŮBEC NESMÍ objevit (sloupec ve výběru, jméno ostré databáze v guardu),
 * a to se nedá ověřit tím, že se zavolá metoda a kouká se na návratovou hodnotu.
 * Nad starou implementací každý z nich selže.
 */
final class PayrollLegacyIdentityExposureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    private static function read(string $relativePath): string
    {
        $contents = file_get_contents(self::root() . '/' . $relativePath);
        self::assertIsString($contents, "Soubor {$relativePath} nelze přečíst.");

        return $contents;
    }

    /**
     * P-01: `Config::load()` mergne i `cfg.local.php`, kde vývojová instance míří
     * na ostrou databázi — kontrola `app.url` tedy sama o sobě neznamená vůbec
     * nic. Bez kontroly jména databáze by `--execute --command approve` schválil
     * mzdový běh v ostrých datech.
     */
    public function testPayrollReviewScriptRefusesNonTestDatabaseBeforeConnecting(): void
    {
        $script = self::read('private/Mzdy/test/run-payroll-review.php');

        $guardPosition = strpos($script, "str_ends_with(\$databaseName, '_test')");
        self::assertIsInt(
            $guardPosition,
            'Skript musí kontrolovat, že běží nad databází s příponou _test.',
        );

        $containerPosition = strpos($script, 'Bootstrap::buildContainer()');
        self::assertIsInt($containerPosition);
        self::assertLessThan(
            $containerPosition,
            $guardPosition,
            'Pojistka musí odmítnout ostrou databázi DŘÍV, než se k ní skript připojí.',
        );
        self::assertStringContainsString(
            "\$config->get('db.name'",
            $script,
            'Jméno databáze se musí brát z téže konfigurace, kterou použije Connection.',
        );
    }

    /** P-02: legacy repository nesmí rodné číslo ani adresu číst. */
    public function testLegacyEmployeeRepositoryNeitherReadsNorWritesPlaintextIdentity(): void
    {
        $repository = self::read('api/src/Repository/PayrollEmployeeRepository.php');

        // V dotazech ani v seznamu zapisovatelných sloupců nesmí být vůbec.
        // Zmínka v komentáři je naopak žádoucí, proto se hledá jen v kódu.
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $repository);
        self::assertIsString($code);

        self::assertStringNotContainsString('birth_number', $code);
        self::assertStringNotContainsString('address', $code);
        self::assertStringContainsString('birth_date', $code);
    }

    /** P-02: legacy akce nesmí rodné číslo ani adresu přijmout k zápisu. */
    public function testLegacyEmployeeActionRejectsPlaintextIdentityPayload(): void
    {
        $action = self::read('api/src/Action/Accounting/PayrollEmployeeAction.php');
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $action);
        self::assertIsString($code);

        self::assertStringNotContainsString(
            "\$fields['birth_number']",
            $code,
            'Rodné číslo se z legacy routy zapisovat nesmí.',
        );
        self::assertStringNotContainsString(
            "'birth_number'        =>",
            $code,
            'Rodné číslo se z legacy routy zakládat nesmí.',
        );
        self::assertStringContainsString('rejectPlaintextIdentity', $code);
        self::assertSame(
            2,
            substr_count($code, '$this->rejectPlaintextIdentity('),
            'Pojistka musí platit pro zakládání i pro částečnou úpravu.',
        );
    }

    /**
     * P-02: migrace sloupce ZÁMĚRNĚ nezahazuje — plaintext v nich je pro staré
     * instalace jediným výskytem rodného čísla a přesealovat ho v SQL nelze.
     */
    public function testMigrationDeprecatesLegacyColumnsWithoutDroppingData(): void
    {
        $sql = self::read('db/migrations/1611_payroll_sensitive_identity_hardening.sql');

        self::assertStringNotContainsString('DROP COLUMN', $sql);
        self::assertStringContainsString('MODIFY COLUMN birth_number', $sql);
        self::assertStringContainsString('MODIFY COLUMN address', $sql);
        self::assertStringContainsString('VYŘAZENO (migrace 1611)', $sql);
    }

    /**
     * P-06: uložené masky se dorovnávají na dvě viditelné číslice a podmínka
     * je taková, že druhý průběh migrace už nic nezmění.
     */
    public function testMigrationNarrowsStoredMasksIdempotently(): void
    {
        $sql = self::read('db/migrations/1611_payroll_sensitive_identity_hardening.sql');

        self::assertStringContainsString('UPDATE payroll_person_identifiers', $sql);
        self::assertStringContainsString('UPDATE payroll_dependants', $sql);
        self::assertSame(
            2,
            substr_count($sql, "- 2, 1) <> '•'"),
            'Obě dorovnání musí mít podmínku, která po prvním běhu přestane platit.',
        );
        self::assertStringNotContainsString('value_ciphertext', $sql);
        self::assertStringNotContainsString('value_hash', $sql);
    }
}
