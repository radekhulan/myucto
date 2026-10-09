<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonLookup;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Osoba z převodu se hledá podle rodného čísla, OIČ a nakonec identity (jméno, příjmení,
 * datum narození). Identita platí jen tam, kde ji rodné číslo nemůže vyvrátit: osoba,
 * kterou dřívější rok převodu založil bez rodného čísla, se nezdvojí. Syntetická data.
 */
#[Group('integration')]
final class PayrollTakeoverPersonLookupTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverPersonLookup $lookup;
    private PayrollPersonCreateService $people;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->lookup = $container->get(PayrollTakeoverPersonLookup::class);
        $this->people = $container->get(PayrollPersonCreateService::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code) VALUES (?, ?, "P")')
            ->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testIdentityFindsPersonCreatedWithoutBirthNumber(): void
    {
        $withoutNumber = $this->person('Eva', 'Zkušební', '1991-06-14', null);

        self::assertSame($withoutNumber, $this->lookup->employeeId($this->supplierId, '9156140004', null, 'Eva', 'Zkušební', '1991-06-14'),
            'Osoba bez rodného čísla se najde podle jména a data narození.');
        self::assertNull($this->lookup->employeeId($this->supplierId, '9156140004', null, 'Eva', 'Zkušební', '1991-06-15'));
    }

    public function testIdentityDoesNotOverrideDifferentBirthNumber(): void
    {
        $this->person('Jan', 'Pokusný', '1985-03-15', '8503150007');

        self::assertNull($this->lookup->employeeId($this->supplierId, '8503150018', null, 'Jan', 'Pokusný', '1985-03-15'),
            'Jiné rodné číslo je jiná osoba, i když se jmenuje stejně.');
    }

    private function person(string $first, string $last, string $birthDate, ?string $birthNumber): int
    {
        $person = $this->people->create($this->supplierId, [
            'full_name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last, 'birth_date' => $birthDate,
            'birth_number' => $birthNumber, 'relation_type' => 'employment', 'planned_start_on' => '2025-01-01',
        ], null, null, null);

        return (int) $person['id'];
    }
}
