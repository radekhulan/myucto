<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoArchiveBuilder;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAssessmentSource;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoClassificationBridge;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoComputation;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoJ6Receipt;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStatementRows;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use PDO;

/**
 * Typový přepis zachycených archivů DPPO 504 v anonymizované kopii.
 *
 * Archiv se nepřepočítává z živých podkladů. Zmrazený záznam se pseudonymizuje po
 * částech (identita, volný text) a převáže na totéž posouzení J6 a tentýž právní profil,
 * které kopie už přepsala; částky, období, buňky výkazů ani příloha E se nemění.
 * Důkaz se pak zapečetí znovu přes {@see NonprofitDppoStoredProof::rebaseHistorical()},
 * XML se sestaví jednou přes {@see NonprofitDppoArchiveBuilder} a každá kopie téhož XML
 * v podáních se vymění jen tehdy, když bajtově odpovídá původnímu archivu.
 */
final class NonprofitDppoArchiveCloneAdapter
{
    private const PAGE = 100;

    /** Věty XML s identitou poplatníka: porovnává se jen sada atributů, ne hodnoty. */
    private const IDENTITY_SENTENCES = ['VetaP', 'VetaUZ', 'VetaNP'];

    private const KEEP_DATA_KEYS = [
        'accounting_decree', 'period', 'vh', 'non_deductible_costs', 'depreciation', 'depreciation_by_group',
        'disposal_nondeductible_residual', 'disposal_tax_increase', 'disposal_tax_decrease', 'disposal_decrease_groups',
        'disposals', 'securities_cost_excess', 'securities_sale', 'closing_projection', 'net_turnover_annual_kc', 'loss_appendix',
    ];

    private const MANIFEST_KEYS = [
        'accounting_decree', 'period', 'legal_profile', 'assessment_id', 'input_hash', 'request_hash', 'j6_receipt_hash',
        'nonprofit_proof_hash', 'saving_due', 'adjustments', 'statements_hash', 'net_turnover', 'loss_appendix_hash',
    ];

    /** @var array<string, array{filing_proof_hash:string, snapshot_id:int}> */
    private array $rebuiltXml = [];

    /** @var list<array{supplier_id:int, tax_submission_id:int, source_xml_sha256:string, target_xml_sha256:string}> */
    private array $submissions = [];

    private ?NonprofitDppoArchiveBuilder $builder = null;

    private ?NonprofitDppoAssessmentSource $assessments = null;

    public function __construct(
        private readonly Connection $db,
        private readonly TextScrubber $scrubber,
        private readonly CloneProtectedRows $protected,
    ) {}

    /**
     * @return list<array{supplier_id:int, tax_submission_id:int, source_xml_sha256:string, target_xml_sha256:string}>
     */
    public function run(): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()
            || TriggerMetadata::any($pdo)) {
            throw new \LogicException('Přepis archivu DPPO 504 musí proběhnout před triggery a mimo transakci.');
        }
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $this->builder = $container->get(NonprofitDppoArchiveBuilder::class);
        $this->assessments = $container->get(NonprofitDppoAssessmentSource::class);
        $last = 0;
        do {
            $ids = $this->protected->idsAfter(FinancialArchiveProtectedTable::SNAPSHOT, $last, self::PAGE);
            foreach ($ids as $id) {
                $this->rewriteOne($id);
                $last = $id;
            }
        } while (count($ids) === self::PAGE);
        $this->assertSubmissionsRewritten();
        $this->revalidate();

        return $this->submissions;
    }

    /**
     * Po přepisu se každý archiv znovu načte z kopie a ověří stejně, jako ho čte aplikace:
     * otisky sloupců, důkaz výpočtu s XML, uložený výpočet finálního přiznání a kopie XML.
     */
    private function revalidate(): void
    {
        $pdo = $this->db->pdo();
        $snapshot = $pdo->prepare('SELECT * FROM income_tax_return_snapshots WHERE id = ?');
        $return = $pdo->prepare('SELECT id, final_snapshot_id, computed FROM income_tax_returns WHERE supplier_id = ? AND id = ?');
        $copies = $pdo->prepare("SELECT xml_content, xml_sha256 FROM tax_submissions WHERE supplier_id = ? AND form_code = 'dppdp9' AND period_year = ? AND xml_sha256 = ?");
        $last = 0;
        do {
            $ids = $this->protected->idsAfter(FinancialArchiveProtectedTable::SNAPSHOT, $last, self::PAGE);
            foreach ($ids as $id) {
                $last = $id;
                $snapshot->execute([$id]);
                $row = $snapshot->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)
                    || !hash_equals((string) $row['source_sha256'], hash('sha256', (string) $row['source_manifest']))
                    || !hash_equals((string) $row['xml_sha256'], hash('sha256', (string) $row['xml_content']))) {
                    throw new \LogicException("Přepsaný archiv DPPO 504 (snapshot {$id}) nemá shodné otisky.");
                }
                $decoded = json_decode((string) $row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
                $proof = NonprofitDppoStoredProof::verify($decoded, json_decode((string) $row['source_manifest'], true, 512, JSON_THROW_ON_ERROR), (string) $row['xml_content']);
                $return->execute([(int) $row['supplier_id'], (int) $row['tax_return_id']]);
                $taxReturn = $return->fetch(PDO::FETCH_ASSOC);
                if (is_array($taxReturn) && (int) $taxReturn['final_snapshot_id'] === $id
                    && NonprofitTaxRules::canonicalJson(json_decode((string) $taxReturn['computed'], true, 512, JSON_THROW_ON_ERROR)) !== NonprofitTaxRules::canonicalJson($decoded)) {
                    throw new \LogicException("Finální přiznání archivu DPPO 504 (snapshot {$id}) nese jiný výpočet než přepsaný snapshot.");
                }
                $copies->execute([(int) $row['supplier_id'], (int) $proof['period']['fiscal_year'], (string) $row['xml_sha256']]);
                foreach ($copies->fetchAll(PDO::FETCH_ASSOC) as $copy) {
                    if ((string) $copy['xml_content'] !== (string) $row['xml_content']) {
                        throw new \LogicException("Kopie přepsaného XML archivu DPPO 504 (snapshot {$id}) se liší.");
                    }
                }
            }
        } while (count($ids) === self::PAGE);
    }

    private function rewriteOne(int $snapshotId): void
    {
        $pdo = $this->db->pdo();
        $supplier = $pdo->prepare('SELECT supplier_id FROM income_tax_return_snapshots WHERE id = ?');
        $supplier->execute([$snapshotId]);
        $supplierId = (int) $supplier->fetchColumn();
        $guard = new DimensionPeriodGuard($this->db);
        $guard->transaction(function () use ($guard, $pdo, $snapshotId, $supplierId): void {
            $guard->lockPeriods($supplierId);
            $statement = $pdo->prepare('SELECT * FROM income_tax_return_snapshots WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $statement->execute([$supplierId, $snapshotId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \LogicException('Chráněný archiv DPPO 504 v kopii chybí.');
            }
            $oldXml = (string) $row['xml_content'];
            $snapshot = json_decode((string) $row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) $row['source_manifest'], true, 512, JSON_THROW_ON_ERROR);
            $record = $snapshot['podklady']['nonprofit_calculation'];
            $data = $record['data'];
            $expectedManifest = $data['source_manifest'] + [
                'nonprofit_filing_proof_hash' => $record['filing_proof_hash'],
                'nonprofit_data_manifest_hash' => NonprofitTaxRules::hash($data['source_manifest']),
            ];
            if (NonprofitTaxRules::canonicalJson($manifest) !== NonprofitTaxRules::canonicalJson($expectedManifest)) {
                throw new \LogicException("Archiv DPPO 504 (snapshot {$snapshotId}) má podklady mimo zmrazený záznam.");
            }

            $rebased = $this->rebaseRecord($supplierId, $record);
            $constants = NonprofitDppoStoredProof::constantsForRecord($rebased);
            $computed = array_replace(
                $this->text(is_array($snapshot['computed'] ?? null) ? $snapshot['computed'] : []),
                (new DppoReturnCalculator())->compute($rebased['data'], $rebased['inputs'], $constants),
            );
            $newManifest = $rebased['data']['source_manifest'] + [
                'nonprofit_filing_proof_hash' => $rebased['filing_proof_hash'],
                'nonprofit_data_manifest_hash' => NonprofitTaxRules::hash($rebased['data']['source_manifest']),
            ];
            $podklady = $this->text($snapshot['podklady']);
            foreach (['accounting_decree', 'period'] as $key) {
                $podklady[$key] = $snapshot['podklady'][$key];
            }
            // Kopie částí záznamu v podkladech se přepíší jen tam, kde je snapshot měl.
            $copies = ['nonprofit' => $rebased['data']['nonprofit'], 'statements' => $rebased['data']['statements'], 'source_manifest' => $newManifest];
            foreach ($copies as $key => $value) {
                if (array_key_exists($key, $snapshot['podklady'])) {
                    $podklady[$key] = $value;
                }
            }
            $podklady['nonprofit_calculation'] = $rebased;
            $rest = $snapshot;
            unset($rest['podklady'], $rest['computed']);
            $newSnapshot = $this->text($rest) + ['computed' => $computed, 'podklady' => $podklady];
            foreach (['finalized_year', 'variant', 'variant_seq'] as $key) {
                if (array_key_exists($key, $snapshot)) {
                    $newSnapshot[$key] = $snapshot[$key];
                }
            }

            $xml = $this->xml($supplierId, $snapshotId, (string) $row['xml_sha256'], $oldXml, $rebased, $computed);
            NonprofitDppoStoredProof::verify($newSnapshot, $newManifest, $xml);
            if (self::xmlProjection($oldXml) !== self::xmlProjection($xml)) {
                throw new \LogicException("Přepsané XML archivu DPPO 504 (snapshot {$snapshotId}) se liší i mimo identitu poplatníka.");
            }

            $snapshotJson = self::encode($newSnapshot);
            $manifestJson = self::encode($newManifest);
            $pdo->prepare('UPDATE income_tax_return_snapshots SET snapshot_json = ?, source_manifest = ?, source_sha256 = ?, xml_content = ?, xml_sha256 = ?, business_errors = ?
                            WHERE supplier_id = ? AND id = ?')
                ->execute([
                    $snapshotJson, $manifestJson, hash('sha256', $manifestJson), $xml, hash('sha256', $xml),
                    $row['business_errors'] === null ? null : $this->scrubber->scrubJson((string) $row['business_errors']),
                    $supplierId, $snapshotId,
                ]);
            $this->rewriteReturn($supplierId, (int) $row['tax_return_id'], $snapshotId, $snapshotJson);
            $this->rewriteSubmissions($supplierId, (int) $rebased['data']['period']['fiscal_year'], (string) $row['xml_sha256'], $oldXml, $xml);
        });
    }

    /**
     * Zmrazený záznam převázaný na přepsané posouzení J6 a právní profil kopie.
     */
    private function rebaseRecord(int $supplierId, array $record): array
    {
        $data = $record['data'];
        $nonprofit = $data['nonprofit'];
        $assessmentId = (int) $nonprofit['assessment']['id'];
        $assessment = $this->assessments?->historicalView($supplierId, $assessmentId)
            ?? throw new \LogicException('Čtení posouzení J6 není připravené.');
        $legal = $assessment['snapshot']['legal_profile'] ?? null;
        $oldLegal = $nonprofit['legal_profile'];
        if (!is_array($legal) || ($legal['id'] ?? null) !== ($oldLegal['id'] ?? null)
            || ($legal['revision_no'] ?? null) !== ($oldLegal['revision_no'] ?? null)
            || ($legal['profile']['kind'] ?? null) !== ($oldLegal['profile']['kind'] ?? null)) {
            throw new \LogicException("Archiv DPPO 504 neodpovídá přepsanému právnímu profilu (posouzení {$assessmentId}).");
        }
        $assessmentRef = [
            'id' => $assessment['id'],
            'period_id' => $assessment['period_id'],
            'input_hash' => $assessment['input_hash'],
            'request_hash' => $assessment['request_hash'],
            'result' => $assessment['result'],
        ];
        if (array_diff_key($nonprofit['assessment'], $assessmentRef) !== []) {
            throw new \LogicException('Archiv DPPO 504 nese neznámá pole posouzení J6.');
        }
        $receipt = null;
        if (array_key_exists('j6_receipt', $nonprofit) || array_key_exists('j6_receipt', $data)) {
            $old = $nonprofit['j6_receipt'] ?? $data['j6_receipt'];
            if (array_key_exists('j6_receipt', $data) && array_key_exists('j6_receipt', $nonprofit) && $data['j6_receipt'] !== $nonprofit['j6_receipt']) {
                throw new \LogicException('Archiv DPPO 504 nese dva různé receipty posouzení J6.');
            }
            $receipt = NonprofitDppoJ6Receipt::rebase($old, $assessment);
        }

        $inputs = $this->text($record['inputs']);
        $rows = array_column($assessment['result']['rows'], null, 'id');
        $adjustments = [];
        foreach ($nonprofit['adjustments'] ?? [] as $adjustment) {
            $adjustments[] = $this->rebaseAdjustment($adjustment, $assessmentRef, $rows);
        }
        $context = $nonprofit;
        $context['legal_profile'] = $legal;
        $context['assessment'] = $assessmentRef;
        if ($receipt !== null && array_key_exists('j6_receipt', $nonprofit)) {
            $context['j6_receipt'] = $receipt;
        }
        $context['bridge'] = (new NonprofitDppoClassificationBridge())->build($assessment['result'], $inputs['nonprofit']['documented_lines'] ?? []);
        $context['adjustments'] = $adjustments;
        if (array_key_exists('saving_due', $nonprofit)) {
            $context['saving_due'] = $this->text($nonprofit['saving_due']);
        }
        $context['elections_hash'] = NonprofitTaxRules::hash($inputs['nonprofit'] ?? []);
        $context = NonprofitDppoComputation::seal($context);

        $statements = $data['statements'];
        foreach (['balance_sheet', 'income_statement'] as $key) {
            if (isset($statements[$key]['entity']) && is_array($statements[$key]['entity'])) {
                $statements[$key]['entity'] = $this->text($statements[$key]['entity']);
            }
        }
        if (array_key_exists('epo_rows', $statements)) {
            $statements['epo_rows'] = (new NonprofitDppoStatementRows())->build($statements['balance_sheet'], $statements['income_statement']);
        }

        $newData = [];
        foreach ($data as $key => $value) {
            $newData[$key] = match (true) {
                in_array($key, self::KEEP_DATA_KEYS, true) => $value,
                $key === 'nonprofit' => $context,
                $key === 'j6_receipt' => $receipt,
                $key === 'statements' => $statements,
                $key === 'source_manifest' => $value,
                default => is_array($value) ? $this->text($value) : (is_string($value) ? $this->scrubber->scrub($value) : $value),
            };
        }
        $newData['source_manifest'] = $this->manifest($data['source_manifest'], $legal, $assessment, $receipt, $context, $statements);

        $entity = $record['entity_proof'];
        $entity['supplier'] = $this->supplier($entity['supplier'] ?? []);
        foreach (['representation', 'settings', 'notes', 'entity_header'] as $key) {
            if (is_array($entity[$key] ?? null)) {
                $entity[$key] = $this->text($entity[$key]);
            }
        }

        return NonprofitDppoStoredProof::rebaseHistorical($record, $newData, $inputs, $entity);
    }

    /**
     * @param array<string,mixed> $assessmentRef
     * @param array<string, array<string,mixed>> $rows
     */
    private function rebaseAdjustment(array $adjustment, array $assessmentRef, array $rows): array
    {
        $proof = $adjustment['proof'] ?? null;
        if (!is_array($proof) || ($proof['assessment_id'] ?? null) !== $assessmentRef['id']) {
            throw new \LogicException('Úprava archivu DPPO 504 neodkazuje na posouzení archivu.');
        }
        $rebased = $this->text($adjustment);
        foreach (['kind', 'source_id', 'source_ids', 'asset_id', 'line', 'amount_cents'] as $key) {
            if (array_key_exists($key, $adjustment)) {
                $rebased[$key] = $adjustment[$key];
            }
        }
        $rebased['proof']['assessment_id'] = $assessmentRef['id'];
        $rebased['proof']['input_hash'] = $assessmentRef['input_hash'];
        $rebased['proof']['request_hash'] = $assessmentRef['request_hash'];
        if (array_key_exists('row', $proof)) {
            $rebased['proof']['row'] = $rows[$adjustment['source_id'] ?? ''] ?? throw new \LogicException('Úprava archivu DPPO 504 odkazuje na neznámý řádek posouzení.');
        }
        if (array_key_exists('rows', $proof)) {
            $rebased['proof']['rows'] = array_map(
                static fn (array $old): array => $rows[$old['id'] ?? ''] ?? throw new \LogicException('Odpisová úprava archivu DPPO 504 odkazuje na neznámý řádek posouzení.'),
                $proof['rows'],
            );
        }

        return $rebased;
    }

    private function manifest(array $old, array $legal, array $assessment, ?array $receipt, array $context, array $statements): array
    {
        $unknown = array_diff(array_keys($old), self::MANIFEST_KEYS);
        if ($unknown !== []) {
            throw new \LogicException('Podklady archivu DPPO 504 nesou neznámá pole: ' . implode(', ', $unknown) . '.');
        }
        $manifest = $old;
        $manifest['legal_profile'] = $legal;
        $manifest['input_hash'] = $assessment['input_hash'];
        $manifest['request_hash'] = $assessment['request_hash'];
        if (array_key_exists('j6_receipt_hash', $old)) {
            $manifest['j6_receipt_hash'] = $receipt['receipt_hash'] ?? throw new \LogicException('Archiv DPPO 504 bez receiptu posouzení J6.');
        }
        $manifest['nonprofit_proof_hash'] = $context['proof_hash'];
        if (array_key_exists('saving_due', $old)) {
            $manifest['saving_due'] = $context['saving_due'];
        }
        if (array_key_exists('adjustments', $old)) {
            $manifest['adjustments'] = $context['adjustments'];
        }
        if (array_key_exists('statements_hash', $old)) {
            $manifest['statements_hash'] = NonprofitTaxRules::hash($statements);
        }
        if (is_array($old['net_turnover'] ?? null)) {
            $manifest['net_turnover']['ledger_hash'] = NonprofitTaxRules::hash($assessment['snapshot']['ledger'] ?? []);
        }

        return $manifest;
    }

    /**
     * Identita poplatníka ve zmrazeném důkazu se pseudonymizuje stejnými strategiemi jako
     * řádek firmy, takže se shodný historický údaj promítne do stejného pseudonymu.
     */
    private function supplier(array $supplier): array
    {
        $strategies = AnonymizationPolicy::COLUMNS['supplier'];
        foreach ($supplier as $column => $value) {
            if ($value === null || $value === '' || is_bool($value) || is_int($value) || is_float($value)) {
                continue;
            }
            $strategy = $strategies[$column] ?? 'text';
            $supplier[$column] = match (true) {
                $strategy === 'keep' => $value,
                in_array($strategy, ['wipe', 'token', 'password'], true) => null,
                is_array($value) => $this->text($value),
                $strategy === 'json' => $this->scrubber->scrubJson((string) $value),
                default => $this->scrubber->byStrategy($strategy, (string) $value),
            };
        }

        return $supplier;
    }

    private function xml(int $supplierId, int $snapshotId, string $oldSha, string $oldXml, array $record, array $computed): string
    {
        $key = $supplierId . ':' . $oldSha;
        $previous = $this->rebuiltXml[$key] ?? null;
        if ($previous !== null) {
            if ($previous['filing_proof_hash'] !== $record['filing_proof_hash']) {
                throw new \LogicException("Stejné XML archivu DPPO 504 by v kopii dostalo dvě různé podoby (snapshot {$snapshotId}).");
            }
            $statement = $this->db->pdo()->prepare('SELECT xml_content FROM income_tax_return_snapshots WHERE supplier_id = ? AND id = ?');
            $statement->execute([$supplierId, $previous['snapshot_id']]);

            return (string) $statement->fetchColumn();
        }
        $builder = $this->builder ?? throw new \LogicException('Sestavení XML archivu DPPO 504 není připravené.');
        $xml = $builder->build($record, $computed, self::xmlMeta($oldXml))['xml'];
        $this->rebuiltXml[$key] = ['filing_proof_hash' => $record['filing_proof_hash'], 'snapshot_id' => $snapshotId];

        return $xml;
    }

    private function rewriteReturn(int $supplierId, int $returnId, int $snapshotId, string $snapshotJson): void
    {
        if (!$this->protected->has(FinancialArchiveProtectedTable::TAX_RETURN, $returnId)) {
            return;
        }
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare('SELECT final_snapshot_id, inputs FROM income_tax_returns WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $statement->execute([$supplierId, $returnId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['final_snapshot_id'] !== $snapshotId) {
            return;
        }
        $inputs = $row['inputs'] === null ? null : json_decode((string) $row['inputs'], true, 512, JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE income_tax_returns SET computed = ?, inputs = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$snapshotJson, is_array($inputs) ? self::encode($this->text($inputs)) : $row['inputs'], $supplierId, $returnId]);
    }

    private function rewriteSubmissions(int $supplierId, int $year, string $oldSha, string $oldXml, string $xml): void
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            "SELECT id, xml_content, notes, summary_json, validation_errors FROM tax_submissions
              WHERE supplier_id = ? AND form_code = 'dppdp9' AND period_year = ? AND xml_sha256 = ? ORDER BY id FOR UPDATE",
        );
        $statement->execute([$supplierId, $year, $oldSha]);
        $update = $pdo->prepare('UPDATE tax_submissions SET xml_content = ?, xml_sha256 = ?, notes = ?, summary_json = ?, validation_errors = ? WHERE supplier_id = ? AND id = ?');
        $newSha = hash('sha256', $xml);
        while (($copy = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) $copy['id'];
            if (!$this->protected->has(FinancialArchiveProtectedTable::SUBMISSION, $id) || (string) $copy['xml_content'] !== $oldXml) {
                throw new \LogicException("Kopie XML archivu DPPO 504 v podání {$id} se po zachycení změnila.");
            }
            $update->execute([
                $xml, $newSha,
                $copy['notes'] === null ? null : $this->scrubber->scrub((string) $copy['notes']),
                $copy['summary_json'] === null ? null : $this->scrubber->scrubJson((string) $copy['summary_json']),
                $copy['validation_errors'] === null ? null : $this->scrubber->scrubJson((string) $copy['validation_errors']),
                $supplierId, $id,
            ]);
            $this->submissions[] = ['supplier_id' => $supplierId, 'tax_submission_id' => $id, 'source_xml_sha256' => $oldSha, 'target_xml_sha256' => $newSha];
        }
    }

    /**
     * Každé chráněné podání muselo dostat přepsané XML; jinak by v kopii zůstal původní
     * obsah, který obecný průchod přeskočil.
     */
    private function assertSubmissionsRewritten(): void
    {
        if ($this->protected->count(FinancialArchiveProtectedTable::SUBMISSION) !== count($this->submissions)) {
            throw new \LogicException('Některá kopie XML archivu DPPO 504 v podáních nebyla přepsána.');
        }
    }

    private function text(array $value): array
    {
        return NonprofitDppoCloneScrubber::transform($this->scrubber, $value);
    }

    /**
     * Hlavička podání, kterou typovaný záznam nenese: verze software a formy přiznání.
     *
     * @return array<string,string>
     */
    private static function xmlMeta(string $xml): array
    {
        $dom = self::dom($xml);
        $xpath = new \DOMXPath($dom);
        $meta = [];
        $root = $xpath->query('/Pisemnost')?->item(0);
        $form = $xpath->query('/Pisemnost/DPPDP9')?->item(0);
        $header = $xpath->query('/Pisemnost/DPPDP9/VetaD')?->item(0);
        if (!$root instanceof \DOMElement || !$form instanceof \DOMElement || !$header instanceof \DOMElement) {
            throw new \LogicException('Archiv DPPO 504 nemá úplnou hlavičku XML.');
        }
        if ($root->hasAttribute('verzeSW')) {
            $meta['verze_sw'] = $root->getAttribute('verzeSW');
        }
        if ($form->hasAttribute('verzePis')) {
            $meta['verze_pis'] = $form->getAttribute('verzePis');
        }
        foreach (['typ_dapdpp', 'dapdpp_forma', 'kc_dppiv1', 'kc_dppiv2', 'kc_dppiv3'] as $attribute) {
            if ($header->hasAttribute($attribute)) {
                $meta[$attribute] = $header->getAttribute($attribute);
            }
        }
        if ($header->hasAttribute('d_zjist')) {
            $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $header->getAttribute('d_zjist'));
            if ($date === false) {
                throw new \LogicException('Archiv DPPO 504 má neplatné datum zjištění.');
            }
            $meta['d_zjist'] = $date->format('Y-m-d');
        }

        return $meta;
    }

    /**
     * Věty podání bez identity poplatníka a bez obsahu příloh. Pseudonymizace smí změnit
     * jen hodnoty identity a bajty přiloženého PDF, nic jiného.
     *
     * @return list<array{name:string, attributes:array<string,string>|list<string>}>
     */
    private static function xmlProjection(string $xml): array
    {
        $dom = self::dom($xml);
        $form = (new \DOMXPath($dom))->query('/Pisemnost/DPPDP9')?->item(0);
        if (!$form instanceof \DOMElement) {
            throw new \LogicException('Archiv DPPO 504 nemá větu DPPDP9.');
        }
        $out = [];
        $walk = static function (\DOMElement $element) use (&$walk, &$out): void {
            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $attributes = [];
                foreach ($child->attributes as $attribute) {
                    $attributes[$attribute->name] = $attribute->value;
                }
                ksort($attributes);
                if (in_array($child->nodeName, self::IDENTITY_SENTENCES, true)) {
                    $attributes = array_keys($attributes);
                }
                $out[] = ['name' => $child->nodeName, 'attributes' => $attributes];
                $walk($child);
            }
        };
        $walk($form);

        return $out;
    }

    private static function dom(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \LogicException('Archiv DPPO 504 nemá čitelné XML.');
        }

        return $dom;
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
