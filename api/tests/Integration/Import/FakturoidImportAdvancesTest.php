<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use PHPUnit\Framework\Attributes\Group;

/**
 * Zálohový cyklus a vazby dokladů z Fakturoidu: zálohová faktura → daňový doklad
 * k přijaté platbě → konečná faktura s odpočty zdaněných záloh (`paid_advances`),
 * nezdaněná záloha odečtená z částky k úhradě, dobropis navázaný na opravovaný doklad
 * a řádek zaokrouhlení s cenou na tisíciny. Částky musí sedět na rekapitulaci Fakturoidu,
 * doklad se nesmí označit ke kontrole.
 */
#[Group('integration')]
final class FakturoidImportAdvancesTest extends FakturoidImportTestCase
{
    private const CUSTOMER = 790001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(self::CUSTOMER)];
    }

    /**
     * Zálohová faktura s jedinou platbou, ke které Fakturoid vystavil daňový doklad.
     *
     * @return list<array<string,mixed>> [zálohová faktura, daňový doklad]
     */
    private function advance(int $proformaId, int $taxDocId, int $finalId, float $gross, float $base, float $vat, int $rate, bool $grossMode = true): array
    {
        $mode = $grossMode ? 'from_total_with_vat' : 'without_vat';
        $line = ['name' => 'Záloha', 'quantity' => 1, 'unit_price' => (string) ($grossMode ? $gross : $base), 'unit_price_with_vat' => (string) $gross, 'vat_rate' => $rate];
        return [
            $this->document($proformaId, self::CUSTOMER, [$line], [
                'document_type' => 'proforma', 'number' => 'ZF-' . $proformaId, 'variable_symbol' => (string) $proformaId,
                'status' => 'paid', 'paid_on' => '2094-02-10', 'vat_price_mode' => $mode, 'total' => (string) $gross,
                'payments' => [['paid_on' => '2094-02-10', 'amount' => (string) $gross, 'tax_document_id' => $taxDocId]],
                'vat_rates_summary' => [],
            ]),
            $this->document($taxDocId, self::CUSTOMER, [$line], [
                'document_type' => 'tax_document', 'number' => 'DDP-' . $taxDocId, 'variable_symbol' => (string) $proformaId,
                'related_id' => $finalId, 'status' => 'paid', 'paid_on' => '2094-02-10',
                'issued_on' => '2094-02-10', 'taxable_fulfillment_due' => '2094-02-10', 'vat_price_mode' => $mode,
                'total' => (string) $gross, 'remaining_amount' => '0.0',
                'vat_rates_summary' => [['vat_rate' => $rate, 'base' => (string) $base, 'vat' => (string) $vat]],
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function header(int $fakturoidId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.invoice_type, i.varsymbol, i.status, i.prices_include_vat, i.total_without_vat, i.total_vat,
                    i.total_with_vat, i.advance_paid_amount, i.amount_to_pay, i.paid_total, p.fakturoid_id AS parent_fakturoid_id
               FROM invoices i LEFT JOIN invoices p ON p.id = i.parent_invoice_id
              WHERE i.supplier_id = ? AND i.fakturoid_id = ?'
        );
        $stmt->execute([$this->sid, $fakturoidId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Doklad Fakturoid #{$fakturoidId} nevznikl.");
        return $row;
    }

    /** @return array<int,string> Fakturoid ID zálohové faktury → čerpaná částka */
    private function deductions(int $finalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.fakturoid_id, d.amount FROM invoice_advance_deductions d
               JOIN invoices a ON a.id = d.advance_invoice_id
              WHERE d.supplier_id = ? AND d.final_invoice_id = ? ORDER BY a.fakturoid_id'
        );
        $stmt->execute([$this->sid, $finalId]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /**
     * Konečná faktura odečítá dva daňové doklady ze dvou záloh: odpočty jsou řádky
     * dokladu, daň se nezdaní podruhé a obě zálohy jsou čerpané v základu.
     */
    public function testFinalInvoiceDeductsTaxedAdvancesOfTwoProformas(): void
    {
        $this->api['invoices.json'] = [
            $this->document(791100, self::CUSTOMER, [
                ['name' => 'Montáž', 'quantity' => 1, 'unit_price' => '200000.0', 'unit_price_with_vat' => '200000.0', 'vat_rate' => 12],
            ], [
                'document_type' => 'final_invoice', 'number' => 'FA-791100', 'status' => 'paid', 'paid_on' => '2094-03-10',
                'vat_price_mode' => 'from_total_with_vat', 'total' => '50000.0', 'remaining_amount' => '50000.0',
                'vat_rates_summary' => [['vat_rate' => 12, 'base' => '44642.86', 'vat' => '5357.14']],
                'paid_advances' => [
                    ['id' => 791111, 'number' => 'DDP-791111', 'vat_rate' => 12, 'price' => '-53571.43', 'vat' => '-6428.57'],
                    ['id' => 791121, 'number' => 'DDP-791121', 'vat_rate' => 12, 'price' => '-80357.14', 'vat' => '-9642.86'],
                ],
                'payments' => [['paid_on' => '2094-03-10', 'amount' => '50000.0', 'tax_document_id' => null]],
            ]),
            ...$this->advance(791110, 791111, 791100, 60000, 53571.43, 6428.57, 12),
            ...$this->advance(791120, 791121, 791100, 90000, 80357.14, 9642.86, 12),
        ];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $final = $this->header(791100);
        self::assertSame(['invoice', 'FA-791100', 'paid'], [$final['invoice_type'], $final['varsymbol'], $final['status']]);
        self::assertSame(['44642.86', '5357.14', '50000.00', '50000.00'], [
            $final['total_without_vat'], $final['total_vat'], $final['total_with_vat'], $final['amount_to_pay'],
        ]);
        $lines = $this->issued(791100)['items'];
        self::assertCount(3, $lines);
        self::assertSame(['-53571.43', '-6428.57'], [$lines[1]['total_without_vat'], $lines[1]['total_vat']]);
        self::assertNull($final['parent_fakturoid_id'], 'Faktura vyúčtovává dvě zálohy, vazba je čerpáním po dvojicích.');
        self::assertSame([791110 => '53571.43', 791120 => '80357.14'], $this->deductions((int) $final['id']));

        $taxDoc = $this->header(791111);
        self::assertSame(['tax_document', 'DDP-791111', '0.00', 791110], [
            $taxDoc['invoice_type'], $taxDoc['varsymbol'], $taxDoc['amount_to_pay'], (int) $taxDoc['parent_fakturoid_id'],
        ]);
        $payment = $this->db->pdo()->prepare(
            'SELECT p.amount, td.fakturoid_id FROM invoice_payments p JOIN invoices pf ON pf.id = p.invoice_id
               LEFT JOIN invoices td ON td.id = p.tax_document_invoice_id
              WHERE pf.supplier_id = ? AND pf.fakturoid_id = 791110'
        );
        $payment->execute([$this->sid]);
        self::assertSame([['amount' => '60000.00', 'fakturoid_id' => 791111]], array_map(
            static fn (array $r): array => ['amount' => $r['amount'], 'fakturoid_id' => (int) $r['fakturoid_id']],
            $payment->fetchAll(\PDO::FETCH_ASSOC),
        ));
    }

    /**
     * Jediná záloha s přenesenou daňovou povinností: konečná faktura vyjde na nulu
     * a dostane vazbu na zálohovou fakturu jako vyúčtování vystavené v aplikaci.
     */
    public function testReverseChargeFinalInvoiceLinksItsSingleProforma(): void
    {
        $rc = ['transferred_tax_liability' => true];
        [$proforma, $taxDoc] = $this->advance(792110, 792111, 792100, 40000, 40000, 0, 12, false);
        $this->api['invoices.json'] = [
            $this->document(792100, self::CUSTOMER, [
                ['name' => 'Montáž', 'quantity' => 1, 'unit_price' => '40000.0', 'unit_price_with_vat' => '40000.0', 'vat_rate' => 12],
            ], $rc + [
                'document_type' => 'final_invoice', 'status' => 'paid', 'paid_on' => '2094-03-10', 'vat_price_mode' => 'without_vat',
                'total' => '0.0', 'remaining_amount' => '0.0',
                'vat_rates_summary' => [['vat_rate' => 12, 'base' => '0.0', 'vat' => 0]],
                'paid_advances' => [['id' => 792111, 'number' => 'DDP-792111', 'vat_rate' => 12, 'price' => '-40000.0', 'vat' => 0]],
            ]),
            $rc + $taxDoc,
            $rc + $proforma,
        ];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $final = $this->header(792100);
        self::assertSame(['0.00', '0.00', '0.00', 792110], [
            $final['total_without_vat'], $final['total_with_vat'], $final['amount_to_pay'], (int) $final['parent_fakturoid_id'],
        ]);
        self::assertSame([], $this->deductions((int) $final['id']));
    }

    /** Odpočet zálohy v jiné sazbě (0 %) než plnění (21 %) zůstane ve své sazbě. */
    public function testAdvanceDeductionKeepsItsOwnRate(): void
    {
        $this->api['invoices.json'] = [
            $this->document(793100, self::CUSTOMER, [
                ['name' => 'Řízení osvětlení', 'quantity' => 1, 'unit_price' => '10000.0', 'unit_price_with_vat' => '12100.0', 'vat_rate' => 21],
            ], [
                'document_type' => 'final_invoice', 'status' => 'paid', 'paid_on' => '2094-03-10', 'vat_price_mode' => 'without_vat',
                'total' => '6100.0', 'remaining_amount' => '6100.0',
                'vat_rates_summary' => [
                    ['vat_rate' => 21, 'base' => '10000.0', 'vat' => '2100.0'],
                    ['vat_rate' => 0, 'base' => '-6000.0', 'vat' => '0.0'],
                ],
                'paid_advances' => [
                    ['id' => 793111, 'number' => 'DDP-793111', 'vat_rate' => 0, 'price' => '-3000.0', 'vat' => '-0.0'],
                    ['id' => 793121, 'number' => 'DDP-793121', 'vat_rate' => 0, 'price' => '-3000.0', 'vat' => '-0.0'],
                ],
            ]),
            ...$this->advance(793110, 793111, 793100, 3000, 3000, 0, 0),
            ...$this->advance(793120, 793121, 793100, 3000, 3000, 0, 0),
        ];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $final = $this->header(793100);
        self::assertSame(['4000.00', '2100.00', '6100.00', '6100.00'], [
            $final['total_without_vat'], $final['total_vat'], $final['total_with_vat'], $final['paid_total'],
        ]);
        $rates = array_map(static fn (array $l): string => $l['vat_rate_snapshot'], $this->issued(793100)['items']);
        self::assertSame(['21.00', '0.00', '0.00'], $rates);
        self::assertSame([793110 => '3000.00', 793120 => '3000.00'], $this->deductions((int) $final['id']));
    }

    /**
     * Řádek „Zaokrouhlení" za −0,165 Kč: Fakturoid mu dává základ −0,17 a daň −0,03
     * (z ceny s DPH −0,20). Výpočet zdola by dal daň 629,96, doklad musí vyjít na
     * 2 999,83 / 629,97 / 3 629,80.
     */
    public function testRoundingLineWithFractionalPriceKeepsSourceAmounts(): void
    {
        $this->api['invoices.json'] = [
            $this->document(794100, self::CUSTOMER, [
                ['name' => 'Zboží', 'quantity' => 1, 'unit_price' => '1000.0', 'unit_price_with_vat' => '1210.0', 'vat_rate' => 21],
                ['name' => 'Montáž', 'quantity' => 1, 'unit_price' => '2000.0', 'unit_price_with_vat' => '2420.0', 'vat_rate' => 21],
                ['name' => 'Zaokrouhlení', 'quantity' => 1, 'unit_price' => '-0.165', 'unit_price_with_vat' => '-0.2', 'vat_rate' => 21],
            ], [
                'status' => 'paid', 'paid_on' => '2094-03-11', 'vat_price_mode' => 'without_vat', 'total' => '3629.8',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '2999.83', 'vat' => '629.97']],
            ]),
        ];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $doc = $this->header(794100);
        self::assertSame(['2999.83', '629.97', '3629.80', '3629.80'], [
            $doc['total_without_vat'], $doc['total_vat'], $doc['total_with_vat'], $doc['paid_total'],
        ]);
    }

    /**
     * Nezdaněná záloha (bez daňového dokladu) sníží částku k úhradě konečné faktury,
     * úhrada konečné faktury nese jen doplatek. Záloha vyšší než faktura se nahlásí.
     */
    public function testUntaxedAdvanceReducesAmountToPayAndOverpaymentIsReported(): void
    {
        $line = static fn (string $price): array => [['name' => 'Montáž', 'quantity' => 1, 'unit_price' => $price, 'unit_price_with_vat' => $price, 'vat_rate' => 21]];
        $proforma = fn (int $id, int $finalId, string $amount): array => $this->document($id, self::CUSTOMER, $line($amount), [
            'document_type' => 'proforma', 'related_id' => $finalId, 'status' => 'paid', 'paid_on' => '2094-02-10',
            'vat_price_mode' => 'from_total_with_vat', 'total' => $amount, 'vat_rates_summary' => [],
            'payments' => [['paid_on' => '2094-02-10', 'amount' => $amount, 'tax_document_id' => null]],
        ]);
        $final = fn (int $id, int $proformaId, string $total, string $remaining, string $base, string $vat): array => $this->document($id, self::CUSTOMER, $line($total), [
            'document_type' => 'final_invoice', 'related_id' => $proformaId, 'status' => 'paid', 'paid_on' => '2094-03-10',
            'vat_price_mode' => 'from_total_with_vat', 'total' => $total, 'remaining_amount' => $remaining,
            'vat_rates_summary' => [['vat_rate' => 21, 'base' => $base, 'vat' => $vat]],
        ]);
        $this->api['invoices.json'] = [
            $final(795100, 795110, '1210.0', '210.0', '1000.0', '210.0'),
            $proforma(795110, 795100, '1000.0'),
            $final(795200, 795210, '121.0', '-879.0', '100.0', '21.0'),
            $proforma(795210, 795200, '1000.0'),
        ];

        $job = $this->runImport();

        self::assertSame('completed_with_warnings', $job['status'], (string) $job['log_text']);
        $partial = $this->header(795100);
        self::assertSame(['1210.00', '1000.00', '210.00', '210.00', 795110], [
            $partial['total_with_vat'], $partial['advance_paid_amount'], $partial['amount_to_pay'], $partial['paid_total'],
            (int) $partial['parent_fakturoid_id'],
        ]);
        $covered = $this->header(795200);
        self::assertSame(['121.00', '0.00', 795210], [$covered['advance_paid_amount'], $covered['amount_to_pay'], (int) $covered['parent_fakturoid_id']]);
        $problems = $job['report']['problems'];
        self::assertCount(1, $problems);
        self::assertSame([795200, 'review'], [$problems[0]['fakturoid_id'], $problems[0]['severity']]);
        self::assertStringContainsString('879,00', $problems[0]['reason']);
    }

    /** Dobropis dostane vazbu na opravovaný doklad, i když přijde dřív než on. */
    public function testCreditNoteLinksCorrectedInvoice(): void
    {
        $line = static fn (string $price): array => [['name' => 'Stmívač', 'quantity' => 1, 'unit_price' => $price, 'unit_price_with_vat' => $price, 'vat_rate' => 21]];
        $this->api['invoices.json'] = [
            $this->document(796200, self::CUSTOMER, $line('-1000.0'), [
                'document_type' => 'correction', 'correction_id' => 796100, 'number' => 'FA-796200',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '-1000.0', 'vat' => '-210.0']],
            ]),
            $this->document(796100, self::CUSTOMER, $line('1000.0'), [
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '1000.0', 'vat' => '210.0']],
            ]),
        ];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $note = $this->header(796200);
        self::assertSame(['credit_note', '-1210.00', 796100], [$note['invoice_type'], $note['total_with_vat'], (int) $note['parent_fakturoid_id']]);
    }

    /** Zkouška nanečisto prochází stejný výpočet i vazby a nic nezapíše. */
    public function testDryRunAcceptsAdvanceCycleWithoutReview(): void
    {
        $this->api['invoices.json'] = [
            $this->document(797100, self::CUSTOMER, [
                ['name' => 'Montáž', 'quantity' => 1, 'unit_price' => '30000.0', 'unit_price_with_vat' => '30000.0', 'vat_rate' => 12],
            ], [
                'document_type' => 'final_invoice', 'vat_price_mode' => 'from_total_with_vat', 'total' => '0.0', 'remaining_amount' => '0.0',
                'vat_rates_summary' => [['vat_rate' => 12, 'base' => '0.0', 'vat' => '0.0']],
                'paid_advances' => [['id' => 797111, 'number' => 'DDP-797111', 'vat_rate' => 12, 'price' => '-26785.71', 'vat' => '-3214.29']],
            ]),
            ...$this->advance(797110, 797111, 797100, 30000, 26785.71, 3214.29, 12),
        ];

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        self::assertSame([3, 0, 2], [
            $job['report']['agendas']['issued']['created'],
            $job['report']['agendas']['issued']['review'],
            $job['report']['agendas']['issued']['linked'],
        ]);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?');
        $count->execute([$this->sid]);
        self::assertSame(0, (int) $count->fetchColumn());
    }
}
