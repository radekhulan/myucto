<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Každá cesta, která účtuje přes PostingService (postDocument, dávka předpisů SVJ,
 * prepareDocumentLines, replaceEntryLines), je zapsaná v registru níž: jakou má
 * podobu z pohledu jednoduchého (párového) zápisu a který test dokládá, že ji
 * párovač rozdělí na dvojice bez `unpairable`. Nová cesta bez záznamu, záznam bez
 * cesty nebo odkaz na neexistující test tenhle test shodí.
 *
 * Výjimka se uděluje METODĚ (soubor::metoda::volání), ne souboru.
 *
 * Podoby:
 *  - `one`     jedna dvojice MD/Dal;
 *  - `hub`     jeden řádek proti N (311, 321, 221, 211, 315 …), párovač ho rozdělí;
 *  - `grouped` builder skládá po dvojicích a označí je (PairGroups, pair_group, pair_role);
 *  - `builder` řádky z builderů PostingService (faktury, přijaté faktury, zálohy);
 *  - `user`    řádky zadané uživatelem (ruční zápis, ruční kontace dokladu) – odmítne
 *              je ruční vstup, není-li zápis rozdělitelný;
 *  - `out`     mimo jednoduchý zápis v etapě 1 (neziskovky, rámec 504, převod rámce,
 *              vzorová data, kontrola nanečisto), důvod v `reason`.
 */
final class PostingPairCoverageTest extends TestCase
{
    private const CALL = '/->(postDocument|postPropertyIssuanceBatchDocument|prepareDocumentLines|replaceEntryLines)\(/';

    private const PAIRER = 'Unit\Accounting\Pair\JournalPairerTest';
    private const AUTO = 'Unit\Accounting\Pair\AutomaticEntryPairsTest';
    private const PAIR = 'Integration\Accounting\Pair\\';

    /**
     * @return array<string, array{shape:string, test?:string, reason?:string}>
     */
    private static function registry(): array
    {
        $hub = self::PAIRER . '::testBulkBankPaymentSplitsBankHubPerCounterLineAndKeepsPairLink';
        $one = self::PAIRER . '::testEqualAmountsArePairedInOrderBeforeHubRule';
        $user = 'Unit\Accounting\Pair\JournalPairInvariantTest';
        return [
            'Action/Accounting/Closing/JournalTransferAction.php::transfer::postDocument' => ['shape' => 'one', 'test' => $one],
            'Action/Accounting/JournalAction.php::postDocumentFrom::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testIssuedInvoiceWithTwoVatRatesIsPairedAgainst311'],
            'Repository/Property/Charges/Issuance/IssuanceRepository.php::postLocked::postDocument' => ['shape' => 'grouped', 'test' => self::PAIR . 'PropertyPairsTest::testChargeIssuanceIsPairedPerComponent'],
            'Repository/Property/Charges/Issuance/IssuanceRepository.php::postLocked::postPropertyIssuanceBatchDocument' => ['shape' => 'grouped', 'test' => self::PAIR . 'PropertyPairsTest::testChargeIssuanceIsPairedPerComponent'],
            'Repository/Property/Payments/PaymentRepository.php::apply::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Repository/Property/Payments/ReliefCorrectionRepository.php::completePendingCorrection::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/Activation/DocumentBackfill.php::run::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testPurchaseInvoiceIsPairedAgainst321'],
            'Service/Accounting/Activation/OpeningBalanceService.php::post::postDocument' => ['shape' => 'grouped', 'test' => self::AUTO . '::testOpeningEntryIsPairedByConsecutiveGroups'],
            'Service/Accounting/AdvanceSettlementSync.php::syncFinal::replaceEntryLines' => ['shape' => 'builder', 'test' => self::PAIRER . '::testExistingPairsAreValidatedAndNewPairsAreNumberedAfterThem'],
            'Service/Accounting/Assets/AssetService.php::disposeOnce::postDocument' => ['shape' => 'grouped', 'test' => self::PAIR . 'AssetPairsTest::testAssetLifecycleIsPaired'],
            'Service/Accounting/Assets/AssetService.php::putIntoUseOnce::postDocument' => ['shape' => 'one', 'test' => self::PAIR . 'AssetPairsTest::testAssetLifecycleIsPaired'],
            'Service/Accounting/Assets/DepreciationPostingService.php::postAccountingEntryLocked::postDocument' => ['shape' => 'one', 'test' => self::PAIR . 'AssetPairsTest::testAssetLifecycleIsPaired'],
            'Service/Accounting/Bank/BankPostingService.php::applyDetection::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Bank/BankPostingService.php::approveSuggestion::postDocument' => ['shape' => 'hub', 'test' => self::PAIR . 'BankPairsTest::testBulkPaymentIsOnePairPerInvoice'],
            'Service/Accounting/Bank/BankPostingService.php::matchedOutcome::postDocument' => ['shape' => 'hub', 'test' => self::PAIR . 'BankPairsTest::testBulkPaymentIsOnePairPerInvoice'],
            'Service/Accounting/Bank/BankPostingService.php::postByRule::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Bank/BankPostingService.php::postManual::postDocument' => ['shape' => 'user', 'test' => $user],
            'Service/Accounting/Bank/BankPostingService.php::repostMatchedInPlaceLocked::postDocument' => ['shape' => 'hub', 'test' => self::AUTO . '::testMultiInvoiceFxGainIsPairedThroughTheSupplementaryRole'],
            'Service/Accounting/Bank/TransferPairService.php::postLeg::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Card/CardSettlementService.php::sync::postDocument' => ['shape' => 'hub', 'test' => self::PAIRER . '::testRoundingLineOnHubSideIsPairedNorthWestWithBigLineFirst'],
            'Service/Accounting/Cash/CashDocumentService.php::doBackfillJournal::postDocument' => ['shape' => 'hub', 'test' => self::PAIR . 'CashPairsTest::testCashSaleAndPurchaseArePairedAgainstCashAccount'],
            'Service/Accounting/Cash/CashDocumentService.php::doPost::postDocument' => ['shape' => 'hub', 'test' => self::PAIR . 'CashPairsTest::testCashSaleAndPurchaseArePairedAgainstCashAccount'],
            'Service/Accounting/Closing/ClosingService.php::assert504PreparedLines::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'kontrola nanečisto zápisu rámce 504'],
            'Service/Accounting/Closing/ClosingService.php::closeBooks::postDocument' => ['shape' => 'grouped', 'test' => self::AUTO . '::testClosingEntryIsPairedByConsecutiveGroups'],
            'Service/Accounting/Closing/ClosingService.php::createAssistedEntry::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Closing/ClosingService.php::createLegalReserve504::postDocument' => ['shape' => 'out', 'reason' => 'zákonné rezervy rámce 504 (neziskovky)'],
            'Service/Accounting/Closing/ClosingService.php::openNext::postDocument' => ['shape' => 'grouped', 'test' => self::AUTO . '::testOpeningEntryIsPairedByConsecutiveGroups'],
            'Service/Accounting/Closing/ClosingService.php::runDeferredRevenueAccrual::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/Closing/ClosingService.php::runDeferredTax::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Closing/ClosingService.php::runFxRevaluation::postDocument' => ['shape' => 'grouped', 'test' => self::AUTO . '::testClosingEntryIsPairedByConsecutiveGroups'],
            'Service/Accounting/Closing/ClosingService.php::runIncomeTax::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Closing/ClosingService.php::runLegalProvisions504::postDocument' => ['shape' => 'out', 'reason' => 'zákonné opravné položky rámce 504'],
            'Service/Accounting/Closing/ClosingService.php::runPrepaidExpenseAccrual::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/Closing/ClosingService.php::runProfitDistribution504::postDocument' => ['shape' => 'out', 'reason' => 'rozdělení výsledku rámce 504'],
            'Service/Accounting/Closing/ClosingService.php::runProfitDistribution504::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'rozdělení výsledku rámce 504'],
            'Service/Accounting/Closing/ClosingService.php::runProfitDistribution::postDocument' => ['shape' => 'grouped', 'test' => self::PAIRER . '::testPairGroupKeepsGroupsApartAndIsDroppedFromOutput'],
            'Service/Accounting/Closing/ClosingService.php::runProfitDistribution::prepareDocumentLines' => ['shape' => 'grouped', 'test' => self::PAIRER . '::testPairGroupKeepsGroupsApartAndIsDroppedFromOutput'],
            'Service/Accounting/Closing/ClosingService.php::runProvisions::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/Closing/ClosingService.php::runSmallAssetAccrual::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/Closing/ClosingService.php::runStockValuation::postDocument' => ['shape' => 'grouped', 'test' => self::AUTO . '::testClosingEntryIsPairedByConsecutiveGroups'],
            'Service/Accounting/Closing/SecuritiesDebt504Workflow.php::execute::postDocument' => ['shape' => 'out', 'reason' => 'dluhové cenné papíry rámce 504'],
            'Service/Accounting/Conversion/FrameworkConversionResultDecision.php::confirm::postDocument' => ['shape' => 'out', 'reason' => 'převod účetního rámce 500/504'],
            'Service/Accounting/Conversion/FrameworkConversionResultDecision.php::confirm::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'převod účetního rámce 500/504'],
            'Service/Accounting/CreditCard/CreditCardConversionService.php::repost::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/CreditCard/CreditCardPostingService.php::postOpening::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/DocumentAutoPoster.php::post::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testIssuedInvoiceWithTwoVatRatesIsPairedAgainst311'],
            'Service/Accounting/DocumentJournalSync.php::reconcileForceEdit::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testIssuedInvoiceWithTwoVatRatesIsPairedAgainst311'],
            'Service/Accounting/DocumentJournalSync.php::repostForceEdit::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testPurchaseInvoiceIsPairedAgainst321'],
            'Service/Accounting/DocumentRepostService.php::repostOnce::postDocument' => ['shape' => 'user', 'test' => $user],
            'Service/Accounting/GoPay/GoPayMovementPoster.php::postOnce::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/InvoiceSettlementService.php::create::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/InvoiceSettlementService.php::postRow::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Accounting/ManualJournalEntryService.php::post::postDocument' => ['shape' => 'user', 'test' => $user],
            'Service/Accounting/OffsetService.php::confirm::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/OtherItemService.php::post::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/OtherItemService.php::repost::postDocument' => ['shape' => 'hub', 'test' => $hub],
            'Service/Accounting/Payroll/PayrollPostingService.php::postTransactional::postDocument' => ['shape' => 'grouped', 'test' => self::PAIR . 'PayrollPairsTest::testMonthlyPayrollEntryIsPairedByPostingPairs'],
            'Service/Accounting/PostingService.php::postDocumentOnce::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'brána zaúčtování sama (hook dvojic)'],
            'Service/Accounting/PostingService.php::preflightPropertyIssuanceBatch::prepareDocumentLines' => ['shape' => 'grouped', 'test' => self::PAIR . 'PropertyPairsTest::testChargeIssuanceIsPairedPerComponent'],
            'Service/Accounting/Setup/AccountingHistoryReclassificationService.php::applyReplacement::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testPurchaseInvoiceIsPairedAgainst321'],
            'Service/Accounting/Setup/AccountingHistoryReclassificationService.php::rollback::postDocument' => ['shape' => 'builder', 'test' => self::PAIR . 'InvoicePairsTest::testPurchaseInvoiceIsPairedAgainst321'],
            'Service/Accounting/Vat/VatClearingService.php::postForPeriod::postDocument' => ['shape' => 'grouped', 'test' => self::PAIR . 'ClosingPairsTest::testVatClearingIsTwoPairs'],
            'Service/Ai/AiInternalDocumentValidator.php::validate::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'kontrola návrhu interního dokladu nanečisto'],
            'Service/Nonprofit/Capital/CapitalService.php::plan::prepareDocumentLines' => ['shape' => 'out', 'reason' => 'neziskovky: vlastní kapitál (typovaný zapisovač)'],
            'Service/Nonprofit/Membership/MembershipChargeService.php::confirmIssue::postDocument' => ['shape' => 'out', 'reason' => 'neziskovky: členské příspěvky'],
            'Service/Payroll/Payment/PayrollAccidentInsurancePosting.php::post::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Payroll/Payment/PayrollPaymentPostingService.php::resolve::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Payroll/Posting/PayrollPostingAdapter.php::post::postDocument' => ['shape' => 'grouped', 'test' => 'Unit\Payroll\Posting\PayrollPostingLineBuilderTest::testSingleEntryLinesArePairsWithTheSameTotals'],
            'Service/Payroll/Travel/BusinessTripCashPosting.php::post::postDocument' => ['shape' => 'one', 'test' => $one],
            'Service/Sample/SampleDataGenerator.php::seedBankStatements::postDocument' => ['shape' => 'out', 'reason' => 'vzorová data demo firmy (složená forma)'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function callSites(): array
    {
        $out = [];
        foreach (array_keys(self::scan()) as $key) {
            $out[$key] = [$key];
        }
        return $out;
    }

    #[DataProvider('callSites')]
    public function testPostingPathHasPairCoverage(string $site): void
    {
        $entry = self::registry()[$site] ?? null;
        self::assertNotNull(
            $entry,
            'Nová posting cesta ' . $site . ' nemá záznam v registru dvojic. Doplň tvar a test, který dokládá, '
                . 'že ji JournalPairer v jednoduché formě rozdělí na dvojice (BOD6, kap. 3.3).',
        );
        self::assertContains($entry['shape'], ['one', 'hub', 'grouped', 'builder', 'user', 'out']);
        if ($entry['shape'] === 'out') {
            self::assertNotEmpty($entry['reason'] ?? '', 'Cesta mimo jednoduchý zápis musí mít důvod.');
            return;
        }
        [$class, $method] = explode('::', 'MyInvoice\\Tests\\' . ($entry['test'] ?? '') . '::');
        self::assertTrue(class_exists($class), 'Test ' . $class . ' pro ' . $site . ' neexistuje.');
        if ($method !== '') {
            self::assertTrue(method_exists($class, $method), 'Test ' . $class . '::' . $method . ' pro ' . $site . ' neexistuje.');
        }
    }

    public function testRegistryHasNoStaleEntriesAndCallCountsMatch(): void
    {
        $scan = self::scan();
        self::assertSame([], array_values(array_diff(array_keys(self::registry()), array_keys($scan))), 'Záznam v registru bez posting cesty.');
        // Přibude-li volání do metody, která už v registru je, musí se zkontrolovat i ono.
        $multiple = [
            'Action/Accounting/Closing/JournalTransferAction.php::transfer::postDocument' => 2,
            'Service/Accounting/Closing/ClosingService.php::openNext::postDocument' => 6,
            'Service/Sample/SampleDataGenerator.php::seedBankStatements::postDocument' => 2,
        ];
        foreach ($scan as $site => $count) {
            self::assertSame($multiple[$site] ?? 1, $count, 'Změnil se počet volání v ' . $site . ', zkontroluj tvar nových řádků a registr.');
        }
    }

    /** @return array<string,int> soubor::metoda::volání => počet */
    private static function scan(): array
    {
        $out = [];
        foreach (SourceCorpus::sources(dirname(__DIR__, 2) . '/src') as $relative => $code) {
            if (preg_match_all(self::CALL, $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as $i => [, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $key = $relative . '::' . (PhpSourceRegions::symbolAtLine($code, $line) ?? '?') . '::' . $matches[1][$i][0];
                $out[$key] = ($out[$key] ?? 0) + 1;
            }
        }
        ksort($out);
        return $out;
    }
}
