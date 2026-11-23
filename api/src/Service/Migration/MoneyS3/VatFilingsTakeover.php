<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\TaxSubmissionArchiver;

/**
 * Přiznání DPH a kontrolní hlášení, která Money eviduje jako podaná (`SzDPH`), v převáděných
 * letech daňové evidence: zapíšou se jako podaná mimo MyÚčto, aby je MyÚčto nenabízelo
 * k podání ani nehlásilo jako chybějící. Podání uzamkne doklady období
 * ({@see TaxSubmissionArchiver::markSubmitted()} posouvá `locked_until` na konec podaného
 * období), takže krok běží poslední.
 *
 * Money XML podání v záloze nemá: záznam podání je bez XML a dodatečné přiznání k takovému
 * období MyÚčto nesestaví (chybí původní podání, ze kterého se počítá rozdíl). Money nemá
 * ani datum podání; čas podání je čas převodu, odkaz podání to říká. Za období se zapíše
 * jedno řádné podání; další podání téhož období v Money převod jen ohlásí.
 *
 * Běží s volbou průvodce „Uzavřít historii" ({@see ImportOptions::$closeHistory}).
 */
final class VatFilingsTakeover
{
    public const STEP = 'vat_filings';

    private const REFERENCE = 'Převzato z Money S3 – podáno mimo MyÚčto, datum podání Money neeviduje';

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        if (!$ctx->options->closeHistory) {
            $p->info(self::STEP, 'skipped', 'Podaná přiznání DPH se nezapisují (volba „Uzavřít historii" je vypnutá).');
            $p->finish(self::STEP);
            return;
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_VAT_FILING);
        $filed = $this->db->pdo()->prepare(
            "SELECT 1 FROM tax_submissions WHERE supplier_id = ? AND form_code = ? AND period_year = ?
               AND period_month <=> ? AND period_quarter <=> ? AND status IN ('submitted', 'accepted') LIMIT 1"
        );
        $today = date('Y-m-d');
        $seen = [];
        foreach (TaxRecordsImporter::filings($ctx) as $f) {
            $form = $f['kind'] === 'DPH' ? 'dphdp3' : 'dphkh1';
            $month = $f['type'] === 'M' ? $f['period'] : null;
            $quarter = $f['type'] === 'K' ? $f['period'] : null;
            $key = $form . '|' . $f['year'] . '|' . ($month !== null ? 'm' . $month : 'q' . $quarter);
            // Probíhající období podané být nemůže.
            if ($f['to'] >= $today) {
                continue;
            }
            if (isset($seen[$key])) {
                $p->count(self::STEP, 'additional');
                continue;
            }
            $seen[$key] = true;
            if (isset($existing[$key])) {
                $p->count(self::STEP, 'existing');
                continue;
            }
            $filed->execute([$ctx->supplierId, $form, $f['year'], $month, $quarter]);
            if ($filed->fetchColumn() !== false) {
                $p->count(self::STEP, 'already_filed');
                continue;
            }
            $id = $this->submissions->archive(
                $ctx->supplierId, $form, $f['year'], $month, $quarter, '',
                ['source' => 'money_s3', 'period_from' => $f['from'], 'period_to' => $f['to']],
                'skipped', [], $ctx->userId > 0 ? $ctx->userId : null,
            );
            $this->archiver->markSubmitted($id, $ctx->supplierId, date('Y-m-d H:i:s'), self::REFERENCE, $ctx->userId > 0 ? $ctx->userId : null);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_VAT_FILING, $key, $id, $ctx->runId);
            $p->count(self::STEP, $form);
        }
        if ($seen !== []) {
            $lockedUntil = $this->settings->getLockedUntil($ctx->supplierId);
            if ($lockedUntil !== null) {
                $p->info(self::STEP, 'locked_until', 'Doklady firmy jsou uzamčené do ' . date('j. n. Y', (int) strtotime($lockedUntil))
                    . ' (konec posledního podaného období DPH).', ['locked_until' => $lockedUntil]);
            }
        }
        $p->finish(self::STEP);
    }
}
