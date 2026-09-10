<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\AttachmentCheck;

use MyInvoice\Repository\AttachmentCheckRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Kontrola zaúčtovaných dokladů proti uloženému vytěžení jejich příloh.
 *
 * Dvě cesty čtení, obě nad stejným {@see AttachmentComparator}:
 *  - {@see evaluate()} porovná živě. Používá ji detail dokladu a měsíční i předuzávěrková
 *    kontrola — brána nad daňovým obdobím nesmí záviset na tom, jestli se uložený
 *    výsledek stihl přepočítat.
 *  - {@see listMismatches()} čte uložené výsledky pro přehled rozporů celé firmy.
 *    Ukládá je {@see recheckEntity()} (po vytěžení, připojení přílohy, změně dokladu)
 *    a hromadný {@see recheckAll()}.
 *
 * Potvrzení „v pořádku" platí k otisku porovnávaných hodnot. Dokud se nezmění doklad
 * ani vytěžení, rozdíl se nehlásí; změna kterékoli hodnoty ho vrátí.
 */
final class AttachmentCheckService
{
    public const MIN_REASON_LENGTH = 3;

    public function __construct(
        private readonly AttachmentCheckRepository $repo,
        private readonly ActivityLogger $activity,
    ) {}

    public static function isEntityType(string $type): bool
    {
        return in_array($type, AttachmentCheckRepository::ENTITY_TYPES, true);
    }

    /**
     * Živé porovnání. Bez typu a id celá firma (případně jen rozsah dat).
     *
     * @return list<array<string,mixed>>
     */
    public function evaluate(int $supplierId, ?string $entityType = null, ?int $entityId = null, ?string $from = null, ?string $to = null): array
    {
        // Pro obsah se bere nejnovější verze schématu vytěžení — starší řádky jsou historie.
        $pairs = [];
        foreach ($this->repo->loadPairs($supplierId, $entityType, $entityId, $from, $to) as $p) {
            $key = self::key($p['entity_type'], $p['entity_id'], $p['sha256']);
            if (!isset($pairs[$key]) || $p['schema_version'] > $pairs[$key]['schema_version']) {
                $pairs[$key] = $p;
            }
        }
        $acks = $this->repo->acks($supplierId, $entityType, $entityId);

        $out = [];
        foreach ($pairs as $key => $p) {
            $r = AttachmentComparator::compare($p['doc'], $p['ext']);
            $ack = $acks[$key] ?? null;
            $acknowledged = $r['status'] === AttachmentComparator::STATUS_MISMATCH
                && $ack !== null && $ack['ack_fingerprint'] === $r['fingerprint'];
            $out[] = [
                'entity_type' => $p['entity_type'],
                'entity_id' => $p['entity_id'],
                'sha256' => $p['sha256'],
                'document_id' => $p['document_id'],
                'extraction_id' => $p['extraction_id'],
                'doc_no' => $p['label']['doc_no'],
                'partner_name' => $p['label']['partner_name'],
                'issue_date' => $p['label']['issue_date'],
                'amount' => $p['label']['amount'],
                'currency' => $p['label']['currency'],
                'doc_tax_date' => $p['doc']['tax_date'] ?? null,
                'attachment_tax_date' => $p['ext']['tax_date'] ?? null,
                'status' => $r['status'],
                'severity' => $r['severity'],
                'findings' => $r['findings'],
                'fingerprint' => $r['fingerprint'],
                'acknowledged' => $acknowledged,
                'open' => $r['status'] === AttachmentComparator::STATUS_MISMATCH && !$acknowledged,
                // Dřívější potvrzení se ukáže i po změně otisku — jako kontext, proč
                // se rozdíl kdysi uznal.
                'ack_reason' => $ack['ack_reason'] ?? null,
                'ack_at' => $ack['ack_at'] ?? null,
                'ack_by' => $ack['ack_by'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Přepočítá a uloží výsledek pro jeden doklad. Výsledky příloh, které už
     * k dokladu nepatří, zahodí.
     *
     * @return list<array<string,mixed>>
     */
    public function recheckEntity(int $supplierId, string $entityType, int $entityId): array
    {
        if (!self::isEntityType($entityType)) {
            return [];
        }
        $rows = $this->evaluate($supplierId, $entityType, $entityId);
        foreach ($rows as $r) {
            $this->repo->upsert($supplierId, $r);
        }
        $this->repo->deleteStale($supplierId, $entityType, $entityId, array_values(array_unique(array_column($rows, 'sha256'))));
        return $rows;
    }

    /**
     * Hromadný přepočet. Bez rozsahu projde celou firmu a zahodí i výsledky
     * dokladů, které přílohu (nebo vytěžení) už nemají.
     *
     * @return array{checked:int, mismatches:int, open:int, removed:int}
     */
    public function recheckAll(int $supplierId, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->evaluate($supplierId, null, null, $from, $to);
        $mismatches = 0;
        $open = 0;
        foreach ($rows as $r) {
            $this->repo->upsert($supplierId, $r);
            $mismatches += $r['status'] === AttachmentComparator::STATUS_MISMATCH ? 1 : 0;
            $open += $r['open'] ? 1 : 0;
        }
        $removed = 0;
        if ($from === null && $to === null) {
            $removed = $this->repo->deleteAllExcept(
                $supplierId,
                array_map(static fn (array $r): string => self::key($r['entity_type'], $r['entity_id'], $r['sha256']), $rows),
            );
        }
        return ['checked' => count($rows), 'mismatches' => $mismatches, 'open' => $open, 'removed' => $removed];
    }

    /**
     * Potvrzení „v pořádku" s povinným důvodem. Váže se k aktuálnímu otisku, takže
     * se rozdíl znovu ukáže, jakmile se doklad nebo vytěžení změní.
     *
     * @return array{ok:bool, error?:string, row?:array<string,mixed>}
     */
    public function acknowledge(int $supplierId, string $entityType, int $entityId, string $sha256, string $reason, ?int $userId): array
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            return ['ok' => false, 'error' => 'reason_required'];
        }
        if (!self::isEntityType($entityType)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $row = null;
        foreach ($this->evaluate($supplierId, $entityType, $entityId) as $r) {
            if ($r['sha256'] === $sha256) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($row['status'] !== AttachmentComparator::STATUS_MISMATCH) {
            return ['ok' => false, 'error' => 'nothing_to_acknowledge'];
        }
        $this->repo->upsert($supplierId, $row);
        $this->repo->acknowledge($supplierId, $entityType, $entityId, $sha256, $row['fingerprint'], $reason, $userId);
        $this->activity->log(
            'attachment_check.acknowledged',
            $userId,
            $entityType,
            $entityId,
            [
                'sha256' => $sha256,
                'reason' => mb_substr($reason, 0, 500),
                'fields' => array_column($row['findings'], 'field'),
                'fingerprint' => $row['fingerprint'],
            ],
            null,
            null,
            $supplierId,
        );
        $row['acknowledged'] = true;
        $row['open'] = false;
        $row['ack_reason'] = mb_substr($reason, 0, 500);
        return ['ok' => true, 'row' => $row];
    }

    /**
     * Nálezy pro měsíční a předuzávěrkovou kontrolu (`ClosingService::buildChecks`).
     * Jen otevřené rozdíly — potvrzené k aktuálnímu otisku se nehlásí. Doklad
     * s rozdílem DUZP přes hranici měsíce a dopadem na DPH jde do `vat_period`,
     * ostatní do `other`.
     *
     * @return array{vat_period:list<array<string,mixed>>, other:list<array<string,mixed>>}
     */
    public function closingFindings(int $supplierId, string $from, string $to): array
    {
        $vat = [];
        $other = [];
        foreach ($this->evaluate($supplierId, null, null, $from, $to) as $r) {
            if (!$r['open']) {
                continue;
            }
            $issues = [];
            $detail = [];
            foreach ($r['findings'] as $f) {
                $code = 'attachment_' . $f['field'];
                $issues[] = $code;
                $detail[$code] = ['doc' => $f['doc'], 'attachment' => $f['attachment']];
            }
            $item = [
                'doc_type' => $r['entity_type'],
                'doc_id' => $r['entity_id'],
                'doc_no' => $r['doc_no'],
                'doc_date' => $r['doc_tax_date'] ?? $r['issue_date'],
                'partner_name' => $r['partner_name'],
                'amount' => $r['amount'],
                'currency' => $r['currency'],
                'issues' => $issues,
                'detail' => $detail,
            ];
            if ($r['severity'] === AttachmentComparator::SEVERITY_WARNING) {
                $vat[] = $item;
            } else {
                $other[] = $item;
            }
        }
        $byDate = static fn (array $a, array $b): int => [(string) $a['doc_date'], $a['doc_id']] <=> [(string) $b['doc_date'], $b['doc_id']];
        usort($vat, $byDate);
        usort($other, $byDate);
        return ['vat_period' => $vat, 'other' => $other];
    }

    /**
     * Uložené rozdíly pro přehled rozporů.
     *
     * @param list<array{0:string,1:int}>|null $entities
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function listMismatches(int $supplierId, string $state = 'open', ?array $entities = null, int $limit = 500): array
    {
        if (!in_array($state, ['open', 'acknowledged', 'all'], true)) {
            $state = 'open';
        }
        return $this->repo->listMismatches($supplierId, $state, $entities, $limit);
    }

    private static function key(string $type, int $id, string $sha): string
    {
        return $type . ':' . $id . ':' . $sha;
    }
}
