<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;

/**
 * Kontrola 1: uhrazená částka vydaných dokladů bez záznamu platby.
 *
 * Převod nesl část úhrad jen v `invoices.paid_total` (POHODA úhrady minulých let, Money S3
 * zápočty a úhrady bez dokladu úhrady). První změna plateb dokladu ji přepočtem ze součtu
 * plateb smaže a doklad se vrátí mezi neuhrazené. Pravidla výběru i oprava jsou opravy
 * převodu, kontrola je jen zpřístupňuje:
 *  - POHODA: {@see PohodaPriorYearPaymentRepair} (platba k poslednímu dni před začátkem
 *    agendy). Rozdíly u dokladů vystavených v roce agendy úhradou minulých let být nemohou,
 *    jen se ohlásí,
 *  - Money S3: {@see MoneyS3SettledPaymentRepair} přes veřejné metody
 *    {@see MoneyS3SettledPayments}. Oprava Money je jedna pro vydané i přijaté doklady,
 *    proto doplní i krytí přijatých dokladů (kontrola {@see PurchaseSettledUncoveredCheck}).
 *
 * Obě opravy opravují firmu jen celou, takže nález v chráněném období zastaví opravu firmy
 * ({@see MigrationCheckLocks::allOrNothing()}). Deník, DPH ani uhrazená částka se nemění.
 */
final class SettledWithoutPaymentCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'settled_without_payment';

    public function __construct(
        MigrationSources $sources,
        private readonly PohodaPriorYearPaymentRepair $pohoda,
        private readonly MoneyS3SettledPaymentRepair $moneyRepair,
        private readonly MoneyS3SettledPayments $money,
        private readonly MigrationCheckLocks $locks,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Uhrazená částka faktur bez záznamu platby';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1263-uhrady-faktur-bez-zaznamu-platby';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3];
    }

    public function options(): array
    {
        return [];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        $notes = [];
        if ($this->sources->has($supplierId, MigrationSources::POHODA)) {
            [$pohodaItems, $pohodaNotes] = $this->pohodaItems($supplierId, $this->pohoda->run([$supplierId], false)[0]);
            $items = array_merge($items, $pohodaItems);
            $notes = array_merge($notes, $pohodaNotes);
        }
        if ($this->sources->has($supplierId, MigrationSources::MONEY_S3)) {
            $items = array_merge($items, $this->moneyItems($supplierId));
        }
        return MigrationCheckLocks::allOrNothing(new CheckResult($items, $notes));
    }

    /** @param array<string,bool> $options */
    public function preview(int $supplierId, array $options = []): FixPreview
    {
        $result = $this->detect($supplierId);
        $notes = [];
        if ($this->sources->has($supplierId, MigrationSources::MONEY_S3)) {
            $money = $this->moneyRepair->run([$supplierId], false)[0];
            if (($money['purchases'] ?? []) !== []) {
                $notes[] = sprintf('Oprava Money S3 zároveň doplní krytí %d přijatých dokladů uhrazených podle Money (%s), stejně jako kontrola přijatých dokladů.',
                    count($money['purchases']), self::money((float) $money['purchase_amount_czk']));
            }
            if ((int) ($money['tax_document_links'] ?? 0) > 0) {
                $notes[] = sprintf('Naváže %d plateb záloh na DDKP z Money.', (int) $money['tax_document_links']);
            }
        }
        return FixPreview::fromResult($result, $notes);
    }

    /** @param array<string,bool> $options */
    public function fix(int $supplierId, ?int $userId, array $options = []): FixResult
    {
        $result = $this->detect($supplierId);
        $fixable = $result->itemsInState(CheckItem::FIXABLE);
        $skipped = array_values(array_filter($result->items, static fn (CheckItem $i): bool => $i->state !== CheckItem::FIXABLE));
        if ($fixable === []) {
            return new FixResult([], $skipped);
        }
        $notes = [];
        if ($this->sources->has($supplierId, MigrationSources::POHODA) && array_filter($fixable, static fn (CheckItem $i): bool => ($i->details['source'] ?? '') === MigrationSources::POHODA) !== []) {
            $row = $this->pohoda->run([$supplierId], true, $userId)[0];
            $notes[] = sprintf('POHODA: doplněno %d plateb úhrad minulých let (%s).', (int) $row['count'], self::money((float) $row['amount_czk']));
        }
        if ($this->sources->has($supplierId, MigrationSources::MONEY_S3) && array_filter($fixable, static fn (CheckItem $i): bool => ($i->details['source'] ?? '') === MigrationSources::MONEY_S3) !== []) {
            $row = $this->moneyRepair->run([$supplierId], true, $userId)[0];
            $notes[] = sprintf('Money S3: vydané %d dokladů (%s), přijaté %d dokladů (%s), vazby plateb na DDKP %d.',
                (int) $row['issued_count'], self::money((float) $row['issued_amount_czk']),
                (int) $row['purchase_count'], self::money((float) $row['purchase_amount_czk']), (int) $row['tax_document_links']);
        }
        return new FixResult($fixable, $skipped, $notes);
    }

    /**
     * @param array<string,mixed> $row řádek {@see PohodaPriorYearPaymentRepair::run()}
     * @return array{0:list<CheckItem>,1:list<string>}
     */
    private function pohodaItems(int $supplierId, array $row): array
    {
        if ($row['status'] === 'skipped') {
            return [[], ['POHODA: ' . (string) $row['reason']]];
        }
        $items = [];
        $lock = $row['invoices'] !== [] ? $this->locks->reason($supplierId, (string) $row['paid_on']) : null;
        foreach ($row['invoices'] as $i) {
            $items[] = new CheckItem('invoice', (int) $i['id'], (string) $i['document_no'], (string) $i['issue_date'], (float) $i['amount_czk'],
                $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED,
                $lock ?? sprintf('Úhrada minulých let jen v uhrazené částce, doplní se platba k %s.', $row['paid_on']),
                (float) $i['amount'], (string) $i['currency'], ['source' => MigrationSources::POHODA, 'paid_on' => (string) $row['paid_on']]);
        }
        foreach ($row['other_invoices'] ?? [] as $i) {
            $items[] = new CheckItem('invoice', (int) $i['id'], (string) $i['document_no'], (string) $i['issue_date'], (float) $i['amount_czk'], CheckItem::REVIEW,
                'Doklad roku agendy má uhrazenou částku vyšší než platby. Úhradou minulých let to být nemůže, ověřte úhrady dokladu.',
                (float) $i['amount'], (string) $i['currency'], ['source' => MigrationSources::POHODA]);
        }
        return [$items, []];
    }

    /** @return list<CheckItem> */
    private function moneyItems(int $supplierId): array
    {
        $lastEnd = $this->money->lastTransferredPeriodEnd($supplierId);
        if ($lastEnd === null) {
            return [];
        }
        $out = [];
        $rows = [];
        foreach ($this->money->issuedCandidates($supplierId, $lastEnd) as $c) {
            $gap = round($c['paid_total'] - $c['others'] - $c['own_amount'], 2);
            if ($c['in_range'] ? abs($gap) >= 0.01 : $c['own_id'] !== null) {
                $rows[] = $c + ['gap' => $c['in_range'] ? $gap : -$c['own_amount']];
            }
        }
        $pending = $this->money->pendingBankMatches($supplierId, array_column($rows, 'id'));
        // Oprava Money doplní i krytí přijatých dokladů: leží-li některý v chráněném období,
        // neopraví se nic.
        $purchaseLock = null;
        foreach ($rows === [] ? [] : $this->money->purchaseCandidates($supplierId, $lastEnd) as $p) {
            if ($p['amount_to_pay'] - $p['covered'] - $p['outside'] >= 0.01 && ($purchaseLock = $this->locks->reason($supplierId, $p['paid_at'])) !== null) {
                $purchaseLock = sprintf('Oprava Money S3 doplní i přijatý doklad %s, který nejde změnit: %s', $p['document_no'], $purchaseLock);
                break;
            }
        }
        foreach ($rows as $c) {
            $rate = $c['currency'] === 'CZK' ? 1.0 : ($c['rate'] ?? 1.0);
            $details = ['source' => MigrationSources::MONEY_S3, 'paid_on' => $c['paid_at']];
            if (isset($pending[$c['id']])) {
                $state = CheckItem::REVIEW;
                $reason = 'Úhrada je spárovaná s pohybem převedené banky bez evidované platby. Doplní ji opakovaný převod z Money S3.';
            } elseif (($lock = $this->locks->reason($supplierId, $c['paid_at'])) !== null) {
                $state = CheckItem::LOCKED;
                $reason = $lock;
            } elseif ($purchaseLock !== null) {
                $state = CheckItem::REVIEW;
                $reason = $purchaseLock;
            } else {
                $state = CheckItem::FIXABLE;
                $reason = !$c['in_range']
                    ? 'Výplň úhrady po konci převáděných období se zruší, úhradu nese rok vedený v MyÚčtu.'
                    : 'Zápočet nebo úhrada bez dokladu úhrady jen v uhrazené částce, doplní se zápočet nebo platba k datu úhrady.';
            }
            $out[] = new CheckItem('invoice', $c['id'], $c['document_no'], $c['paid_at'], round($c['gap'] * $rate, 2), $state, $reason,
                $c['gap'], $c['currency'], $details);
        }
        return $out;
    }
}
