<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture, PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;

/**
 * Věřitel kreditu a účet příjemce vratky vycházejí z doložených osob, ne z volného textu:
 * kredit jen pro vlastníky jednotky podle registru nebo s doloženým titulem, vratka jen
 * na účet ručně doložený u oprávněné osoby.
 */
final class PaymentEntitlementBindingTest extends TestCase
{
    use ChargeIssuanceFixture, PaymentCorrectionFixture;

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

    public function testCreditForPersonOutsideRegistryNeedsDocumentedTitle(): void
    {
        $request = $this->credit($this->f['clients'][1]);
        try {
            $this->correctionPayments()->preview($this->f['supplier'], $request);
            self::fail('Credit assigned to a person who is not an owner and has no title.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_credit_party_unproven', $e->errorCode);
            self::assertSame($this->f['clients'][1], $e->details['client_id']);
        }
        $request['credit_entitlement']['third_party'] = ['basis' => 'mistaken_payment', 'evidence' => 'Synthetic payer statement'];
        $party = $this->correctionPayments()->preview($this->f['supplier'], $request)['snapshot']['credit']['parties'][0];
        self::assertSame('third_party:mistaken_payment', $party['basis']);
    }

    public function testRegistryOwnerIsEntitledWithoutExtraTitle(): void
    {
        $owner = $this->f['states']['G12']['ownerships'][0]['members'][0];
        $party = $this->correctionPayments()->preview($this->f['supplier'], $this->credit($owner))['snapshot']['credit']['parties'][0];
        self::assertSame('registry_owner', $party['basis']);
    }

    public function testRefundAccountMustBeDocumentedForEntitledPerson(): void
    {
        $owner = $this->f['states']['G12']['ownerships'][0]['members'][0];
        $request = $this->credit($owner);
        $operation = $this->correctionPayments()->apply($this->f['supplier'], $request, $this->correctionPayments()->preview($this->f['supplier'], $request)['preview_hash'], 'binding-credit', $this->f['actor']);
        $reserve = ['amount_cents' => '1000', 'party_ids' => [$owner], 'disposition' => 'Synthetic refund instruction', 'recipient_account' => '2000000042/0100'];
        $this->db->pdo()->prepare("INSERT INTO client_bank_accounts (supplier_id,client_id,account_number,bank_code,account_key,bank_key,source_bank_statement) VALUES (?,?,'2000000042','0100','2000000042','0100',1)")
            ->execute([$this->f['supplier'], $owner]);
        try {
            $this->correctionPayments()->reserve($this->f['supplier'], $operation['credit_id'], 1, $reserve, 'binding-reserve', $this->f['actor']);
            self::fail('Account learned from a statement released a refund without documented authority.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_refund_recipient_unproven', $e->errorCode);
        }
        $this->db->pdo()->prepare("UPDATE client_bank_accounts SET source_manual=1 WHERE supplier_id=? AND client_id=? AND account_key='2000000042'")->execute([$this->f['supplier'], $owner]);
        $reservation = $this->correctionPayments()->reserve($this->f['supplier'], $operation['credit_id'], 1, $reserve, 'binding-reserve', $this->f['actor']);
        self::assertSame('reserved', $reservation['status']);
    }

    private function credit(int $party): array
    {
        return ['kind' => 'allocation', 'source' => ['kind' => 'bank', 'id' => $this->correctionBank('50.00'), 'expected_version' => 0], 'amount_cents' => '5000',
            'accounting_date' => '2026-02-01', 'reason' => 'Synthetic credit', 'debts' => [],
            'credit_entitlement' => ['unit_id' => $this->f['units']['G12'], 'party_ids' => [$party], 'evidence' => 'Synthetic credit title', 'due_date' => '2026-03-01']];
    }
}
