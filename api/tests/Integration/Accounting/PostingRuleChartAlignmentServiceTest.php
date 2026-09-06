<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingRuleChartAlignmentService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Doplnit podle osnovy" — náhled i zápis.
 *
 * Testuje se na KLONU firmy (`cloneSupplier`), ne na hlavní: sanitizovaný klon
 * produkce má u supplieru 1 desítky vlastních override, takže by test tvrdil
 * něco o cizích datech a na čisté DB by dopadl jinak. Klon má override nula.
 */
#[Group('integration')]
final class PostingRuleChartAlignmentServiceTest extends BankPostingTestCase
{
    private function newSupplier(): int
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        return $supplierId;
    }

    private function service(): PostingRuleChartAlignmentService
    {
        return $this->container->get(PostingRuleChartAlignmentService::class);
    }

    /** @return array<string,mixed> */
    private function previewRule(array $preview, string $ruleKey): array
    {
        foreach ($preview['rules'] as $rule) {
            if ($rule['rule_key'] === $ruleKey) {
                return $rule;
            }
        }
        self::fail("Pravidlo {$ruleKey} v náhledu chybí.");
    }

    private function addAnalytic(int $supplierId, string $parentCode, string $code, string $name): void
    {
        $accounts = $this->container->get(ChartOfAccountsRepository::class);
        $parent = $accounts->findByCode($supplierId, $parentCode);
        self::assertNotNull($parent, "Šablona osnovy nemá {$parentCode}.");
        $accounts->insert($supplierId, [
            'account_code' => $code,
            'name'         => $name,
            'account_type' => (string) $parent['account_type'],
            'normal_side'  => $parent['normal_side'],
            'is_synthetic' => false,
            'parent_id'    => (int) $parent['id'],
            'is_active'    => true,
        ]);
    }

    /**
     * 518 veze v šabloně jedinou analytiku 518.990, a ta je NEDAŇOVÁ — vybírá ji
     * daňový příznak dokladu, ne kontace. Kdyby ji náhled nabízel, tlačil by
     * všechny služby na nedaňový účet.
     */
    public function testNedanovaAnalytikaSeNenabiziANepocitaSeJakoNejednoznacnost(): void
    {
        $supplierId = $this->newSupplier();

        $rule = $this->previewRule($this->service()->preview($supplierId), 'invoice.services.received');

        self::assertSame(PostingRuleChartAlignmentService::STATUS_OK, $rule['status']);
        self::assertSame('518', $rule['debit']['code']);
        self::assertSame([], $rule['debit']['candidates']);
    }

    /**
     * Jediná daňová analytika = jednoznačné, a řeší to už PostingService::singleAnalyticMap()
     * za běhu. Náhled to má hlásit, ale NENAVRHOVAT k zápisu — override navíc by
     * jen přibyl k údržbě a nic nezměnil.
     */
    public function testJedinaDanovaAnalytikaJeAutoBezNavrhu(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '518', '518.100', 'Služby — základní');

        $rule = $this->previewRule($this->service()->preview($supplierId), 'invoice.services.received');

        self::assertSame(PostingRuleChartAlignmentService::STATUS_AUTO, $rule['status']);
        self::assertSame('518', $rule['debit']['code']);
        self::assertSame('518.100', $rule['debit']['effective_code']);
        self::assertNull($rule['debit']['suggested_code']);
    }

    /**
     * Dvě daňové analytiky → přesměr mlčí a rozhodnout musí člověk. Bez deníku
     * nesmí být nic předvybrané: hádat pořadím kódů by z náhledu udělalo tichou
     * automatiku.
     *
     * Pozn.: 501 se k tomu nehodí, i když má v šabloně 501.100 i 501.900 —
     * {@see ChartOfAccountsSeeder} pravidla pro materiál a drobný majetek rovnou
     * přesměruje na 501.900, takže tam žádná nejednoznačnost nezbyde.
     */
    public function testDveDanoveAnalytikyJsouKRozhodnutiBezPredvolby(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '518', '518.100', 'Služby — základní');
        $this->addAnalytic($supplierId, '518', '518.200', 'Služby — IT');

        $rule = $this->previewRule($this->service()->preview($supplierId), 'invoice.services.received');

        self::assertSame(PostingRuleChartAlignmentService::STATUS_SUGGEST, $rule['status']);
        self::assertSame(
            ['518.100', '518.200'],
            array_column($rule['debit']['candidates'], 'account_code'),
        );
        self::assertNull($rule['debit']['suggested_code']);
    }

    /** Předvolba se bere z toho, co firma na účtu opravdu používá v hlavní knize. */
    public function testPredvolbaSeRidiPouzitimVDeniku(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '518', '518.100', 'Služby — základní');
        $this->addAnalytic($supplierId, '518', '518.200', 'Služby — IT');
        $accounts = $this->container->get(ChartOfAccountsRepository::class);
        $map = $accounts->codeToIdMap($supplierId);
        $periodId = $this->container->get(AccountingPeriodRepository::class)
            ->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $journal = $this->container->get(JournalEntryRepository::class);
        $journal->insert([
            'supplier_id' => $supplierId,
            'period_id'   => $periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'ALIGN-1',
            'description' => 'Služby',
            'source_type' => 'manual',
            'source_id'   => 1,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518.200']['id'], 'side' => 'debit', 'amount' => 1000.0],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => 1000.0],
        ]);

        $rule = $this->previewRule($this->service()->preview($supplierId), 'invoice.services.received');

        self::assertSame('518.200', $rule['debit']['suggested_code']);
    }

    /**
     * 221/211/343/345 patří kontextu dokladu (výpis, pokladna, směr daně) — kontace
     * do jejich analytiky mluvit nesmí, jinak by nespárovaný pohyb spadl na náhodný účet.
     */
    public function testKontextoveSyntetikySeNenabizeji(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '221', '221.100', 'Běžný účet');
        $this->addAnalytic($supplierId, '221', '221.200', 'Spořicí účet');

        $rule = $this->previewRule($this->service()->preview($supplierId), 'payment.receivable.bank');

        self::assertSame(PostingRuleChartAlignmentService::STATUS_CONTEXT, $rule['status']);
        self::assertSame([], $rule['debit']['candidates']);
    }

    /** Potvrzená volba se zapíše jako per-tenant override a náhled ji přestane hlásit. */
    public function testApplyZapiseOverrideAZmeniNahled(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '518', '518.100', 'Služby — základní');
        $this->addAnalytic($supplierId, '518', '518.200', 'Služby — IT');

        $result = $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '518.100'],
        ]);

        self::assertSame(['invoice.services.received'], $result['applied']);
        $stored = $this->container->get(PostingRuleRepository::class)
            ->resolve($supplierId, 'invoice.services.received');
        self::assertSame($supplierId, $stored['supplier_id']);
        self::assertSame('518.100', $stored['debit_account_code']);
        // Druhá strana se nesmí ztratit jen proto, že ji uživatel nevybíral.
        self::assertSame('321', $stored['credit_account_code']);

        $rule = $this->previewRule($this->service()->preview($supplierId), 'invoice.services.received');
        self::assertSame(PostingRuleChartAlignmentService::STATUS_OK, $rule['status']);
    }

    /** Opakované potvrzení téhož nic nezapisuje — jinak by každý průchod plodil zápis do auditu. */
    public function testOpakovaneApplyNicNezapise(): void
    {
        $supplierId = $this->newSupplier();
        $this->addAnalytic($supplierId, '518', '518.100', 'Služby — základní');
        $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '518.100'],
        ]);

        $second = $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '518.100'],
        ]);

        self::assertSame([], $second['applied']);
        self::assertSame(['invoice.services.received'], $second['skipped']);
    }

    /**
     * Náhled je nabídka, ne důkaz — klient může poslat cokoli. Účet, který není
     * daňovou analytikou PŮVODNÍHO účtu, musí dávku shodit: 518 → 501 je jiná
     * operace, ne zpřesnění téže.
     */
    public function testApplyOdmitneUcetKteryNeniAnalytikouPuvodnihoUctu(): void
    {
        $supplierId = $this->newSupplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not_an_analytic');
        $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '501.900'],
        ]);
    }

    /** Nedaňovou analytiku nesmí zapsat ani přímé volání — nabízená není a vybrat ji nelze. */
    public function testApplyOdmitneNedanovouAnalytiku(): void
    {
        $supplierId = $this->newSupplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not_an_analytic');
        $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '518.990'],
        ]);
    }

    /** Neznámý účet je chyba, ne tichý přeskok. */
    public function testApplyOdmitneNeznamyUcet(): void
    {
        $supplierId = $this->newSupplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown_account');
        $this->service()->apply($supplierId, [
            ['rule_key' => 'invoice.services.received', 'debit_account_code' => '999.999'],
        ]);
    }
}
