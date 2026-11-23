<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Support\EntryFormMigrationTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;

/**
 * Převod Money S3 v instalaci s výchozím jednoduchým zápisem: nová firma dostane formu
 * single dřív, než převod zapíše první zápis deníku, a deník Money (řádek = účet MD,
 * účet Dal, částka) přijde po dvojicích přímo ze zdroje. Obraty po účtech, rekonciliace
 * K1 až K4 a řádky deníku (bez čísla dvojice) jsou stejné jako ve složené formě, která
 * se bez nastavení instalace nemění.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class MoneyS3EntryFormImportTest extends MoneyS3ImportTestCase
{
    use EntryFormMigrationTrait;

    private const MAP_KEYS = "SELECT money_key AS entry_key, target_id AS entry_id FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'journal_entry'";

    public function testSingleInstallationImportsMoneyJournalAsSourcePairsWithSameTurnover(): void
    {
        $composite = $this->withDefaultEntryForm(null, fn (ContainerInterface $c, PDO $pdo): array => $this->convert($c, $pdo));
        $single = $this->withDefaultEntryForm('single', fn (ContainerInterface $c, PDO $pdo): array => $this->convert($c, $pdo));

        self::assertSame([], $composite['fingerprint']['history']);
        self::assertSame(0, $composite['fingerprint']['paired'], 'Složená forma bez nastavení instalace dvojice netvoří.');
        self::assertSame(0, $composite['fingerprint']['pair_status']);
        self::assertSame(0, $composite['line_texts']);

        self::assertSame(['single'], $single['fingerprint']['history'], 'Forma single musí být zapsaná dřív, než převod zapíše deník.');
        self::assertGreaterThan(0, $single['fingerprint']['lines']);
        self::assertSame(0, $single['fingerprint']['unpaired'], 'Ve formě single má každý řádek deníku číslo dvojice.');
        self::assertSame(0, $single['fingerprint']['pair_status'], 'Žádný zápis nesmí zůstat složený (ineligible / composite_mn).');
        self::assertGreaterThan(0, $single['line_texts'], 'Dvojice nese text řádku Money.');

        self::assertSame($composite['fingerprint']['turnover'], $single['fingerprint']['turnover'], 'Obraty po účtech musí být stejné jako ve složené formě.');
        self::assertSame($composite['lines'], $single['lines'], 'Převzaté řádky deníku (bez čísla dvojice) musí být stejné jako ve složené formě.');
        self::assertTrue($single['criteria']['total'][ReconciliationCriteria::K1] ?? false, 'K1 obratová předvaha proti deníku Money.');
        self::assertTrue($single['criteria']['total'][ReconciliationCriteria::K2] ?? false, 'K2 vyrovnanost.');
        self::assertSame($composite['criteria'], $single['criteria'],'Rekonciliace K1 až K4 musí vyjít stejně jako ve složené formě.');
        self::assertSame($composite['counts'], $single['counts']);
    }

    public function testRowOnTheSameAccountIsLeftOutOfPairsWithoutChangingBalances(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/same-account.lz', SyntheticAgenda::filesWithSameAccountRow());
        $this->withDefaultEntryForm('single', function (ContainerInterface $c, PDO $pdo): void {
            $supplierId = $this->firm($pdo);
            $backup = Ms3Backup::extract($this->tmp . '/same-account.lz', $this->tmp . '/same-account');
            $protocol = $c->get(MoneyS3Importer::class)->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $messages = array_merge(...array_map(static fn (array $step): array => (array) ($step['messages'] ?? []), $protocol->toArray()['steps']));
            $info = array_values(array_filter($messages, static fn (array $m): bool => ($m['code'] ?? '') === 'same_account_rows'));
            self::assertCount(1, $info, $this->explain($protocol));
            self::assertSame(1, $info[0]['context']['rows']);
            $fingerprint = $this->journalFingerprint($pdo, $supplierId);
            self::assertSame(0, $fingerprint['unpaired']);
            self::assertSame(0, $fingerprint['pair_status']);
            self::assertTrue(ReconciliationCriteria::summarize((array) $protocol->get('reconciliation'))['total'][ReconciliationCriteria::K1] ?? false);
        });
    }

    public function testDryRunLeavesNoEntryFormBehind(): void
    {
        $this->withDefaultEntryForm('single', function (ContainerInterface $c, PDO $pdo): void {
            $supplierId = $this->firm($pdo);
            $protocol = $c->get(MoneyS3Importer::class)->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN, true));
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $history = $pdo->prepare('SELECT COUNT(*) FROM accounting_entry_form_history WHERE supplier_id = ?');
            $history->execute([$supplierId]);
            self::assertSame(0, (int) $history->fetchColumn());
            self::assertFalse($c->get(EntryFormService::class)->hasAnySingle($supplierId));
        });
    }

    /** @return array<string,mixed> */
    private function convert(ContainerInterface $c, PDO $pdo): array
    {
        $supplierId = $this->firm($pdo);
        $protocol = $c->get(MoneyS3Importer::class)->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $texts = $pdo->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND line_text IS NOT NULL');
        $texts->execute([$supplierId]);
        $journal = array_column((array) $protocol->get('journal'), null, 'year');
        return [
            'fingerprint' => $this->journalFingerprint($pdo, $supplierId),
            'lines' => $this->journalLinesByKey($pdo, $supplierId, self::MAP_KEYS),
            'line_texts' => (int) $texts->fetchColumn(),
            'criteria' => ReconciliationCriteria::summarize((array) $protocol->get('reconciliation')),
            'counts' => array_map(static fn (array $y): array => [$y['entries'], $y['lines'], $y['debit'], $y['credit']], $journal),
        ];
    }

    private function firm(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, ?)'
        )->execute([SyntheticAgenda::NAME, $this->czId, SyntheticAgenda::ICO, $this->anyCurrencyId, $this->vatRateId, 'tax_evidence']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }
}
