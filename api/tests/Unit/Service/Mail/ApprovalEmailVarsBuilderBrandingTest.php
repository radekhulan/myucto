<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: e-mail se žádostí o schválení výkazu odcházel s výchozí hlavičkou
 * MyÚčto.cz místo brandingu dodavatele. Builder vracel jen „patičkovou"
 * podmnožinu sloupců bez `id` a branding polí — a protože `Mailer` doplňuje
 * výchozího dodavatele jen když `supplier` chybí ÚPLNĚ, neúplné pole fallback
 * zablokovalo. Test hlídá, že kontext obsahuje vše, na čem stojí `_layout`
 * (`email_branding_enabled`, `logo_path`, `email_accent_color`) i Mailer (`id`).
 */
final class ApprovalEmailVarsBuilderBrandingTest extends TestCase
{
    private function builderWithSupplier(string $extraSupplierSql = ''): ApprovalEmailVarsBuilder
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE supplier (
                id INTEGER PRIMARY KEY,
                branding_profiles_enabled INTEGER NOT NULL,
                email_branding_enabled INTEGER NOT NULL,
                email_accent_color TEXT NULL,
                work_report_display_options TEXT NULL,
                logo_path TEXT NULL
            )'
        );
        $pdo->exec(
            "INSERT INTO supplier
                (id, branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path)
             VALUES
                (7, 0, 1, '#0A5C3B', 'storage/supplier-logos/sup-7.png')"
        );
        // Prázdná tabulka místo mocku — WorkReportRepository je final, a k výkazu
        // se tenhle test stejně nevyjadřuje; stačí, že dotaz projde a vrátí null.
        $pdo->exec('CREATE TABLE work_reports (id INTEGER PRIMARY KEY, invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        if ($extraSupplierSql !== '') {
            $pdo->exec($extraSupplierSql);
        }

        $connection = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('https://ucto.example.test');

        return new ApprovalEmailVarsBuilder($connection, $config, new WorkReportRepository($connection));
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return [
            'id' => 4242,
            'supplier_id' => 7,
            'varsymbol' => '2607038',
            'supplier_snapshot' => json_encode([
                'id' => 7,
                'company_name' => 'Vzorová firma s.r.o.',
                'display_name' => 'Vzorová firma',
                'street' => 'Testovací 1',
                'city' => 'Praha',
                'zip' => '100 00',
                'country_name_cs' => 'Česko',
                'email' => 'fakturace@example.test',
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public function testApprovalEmailCarriesSupplierBranding(): void
    {
        $vars = $this->builderWithSupplier()->build($this->invoice(), 'tok3n', false, 'cs');

        $supplier = $vars['supplier'];
        self::assertSame('Vzorová firma', $supplier['display_name']);
        // `id` je to, podle čeho Mailer připojuje logo jako CID a vybírá SMTP profil.
        self::assertSame(7, (int) $supplier['id']);
        self::assertTrue($supplier['email_branding_enabled']);
        self::assertSame('#0A5C3B', $supplier['email_accent_color']);
        self::assertSame('storage/supplier-logos/sup-7.png', $supplier['logo_path']);
        self::assertArrayHasKey('accent_soft', $supplier);
    }

    public function testDisabledBrandingStillYieldsCompleteContext(): void
    {
        $builder = $this->builderWithSupplier(
            'UPDATE supplier SET email_branding_enabled = 0, logo_path = NULL WHERE id = 7'
        );
        $supplier = $builder->build($this->invoice(), 'tok3n', false, 'cs')['supplier'];

        // Vypnutý branding NENÍ totéž co chybějící kontext — layout musí dostat
        // rozhodnutí „nebrandovat", ne prázdno, které by shodilo i patičku.
        self::assertFalse($supplier['email_branding_enabled']);
        self::assertSame(7, (int) $supplier['id']);
        self::assertSame('Vzorová firma s.r.o.', $supplier['company_name']);
    }
}
