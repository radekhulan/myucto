<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\CreditCard\CreditCardConversionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\PairedJournalAssertions;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převod běžného účtu na úvěrový účet kreditní karty ve formě single: přeúčtování na
 * místě zachová dvojice i jejich text, mění se jen bankovní účet. Dvojice, jejíž obě
 * nohy by skončily na témže úvěrovém účtu, se odmítne srozumitelně dřív, než se převod
 * zastaví na invariantu dvojic.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class CardPairsTest extends BankPostingTestCase
{
    use PairedJournalAssertions;

    public function testCreditCardConversionKeepsPairsAndText(): void
    {
        $pdo = $this->db->pdo();
        $this->enableSingleEntryForm($pdo, $this->supplierId, self::YEAR . '-01-01');
        [$bankAccountId, $tx] = $this->currentAccountWithCharge('6000000028', -300.00);
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        $entryId = (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id'];
        $pdo->prepare("UPDATE journal_entry_lines SET line_text = 'Čerpání kartou' WHERE supplier_id = ? AND entry_id = ?")
            ->execute([$this->supplierId, $entryId]);
        $before = $this->lines($entryId);

        $result = $this->container->get(CreditCardConversionService::class)->convert($this->supplierId, $bankAccountId, $this->userId, 'csob');

        self::assertSame(1, $result['reposted']);
        $pairs = $this->assertEntryPaired($pdo, $this->supplierId, $entryId, '300.00');
        self::assertSame('518', $pairs[0][0]);
        self::assertStringStartsWith('231', $pairs[0][1]);
        $after = $this->lines($entryId);
        self::assertSame(array_column($before, 'pair_no'), array_column($after, 'pair_no'), 'Čísla dvojic zůstávají.');
        self::assertSame(['Čerpání kartou', 'Čerpání kartou'], array_column($after, 'line_text'), 'Text dvojice převod neztratí.');
    }

    public function testConversionRefusesPairWithBothLegsOnConvertedAccount(): void
    {
        $pdo = $this->db->pdo();
        $this->enableSingleEntryForm($pdo, $this->supplierId, self::YEAR . '-01-01');
        [$bankAccountId, $tx] = $this->currentAccountWithCharge('6000000036', -300.00);
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        $entryId = (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id'];
        // Převzatý zápis převodu mezi analytikou účtu a holou 221: obě nohy patří převáděnému účtu.
        $pdo->prepare(
            "UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id = l.supplier_id AND a.account_code = '221'
                SET l.account_id = a.id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND l.side = 'debit'"
        )->execute([$this->supplierId, $entryId]);

        try {
            $this->container->get(CreditCardConversionService::class)->convert($this->supplierId, $bankAccountId, $this->userId, 'csob');
            self::fail('Dvojice se stejným úvěrovým účtem na obou stranách se musí odmítnout.');
        } catch (PostingException $e) {
            self::assertSame('credit_card_same_account_pair', $e->errorCode);
            self::assertSame($entryId, $e->context['entry_id']);
        }
    }

    public function testCardSettlementOfTwoDocumentsIsPairedAndStable(): void
    {
        $pdo = $this->db->pdo();
        $this->enableSingleEntryForm($pdo, $this->supplierId, self::YEAR . '-01-01');
        $pdo->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, ?, '378') ON DUPLICATE KEY UPDATE enabled = 1, effective_from = VALUES(effective_from)"
        )->execute([$this->supplierId, self::YEAR . '-01-01']);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $pdo->prepare('INSERT INTO payment_cards (supplier_id, label, last4, valid_from, valid_to) VALUES (?, ?, ?, NULL, NULL)')
            ->execute([$this->supplierId, 'Karta dvojic', '4321']);

        $vendor = $this->client('Dodavatel karty dvojic');
        $first = $this->purchaseInvoice('PF-CARD-PAIR-1', $vendor, 500.00);
        $second = $this->purchaseInvoice('PF-CARD-PAIR-2', $vendor, 740.00);
        $this->postPredpis('purchase_invoice', $first, '518', '321', 500.00);
        $this->postPredpis('purchase_invoice', $second, '518', '321', 740.00);
        $tx = $this->transaction($this->statement(), -1240.00, [
            'posted_at' => self::YEAR . '-06-15', 'counterparty_name' => 'OBCHOD TEST', 'description' => 'PK: 000000******4321',
        ]);
        $pdo->prepare('UPDATE bank_transactions SET card_last4 = ? WHERE id = ?')->execute(['4321', $tx]);

        $bank = $this->service->handleTransaction($tx, $this->userId);
        $bankPairs = $this->assertEntryPaired($pdo, $this->supplierId, (int) $bank['entry_id'], '1240.00');
        self::assertStringStartsWith('378.', $bankPairs[0][0]);
        // Přeúčtovaný pohyb s mezičlenem po dílech: vypořádání patří celé částce.
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => $bankPairs[0][0], 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => $bankPairs[0][0], 'side' => 'debit', 'amount' => 240.00],
            ['account_code' => $bankPairs[0][1], 'side' => 'credit', 'amount' => 1240.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted' => true, 'posted_by' => $this->userId]);
        self::assertCount(2, $this->assertEntryPaired($pdo, $this->supplierId, (int) $bank['entry_id'], '1240.00'));
        $clearing = $this->service->liveCardClearingLine($this->supplierId, $tx);
        self::assertNotNull($clearing);
        self::assertEqualsWithDelta(1240.00, $clearing['amount'], 0.001);

        $this->paymentMatch($tx, $first, 500.00);
        $this->paymentMatch($tx, $second, 740.00);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);
        $this->service->handleTransaction($tx, $this->userId);

        $settlement = $this->journal->findBySource($this->supplierId, 'card_settlement', $tx);
        self::assertNotNull($settlement);
        $settlementId = (int) $settlement['id'];
        $pairs = $this->assertEntryPaired($pdo, $this->supplierId, $settlementId, '1240.00');
        self::assertSame([['321', '500.00'], ['321', '740.00']], array_map(static fn (array $p): array => [$p[0], $p[2]], $pairs));
        foreach ($pairs as $pair) {
            self::assertNotSame($pair[0], $pair[1], 'Dvojice vypořádání nemá stejný účet na MD i Dal.');
            self::assertStringStartsWith('378.', $pair[1]);
        }
        $version = (int) $this->journal->find($settlementId, $this->supplierId)['row_version'];

        $this->service->syncCardSettlement($this->supplierId, $tx, $this->userId);
        $again = $this->journal->findBySource($this->supplierId, 'card_settlement', $tx);
        self::assertSame($settlementId, (int) $again['id'], 'Opakovaný běh vypořádání nezaloží znovu.');
        self::assertSame($version, (int) $this->journal->find($settlementId, $this->supplierId)['row_version'], 'Ani ho nepřepíše.');

        $this->service->releaseMatch($this->supplierId, $tx, ['user_id' => $this->userId]);
        $live = $this->journal->findBySource($this->supplierId, 'card_settlement', $tx);
        self::assertTrue($live === null || $live['reversed_by'] !== null, 'Zrušení párování stornuje vypořádání.');
        self::assertEqualsWithDelta(1240.00, $this->service->liveCardClearingLine($this->supplierId, $tx)['amount'], 0.001);
    }

    /** @return array{int,int} id účtu v registru, id pohybu */
    private function currentAccountWithCharge(string $number, float $amount): array
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, '0300', '0300', 'CZK', ?, 'current', 'statement', 1)"
        )->execute([$this->supplierId, 'Účet ' . $number, $number, $number]);
        $bankAccountId = (int) $this->db->pdo()->lastInsertId();
        $tx = $this->transaction($this->statement($number, '0300'), $amount, ['description' => 'Čerpání úvěru platební kartou']);
        return [$bankAccountId, $tx];
    }

    /** @return list<array{pair_no:?string, line_text:?string}> */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT pair_no, line_text FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $stmt->execute([$this->supplierId, $entryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
