<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PaymentCorrectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentRefundProjectionTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PaymentCorrectionFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function orders(): array
    {
        return ['cancel before reversal' => [true], 'cancel after reversal' => [false]];
    }

    #[DataProvider('orders')]
    public function testRefundReversalPreservesReservationCancellationAndHistoricalBalances(bool $cancelFirst): void
    {
        $base = $this->settledPaymentFixture('1000', '500');
        $payments = $this->correctionPayments();
        $supplier = $this->f['supplier'];
        $actor = $this->f['actor'];
        $credit = $base['operation']['credit_id'];
        $reservation = $payments->reserve($supplier, $credit, 1, ['amount_cents' => '300', 'party_ids' => [$this->f['clients'][1]],
            'disposition' => 'Synthetic partial refund', 'recipient_account' => '1000000005/0100'], 'partial-reserve', $actor);
        $history = [];
        $history[] = $this->capture($credit, '500', '300', '200');
        $request = ['kind' => 'refund', 'source' => ['kind' => 'bank', 'id' => $this->correctionBank('-1.00'), 'expected_version' => 0],
            'amount_cents' => '100', 'accounting_date' => '2026-02-02', 'reservation_id' => $reservation['id'],
            'expected_reservation_version' => 1, 'reason' => 'Synthetic partial refund'];
        $refund = $payments->apply($supplier, $request, $payments->preview($supplier, $request)['preview_hash'], 'partial-refund', $actor);
        $history[] = $this->capture($credit, '400', '200', '200');
        if ($cancelFirst) {
            $payments->cancelReservation($supplier, $reservation['id'], 2, 'Synthetic cancellation', 'cancel', $actor);
            $history[] = $this->capture($credit, '400', '0', '400');
        }
        $payments->reverse($supplier, $refund['id'], 2, '2026-02-03', 'Synthetic refund reversal', 'reverse-refund', $actor);
        $history[] = $this->capture($credit, '500', $cancelFirst ? '0' : '300', $cancelFirst ? '500' : '200');
        if (!$cancelFirst) {
            $q = $this->db->pdo()->prepare('SELECT row_version FROM unit_credit_reservations WHERE supplier_id=? AND id=?');
            $q->execute([$supplier, $reservation['id']]);
            $payments->cancelReservation($supplier, $reservation['id'], (int) $q->fetchColumn(), 'Synthetic cancellation', 'cancel', $actor);
            $history[] = $this->capture($credit, '500', '0', '500');
        }
        foreach ($history as [$knownAt, $expected]) {
            $projection = (new PropertyReceivableProjection($this->db))->at($supplier, $knownAt, $knownAt, [], true);
            $credits = array_column($projection['credits'], null, 'id');
            self::assertArrayHasKey($credit, $credits);
            foreach ($expected as $key => $amount) self::assertSame($amount, $credits[$credit][$key], 'Historical credit disagrees with its actual state at ' . $knownAt);
        }
    }

    private function capture(int $id, string $balance, string $reserved, string $available): array
    {
        $credit = $this->correctionPayments()->credit($this->f['supplier'], $id);
        self::assertSame($balance, $credit['balance_cents']);
        self::assertSame($reserved, $credit['reserved_cents']);
        self::assertSame($available, $credit['available_cents']);
        return [PartyEventRepository::now(), ['gross_credit_cents' => $balance, 'reserved_credit_cents' => $reserved, 'available_credit_cents' => $available]];
    }
}
