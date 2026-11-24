<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignPurchaseRepair;

/**
 * Kontrola 11: přijaté doklady v cizí měně se samovyměřením DPH, které převod z POHODY
 * převzal v Kč (služby a zboží od zahraničních dodavatelů).
 *
 * DPH, KH i deník takových dokladů jsou správně, chybí jen měna, kurz a částka v měně:
 * platbu v měně nejde spárovat, kurzový rozdíl ani přecenění ke konci roku nespočítat.
 * Měnu a kurz databáze nezná (POHODA je bere k různým datům i ručně), oprava je proto čte
 * z původního exportu z POHODY ({@see PohodaForeignPurchaseRepair}) a spouští ji správce
 * instalace příkazovou řádkou. Kontrola doklady jen ukáže; uzavřená, zamčená a podaná
 * období a doklady, které oprava nepřevede, označí.
 */
final class ForeignPurchaseInCzkCheck extends SourceScopedCheck
{
    public const KEY = 'foreign_purchase_in_czk';

    public function __construct(
        MigrationSources $sources,
        private readonly PohodaForeignPurchaseRepair $repair,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Přijaté doklady v cizí měně převzaté v Kč';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12611-prijate-doklady-v-cizi-mene-prevzate-v-kc';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        foreach ($this->repair->run($supplierId, null, false) as $r) {
            $state = $r['status'] === 'locked' ? CheckItem::LOCKED : CheckItem::REVIEW;
            $reason = $r['status'] === 'needs_export'
                ? sprintf('Doklad v %s převzatý v Kč kvůli samovyměření DPH. DPH, KH i deník jsou správně. Na měnu a kurz dokladu ho převede oprava s původním exportem z POHODY, postup je v manuálu.', $r['currency'])
                : (string) $r['reason'];
            $items[] = new CheckItem('purchase_invoice', $r['id'], trim($r['document_no'] . ' ' . $r['vendor_invoice_number']), $r['issue_date'],
                $r['total_czk'], $state, $reason, null, $r['currency'], ['outcome' => $r['status']]);
        }
        return new CheckResult($items);
    }
}
