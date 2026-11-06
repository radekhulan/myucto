<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository;
use MyInvoice\Tests\Support\{BankProposalFixture, ChargeIssuanceFixture};
use PHPUnit\Framework\TestCase;

/** Filtr dluhů podle jednotky jde po indexu předpisu a vrací jen dluhy dané jednotky. */
final class PaymentDebtUnitFilterTest extends TestCase
{
    use ChargeIssuanceFixture, BankProposalFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testDebtsAreFilteredByTheirUnit(): void
    {
        $case = $this->postedDebts($this->db, $this->f, ['U03', 'U05']);
        $read = new PaymentOperationsReadRepository($this->db);
        foreach (['U03', 'U05'] as $code) {
            $items = $read->page($this->f['supplier'], 'debts', 0, 25, $this->f['units'][$code])['items'];
            self::assertSame([$case['debts'][$code]], array_column($items, 'id'));
        }
        // Zaúčtování dávky registruje dluh každé jednotce; filtr U07 vrací jen její vlastní dluh.
        $other = $read->page($this->f['supplier'], 'debts', 0, 25, $this->f['units']['U07'])['items'];
        self::assertCount(1, $other);
        self::assertSame([$this->f['units']['U07']], array_values(array_unique(array_column($other, 'unit_id'))));
        self::assertNotContains($other[0]['id'], $case['debts']);
    }
}
