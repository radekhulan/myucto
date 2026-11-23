<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Účty ručního zaúčtování bankovního pohybu (postManual, dvojice MD/D).
 *
 * Dvě věci, které dvojice MD/D dělala jinak než ruční rozúčtování na víc řádků,
 * ačkoli z obou vzniká TÝŽ zápis:
 *
 *  1. saldokonto na protiúčtu odmítala (blacklist H2, který míří na automatiku) —
 *     kvůli tomu z našeptávače zmizelo celé 311/321/…, takže firma převedená na
 *     analytiky nedostala po napsání „311" ani syntetiku, ani 311.100;
 *  2. bankovní nohu neposílala na analytiku vlastního účtu výpisu (#35), takže
 *     holé „221" z modalu skončilo na syntetice — na rozdíl od automatiky
 *     i schvalování návrhu, které analytiku dosazují.
 */
#[Group('integration')]
final class BankManualPostAccountsTest extends BankPostingTestCase
{
    private const SUFFIX = '981';

    /** Přiřadí vlastnímu účtu výpisu analytiku 221.981 (idempotentně). */
    private function ownAccountWithAnalytic(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ? LIMIT 1'
        );
        $stmt->execute([$this->supplierId, self::ACCOUNT]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE supplier_bank_accounts SET analytic_suffix = ? WHERE id = ?')
                ->execute([self::SUFFIX, $id]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, analytic_suffix, source, is_active)
             VALUES (?, "Test CZK", ?, ?, ?, "CZK", ?, "current", ?, "manual", 1)'
        )->execute([
            $this->supplierId, self::ACCOUNT, self::BANK_CODE, self::BANK_CODE, self::ACCOUNT, self::SUFFIX,
        ]);
    }

    /** Analytika saldokonta, jakou vede firma po převodu osnovy na analytické účty. */
    private function receivableAnalytic(): string
    {
        if ($this->accounts->findByCode($this->supplierId, '311.100') === null) {
            $parent = $this->accounts->findByCode($this->supplierId, '311');
            self::assertNotNull($parent, 'Osnova musí mít syntetické 311.');
            $this->accounts->insert($this->supplierId, [
                'account_code' => '311.100',
                'name'         => 'Pohledávky z obchodních vztahů',
                'account_type' => (string) $parent['account_type'],
                'normal_side'  => (string) $parent['normal_side'],
                'is_synthetic' => false,
                'parent_id'    => (int) $parent['id'],
                'is_active'    => true,
            ]);
        }
        return '311.100';
    }

    public function testManualPairAcceptsSaldoAnalyticOnCounterSide(): void
    {
        $code = $this->receivableAnalytic();
        $tx = $this->transaction($this->statement(), 1000.00);

        $res = $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '221', 'credit_account_code' => $code,
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertArrayHasKey($code, $lines, 'Analytika saldokonta je legitimní protiúčet ručního zaúčtování.');
        self::assertSame(1000.00, $lines[$code]['credit']);
        self::assertSame(1000.00, $lines['221']['debit']);
    }

    public function testManualPairAcceptsSyntheticSaldoToo(): void
    {
        // Firma bez analytického členění osnovy nesmí fixem přijít o svoji syntetiku.
        $tx = $this->transaction($this->statement(), -1000.00);

        $res = $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '321', 'credit_account_code' => '221',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertSame(1000.00, $lines['321']['debit']);
        self::assertSame(1000.00, $lines['221']['credit']);
    }

    public function testManualPairRoutesBareBankCodeToOwnAccountAnalytic(): void
    {
        $this->ownAccountWithAnalytic();
        $tx = $this->transaction($this->statement(), -1500.00);

        $res = $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());

        $entry = $this->journal->find((int) $res['entry_id'], $this->supplierId);
        $codes = array_map(fn (array $l): string => $this->accountCode((int) $l['account_id']), $entry['lines']);
        self::assertContains('221.' . self::SUFFIX, $codes, 'Bankovní noha patří na analytiku vlastního účtu (#35).');
        self::assertNotContains('221', $codes, 'Na ploché syntetice 221 nesmí zůstat nic.');
    }

    public function testManualSplitRoutesBareBankCodeToOwnAccountAnalytic(): void
    {
        $this->ownAccountWithAnalytic();
        $tx = $this->transaction($this->statement(), -1500.00);

        $res = $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '518', 'side' => 'debit',  'amount' => 1500.00],
                ['account_code' => '221', 'side' => 'credit', 'amount' => 1500.00],
            ],
        ], $this->meta());

        $entry = $this->journal->find((int) $res['entry_id'], $this->supplierId);
        $codes = array_map(fn (array $l): string => $this->accountCode((int) $l['account_id']), $entry['lines']);
        self::assertContains('221.' . self::SUFFIX, $codes);
    }

    public function testLearnedRuleHintKeepsGenericBankLeg(): void
    {
        // Kontace pro pravidlo se čte ze zaúčtované historie, kde na bankovní noze leží
        // analytika účtu výpisu. Do pravidla ale patří holé '221' — jinak by se pravidlo
        // přibilo k jednomu bankovnímu účtu a platba na jiném skončila na cizí analytice.
        $this->ownAccountWithAnalytic();
        $stmt = $this->statement();
        $over = ['counterparty_account' => '77661', 'variable_symbol' => '7761'];

        $this->service->postManual($this->supplierId, $this->transaction($stmt, -2000.00, $over), [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());
        $res = $this->service->postManual($this->supplierId, $this->transaction($stmt, -2010.00, $over), [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());

        self::assertNotNull($res['rule_hint']);
        self::assertSame('518', $res['rule_hint']['prefill']['debit_account_code']);
        self::assertSame('221', $res['rule_hint']['prefill']['credit_account_code']);
    }

    public function testManualPairStillRequiresBankOnBankSide(): void
    {
        // R6 guard zůstává: příchozí platba musí mít 221* na MD.
        $tx = $this->transaction($this->statement(), 1000.00);

        try {
            $this->service->postManual($this->supplierId, $tx, [
                'debit_account_code' => '518', 'credit_account_code' => '311',
            ], $this->meta());
            self::fail('Nebankovní účet na bankovní straně nesmí projít.');
        } catch (PostingException $e) {
            self::assertSame('rule_bank_side_required', $e->errorCode);
        }
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    /**
     * BEZ OPRAVY PADÁ: stejný účet na MD i Dal (zadaný přímo i po doplnění analytiky
     * banky z holého „221") je nesmyslný zápis a odmítne se validační chybou.
     */
    public function testManualPairWithSameAccountOnBothSidesIsRejected(): void
    {
        $this->ownAccountWithAnalytic();
        $bank = '221.' . self::SUFFIX;
        foreach ([[$bank, $bank], ['221', $bank]] as [$debit, $credit]) {
            $tx = $this->transaction($this->statement(), 1000.00);
            try {
                $this->service->postManual($this->supplierId, $tx, [
                    'debit_account_code' => $debit, 'credit_account_code' => $credit,
                ], $this->meta());
                self::fail('Stejný účet MD i Dal nesmí projít (' . $debit . '/' . $credit . ').');
            } catch (PostingException $e) {
                self::assertSame('same_account', $e->errorCode);
                self::assertSame(422, $e->httpStatus);
            }
            self::assertSame(0, $this->entryCountForTx($tx));
        }
    }

    public function testRuleFromManualSaldoPostingStillRejected(): void
    {
        // Uvolnění saldokonta platí pro JEDNORÁZOVÝ zápis, ne pro pravidlo (H2).
        $code = $this->receivableAnalytic();
        $tx = $this->transaction($this->statement(), 1000.00, ['counterparty_account' => '77650']);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/párují/');
        $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '221', 'credit_account_code' => $code,
            'create_rule' => [
                'name' => 'Nesmysl', 'direction' => 'incoming', 'counterparty_account' => '77650',
                'debit_account_code' => '221', 'credit_account_code' => $code, 'mode' => 'suggest',
            ],
        ], $this->meta());
    }
}
