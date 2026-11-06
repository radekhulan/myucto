<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class PropertyDimensionPolicy
{
    public function __construct(private readonly Connection $db) {}
    public function lockPeriods(int $supplierId): void
    {
        (new PropertyCatalog($this->db))->lockContext($supplierId);
    }

    public function assertAccountingDate(int $supplierId, string $date, array $lines, array $previousLines = []): void
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow($supplierId, $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, $previousLines);
    }

    public function assertAccountingDateBatch(int $supplierId,array $documents):void
    {
        $byDate=[];
        foreach($documents as$document){
            $date=(string)($document['accounting_date']??'');$lines=$document['lines']??null;
            if($date===''||!is_array($lines)||$lines===[])throw new \LogicException('Invalid property dimension batch.');
            $byDate[$date]??=[];
            array_push($byDate[$date],...$lines);
        }
        ksort($byDate,SORT_STRING);
        foreach($byDate as$date=>$lines)$this->assertAccountingDate($supplierId,$date,$lines);
    }

    public function assertNonprofitCapitalJournal(array $header,array $lines,\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof $proof):void
    {
        $carried=$proof->indices($this->db,$header,$lines);$date=(string)$header['entry_date'];
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Prague'));
        if($start===false||$start->format('Y-m-d')!==$date)PropertyInput::invalid('entry_date');
        $this->assertWindow((int)$header['supplier_id'],$start->format('Y-m-d\TH:i:sP'),$start->modify('+1 day')->format('Y-m-d\TH:i:sP'),$lines,[],$carried);
    }

    public function assertFundingJournal(array $header,array $lines,Funds\FundingProof $proof):void
    {
        $carried=$proof->indices($this->db,$header,$lines);
        $start=new \DateTimeImmutable($header['entry_date'],new \DateTimeZone('Europe/Prague'));
        $this->assertWindow((int)$header['supplier_id'],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM),$lines,[],$carried);
    }
    public function assertPaymentCarrierJournal(array $header, array $lines, Payments\PaymentHistoricalCarrierProof $proof): void
    {
        $carried = $proof->indices($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], $carried);
    }

    public function assertServiceSettlementJournal(array $header,array $lines,Services\Settlement\SettlementHistoricalCarrierProof $proof):void
    {
        $carried=$proof->indices($this->db,$header,$lines);
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$header['entry_date'],new \DateTimeZone('Europe/Prague'));
        if($start===false||$start->format('Y-m-d')!==$header['entry_date'])PropertyInput::invalid('entry_date');
        $this->assertWindow((int)$header['supplier_id'],$start->format('c'),$start->modify('+1 day')->format('c'),$lines,[],$carried);
    }

    public function assertServiceLifecycleJournal(array $header,array $lines,Services\Settlement\ServiceLifecycleProof $proof):void
    {$carried=$proof->indices($this->db,$header,$lines);$start=new \DateTimeImmutable($header['entry_date'],new \DateTimeZone('Europe/Prague'));$this->assertWindow((int)$header['supplier_id'],$start->format('c'),$start->modify('+1 day')->format('c'),$lines,[],$carried);}

    public function assertOpeningJournal(array $header,array $lines,Opening\OpeningOwnerProof $proof,string $part):void
    {
        $carried=$proof->indices($this->db,$header,$lines,$part);
        $start=new \DateTimeImmutable($header['entry_date'],new \DateTimeZone('Europe/Prague'));
        $this->assertWindow((int)$header['supplier_id'],$start->format('c'),$start->modify('+1 day')->format('c'),$lines,[],$carried);
    }

    public function assertClosingCarryJournal(array $header, array $lines, \MyInvoice\Service\Accounting\Closing\ClosingCarryJournalProof|\MyInvoice\Service\Accounting\Conversion\FrameworkConversionJournalProof $proof): void
    {
        $carried = $proof->indices($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], $carried);
    }

    public function assertDeferredTax504Journal(array $header, array $lines, \MyInvoice\Service\Accounting\Closing\DeferredTax504Proof $proof): void
    {
        $carried = $proof->indices($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], $carried);
    }

    public function assertFx504Journal(array $header, array $lines, \MyInvoice\Service\Accounting\Closing\Fx504JournalProof $proof): void
    {
        $retained = $proof->assignments($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], [], $retained);
    }

    public function assertLegalClosingJournal(array $header, array $lines, \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof $proof): void
    {
        $carried = $proof->indices($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], $carried);
    }

    public function assertAssetReserveCorrectionJournal(array $header, array $lines, \MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof $proof): void
    {
        $carried = $proof->indices($this->db, $header, $lines);
        $date = (string) $header['entry_date'];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
        if ($start === false || $start->format('Y-m-d') !== $date) PropertyInput::invalid('entry_date');
        $this->assertWindow((int) $header['supplier_id'], $start->format('Y-m-d\TH:i:sP'), $start->modify('+1 day')->format('Y-m-d\TH:i:sP'), $lines, [], $carried);
    }

    public function assertAt(int $supplierId, string $at, array $lines): void
    {
        $this->assertWindow($supplierId, $at, null, $lines);
    }

    public function assertStructural(int $supplierId, array $lines, array $previousLines = []): void
    {
        $this->assertWindow($supplierId, null, null, $lines, $previousLines);
    }

    /** Owner locks periods before catalog/journal rows; this check must precede the first mutation. */
    private function assertWindow(int $supplierId, ?string $from, ?string $to, array $lines, array $previousLines = [], array $carried = [], array $fxRetained = []): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Property dimensions require period-first transaction locks.');
        $from = $from === null ? null : PropertyInstant::normalize($from);
        $to = $to === null ? null : PropertyInstant::normalize($to);
        $ids = [];
        foreach ($lines as $line) foreach (['dimensions', 'dimension_splits'] as $field) foreach (array_keys($line[$field] ?? []) as $id) $ids[(int) $id] = (int) $id;
        if ($ids === []) return;

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, supplier_id, system_key, is_active FROM dimension_types WHERE id IN ({$marks}) AND system_key IN ('unit','service') ORDER BY id FOR UPDATE");
        $stmt->execute(array_values($ids));
        $types = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        if ($types === []) return;
        foreach ($types as $type) if ((int) $type['supplier_id'] !== $supplierId) $this->unavailable();
        $framework = $from === null ? null : (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forDateForUpdate($supplierId, (new \DateTimeImmutable($from))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d'));
        if ($from !== null && $framework !== AccountingFrameworkService::CZ_504) throw new PropertyException('property_framework_required', 'Jednotky a služby SVJ vyžadují účetní rámec podle vyhlášky 504 k datu zápisu.', 409);
        $requested = [];
        $retained = self::retainedTuples($lines, $previousLines, $types) + $carried;
        foreach ($lines as $index => $line) {
            if (isset($line['document_assignment_owners']) && ($line['id'] ?? null) === null
                && array_intersect_key(($line['dimensions'] ?? []) + ($line['dimension_splits'] ?? []), $types) !== []) {
                throw new PropertyException('property_document_item_missing', 'Jednotku nebo službu lze přiřadit pouze existující položce dokladu.', 409);
            }
            $fractional = 0;
            foreach ($line['dimension_splits'] ?? [] as $typeId => $shares) {
                if (!isset($types[$typeId])) continue;
                if (count($shares) > 1) ++$fractional;
                foreach ($shares as $valueId => $share) { $key = $typeId . ':' . $valueId; $requested[$key] = [(int) $typeId, (int) $valueId, (isset($retained[$index]) || isset($fxRetained[$index]['dimension_splits'][$typeId][$valueId])) && ($requested[$key][2] ?? true)]; }
            }
            if ($fractional > 1) throw new PropertyException('property_joint_required', 'Rozdělení jednotek i služeb vyžaduje výslovné společné řádky.', details: ['system_keys' => ['unit', 'service']]);
            foreach ($line['dimensions'] ?? [] as $typeId => $valueId) if (isset($types[$typeId])) { $key = $typeId . ':' . $valueId; $requested[$key] = [(int) $typeId, (int) $valueId, (isset($retained[$index]) || ($fxRetained[$index]['dimensions'][$typeId] ?? null) === (int) $valueId) && ($requested[$key][2] ?? true)]; }
        }
        if ($requested === []) return;
        $valueIds = array_values(array_unique(array_column($requested, 1)));
        $marks = implode(',', array_fill(0, count($valueIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, type_id, is_active FROM dimension_values WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $stmt->execute([$supplierId, ...$valueIds]);
        $values = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $reader = new PropertyReadRepository($this->db);
        $objects = array_column($reader->objects($supplierId, null, true), null, 'id');
        $bound = array_column(array_filter($objects, static fn (array $o): bool => $o['dimension_value_id'] !== null), null, 'dimension_value_id');
        $objectIds = [];
        foreach ($requested as [$typeId, $valueId, $retained]) {
            if (!isset($values[$valueId], $bound[$valueId]) || (int) $values[$valueId]['type_id'] !== $typeId
                || $bound[$valueId]['kind'] !== $types[$typeId]['system_key']) $this->unavailable($valueId);
            if ($from === null || $retained) continue;
            if (!(bool) $values[$valueId]['is_active'] || !(bool) $types[$typeId]['is_active']) $this->unavailable($valueId);
            $objectIds[] = $bound[$valueId]['id'];
            if ($bound[$valueId]['parent_id'] !== null) $objectIds[] = $bound[$valueId]['parent_id'];
        }
        if ($objectIds === []) return;
        $histories = $reader->histories($supplierId, array_values(array_unique($objectIds)), true);
        $profiles = (new PropertyProfileRepository($this->db))->history($supplierId, true);
        $points = [$from];
        foreach ([$profiles, ...array_values($histories)] as $history) foreach (PropertyTimeline::boundaries($history) as $point) {
            if ($to !== null && $point > $from && $point < $to) $points[] = $point;
        }
        foreach (array_unique($points) as $point) {
            $enabled = false;
            foreach ($profiles as $profile) if (PropertyInstant::contains($profile['effective_from'], $profile['effective_to'], $point)) {
                $enabled = $profile['enabled'];
                break;
            }
            if (!$enabled) throw new PropertyException('property_profile_required', 'V daném okamžiku není aktivní potvrzený profil SVJ.', 409, ['at' => $point]);
            foreach ($objectIds as $id) if (!(PropertyTimeline::at($histories[$id] ?? [], $point)['revision']['state']['active'] ?? false)) $this->unavailable(null, $id, $point);
        }
    }

    private static function retainedTuples(array $lines, array $previous, array $types): array
    {
        $byId = $byNumber = [];
        foreach ($previous as $index => $line) {
            if (isset($line['id'])) $byId[(string) $line['id']] = $line;
            if (!isset($line['document_assignment_owners'])) $byNumber[(int) ($line['line_no'] ?? $index)] = $line;
        }
        $out = $used = [];
        foreach ($lines as $index => $line) {
            $id = isset($line['id']) ? (string) $line['id'] : null;
            $old = $id === null && !isset($line['document_assignment_owners'])
                ? ($byNumber[(int) ($line['line_no'] ?? $index)] ?? null) : ($id === null ? null : ($byId[$id] ?? null));
            $id = isset($old['id']) ? 'id:' . $old['id'] : 'number:' . ($old['line_no'] ?? $index);
            if ($old === null || isset($used[$id]) || !\MyInvoice\Service\Accounting\JournalLineComparison::sameLedgerLine($line, $old)) continue;
            $same = array_intersect_key($line['document_assignment_owners'] ?? [], $types) === array_intersect_key($old['document_assignment_owners'] ?? [], $types);
            foreach (['dimensions', 'dimension_splits'] as $field) {
                $a = array_intersect_key($line[$field] ?? [], $types);
                $b = array_intersect_key($old[$field] ?? [], $types);
                if ($field === 'dimension_splits' ? !\MyInvoice\Repository\DimensionAssignmentRepository::sameSplits($a, $b) : $a != $b) $same = false;
            }
            if ($same) { $out[$index] = true; $used[$id] = true; }
        }
        return $out;
    }

    private function unavailable(?int $valueId = null, ?int $objectId = null, ?string $at = null): never
    {
        throw new PropertyException('property_dimension_unavailable', 'Jednotka nebo služba není v daném okamžiku dostupná v registru firmy.', 409,
            ['value_id' => $valueId, 'object_id' => $objectId, 'at' => $at]);
    }
}
