<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

/**
 * Zachycení finančních archivů DPPO 504 v kopii ještě před pseudonymizací.
 *
 * Každý archiv se ověří celý (vlastní důkaz výpočtu, XML a vazba na posouzení J6 i právní
 * profil) a jeho snapshot, finální přiznání a všechny kopie téhož XML v daňových podáních
 * se zapíší mezi chráněné řádky. Archivy se čtou po jednom, v paměti zůstává jen počet.
 * Co zachycení nedokáže doložit, kopii zastaví dřív, než se změní jediný text.
 */
final class NonprofitDppoArchiveCloneCapture
{
    private const PAGE = 200;

    public static function capture(Connection $db, CloneProtectedRows $protected): int
    {
        $pdo = $db->pdo();
        if ($pdo->inTransaction()
            || TriggerMetadata::any($pdo)) {
            throw new \LogicException('Zachycení archivu DPPO 504 musí proběhnout před triggery a mimo transakci.');
        }
        $page = $pdo->prepare(
            "SELECT id, JSON_UNQUOTE(JSON_EXTRACT(snapshot_json, '$.podklady.accounting_decree')) AS decree
               FROM income_tax_return_snapshots WHERE id > ? ORDER BY id LIMIT " . self::PAGE,
        );
        $count = 0;
        $last = 0;
        do {
            $page->execute([$last]);
            $rows = $page->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if ($row['decree'] === '504') {
                    self::captureOne($db, $protected, $last);
                    $count++;
                }
            }
        } while (count($rows) === self::PAGE);

        return $count;
    }

    private static function captureOne(Connection $db, CloneProtectedRows $protected, int $snapshotId): void
    {
        $pdo = $db->pdo();
        $statement = $pdo->prepare('SELECT * FROM income_tax_return_snapshots WHERE id = ?');
        $statement->execute([$snapshotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !hash_equals((string) $row['source_sha256'], hash('sha256', (string) $row['source_manifest']))
            || !hash_equals((string) $row['xml_sha256'], hash('sha256', (string) $row['xml_content']))) {
            self::reject($snapshotId, 'otisk podkladů nebo XML nesouhlasí');
        }
        $supplierId = (int) $row['supplier_id'];
        $snapshot = json_decode((string) $row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) $row['source_manifest'], true, 512, JSON_THROW_ON_ERROR);
        try {
            NonprofitDppoStoredProof::verify($snapshot, $manifest, (string) $row['xml_content']);
        } catch (\Throwable $e) {
            self::reject($snapshotId, 'důkaz výpočtu neprošel (' . $e->getMessage() . ')');
        }
        $record = $snapshot['podklady']['nonprofit_calculation'];
        $data = $record['data'];
        $nonprofit = $data['nonprofit'];
        $receipt = $data['j6_receipt'] ?? $nonprofit['j6_receipt'] ?? null;
        if (is_array($receipt) && ($receipt['version'] ?? null) !== 1) {
            self::unsupported($snapshotId, 'archiv odkazuje na stránkované posouzení J6');
        }
        if (($nonprofit['saving_due']['sources'] ?? []) !== []) {
            self::unsupported($snapshotId, 'archiv uplatňuje dorovnání úspory z předchozího období');
        }

        $assessment = (new NonprofitTaxAssessmentRepository($db))->find($supplierId, (int) ($nonprofit['assessment']['id'] ?? 0));
        if (($assessment['storage_version'] ?? null) === 'paged_v2') {
            self::unsupported($snapshotId, 'posouzení J6 je uložené ve stránkovaném formátu');
        }
        if ($assessment['period_id'] !== ($nonprofit['assessment']['period_id'] ?? null)
            || $assessment['period_id'] !== ($data['period']['id'] ?? null)
            || $assessment['input_hash'] !== ($nonprofit['assessment']['input_hash'] ?? null)
            || $assessment['request_hash'] !== ($nonprofit['assessment']['request_hash'] ?? null)
            || NonprofitTaxRules::hash($assessment['result']) !== NonprofitTaxRules::hash($nonprofit['assessment']['result'] ?? [])
            || NonprofitTaxRules::hash($assessment['snapshot']['legal_profile'] ?? []) !== NonprofitTaxRules::hash($nonprofit['legal_profile'] ?? [])) {
            self::reject($snapshotId, 'vazba na potvrzené posouzení J6 nebo právní profil nesouhlasí');
        }
        $ledgerHash = $data['source_manifest']['net_turnover']['ledger_hash'] ?? null;
        if (!is_array($assessment['snapshot']['ledger'] ?? null) || $ledgerHash !== NonprofitTaxRules::hash($assessment['snapshot']['ledger'])) {
            self::reject($snapshotId, 'otisk účetních podkladů posouzení nesouhlasí');
        }

        $return = $pdo->prepare('SELECT id, supplier_id, year, taxpayer_type, final_snapshot_id, computed FROM income_tax_returns WHERE id = ?');
        $return->execute([(int) $row['tax_return_id']]);
        $taxReturn = $return->fetch(PDO::FETCH_ASSOC);
        $fiscalYear = (int) ($data['period']['fiscal_year'] ?? 0);
        if (!is_array($taxReturn) || (int) $taxReturn['supplier_id'] !== $supplierId
            || (int) $taxReturn['year'] !== $fiscalYear || $taxReturn['taxpayer_type'] !== 'po') {
            self::reject($snapshotId, 'finální přiznání patří jiné firmě nebo jinému období');
        }
        $protected->protectMany(FinancialArchiveProtectedTable::SNAPSHOT, [$snapshotId]);
        if ((int) $taxReturn['final_snapshot_id'] === $snapshotId) {
            $computed = json_decode((string) $taxReturn['computed'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($computed) || NonprofitTaxRules::canonicalJson($computed) !== NonprofitTaxRules::canonicalJson($snapshot)) {
                self::reject($snapshotId, 'uložený výpočet finálního přiznání se liší od snapshotu');
            }
            $protected->protectMany(FinancialArchiveProtectedTable::TAX_RETURN, [(int) $taxReturn['id']]);
        }

        $copies = $pdo->prepare(
            "SELECT id, xml_content FROM tax_submissions
              WHERE supplier_id = ? AND form_code = 'dppdp9' AND period_year = ? AND xml_sha256 = ? ORDER BY id",
        );
        $copies->execute([$supplierId, $fiscalYear, $row['xml_sha256']]);
        $ids = [];
        while (($copy = $copies->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ((string) $copy['xml_content'] !== (string) $row['xml_content']) {
                self::reject($snapshotId, 'kopie XML v daňovém podání se liší od archivu');
            }
            $ids[] = (int) $copy['id'];
        }
        if ($ids !== []) {
            $protected->protectMany(FinancialArchiveProtectedTable::SUBMISSION, $ids);
        }
    }

    private static function reject(int $snapshotId, string $reason): never
    {
        throw new \RuntimeException("Finanční archiv DPPO 504 (snapshot {$snapshotId}) nelze anonymizovat: {$reason}. Zdrojová databáze zůstala zachovaná.");
    }

    private static function unsupported(int $snapshotId, string $reason): never
    {
        throw new \RuntimeException("Anonymizace finančního archivu DPPO 504 není pro snapshot {$snapshotId} podporována: {$reason}. Zdrojová databáze zůstala zachovaná.");
    }
}
