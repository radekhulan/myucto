<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Services\Settlement\{SettlementDeadlines,SettlementEvidence};
use PDO;

/** Evidence námitek proti vyúčtování služeb a přehled zákonných lhůt dokumentu. */
final class SettlementObjectionRepository
{
    public function __construct(private readonly Connection $db, private readonly SettlementDossierRepository $dossiers) {}

    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($args);
        return $q;
    }

    private function transaction(int $supplier, callable $work): mixed
    {
        PropertyInput::id($supplier, 'supplier_id');
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $work) {
            (new PaymentLocks($this->db))->acquire($supplier);
            return $work();
        });
    }

    public function deadlines(int $supplier, int $document, string $today): array
    {
        SettlementEvidence::date($today);
        return $this->transaction($supplier, function () use ($supplier, $document, $today): array {
            $read = $this->dossiers->readLocked($supplier, $document);
            $period = $this->q('SELECT document_json FROM property_service_settlements WHERE supplier_id=? AND id=?', [$supplier, $document])->fetchColumn();
            $facts = json_decode((string)$period, true, flags: JSON_THROW_ON_ERROR);
            $objections = $this->rows($supplier, $document);
            return SettlementDeadlines::evaluate($facts['period_to'], $read['dossier'], $objections, $today) + ['parties' => $read['parties']];
        });
    }

    public function record(int $supplier, int $document, int $recipient, string $receivedOn, string $content, int $actor): array
    {
        PropertyInput::id($recipient, 'recipient_id');
        PropertyInput::id($actor, 'actor');
        SettlementEvidence::date($receivedOn);
        $content = PropertyInput::text($content, 'content', 4000);
        return $this->transaction($supplier, function () use ($supplier, $document, $recipient, $receivedOn, $content, $actor): array {
            $read = $this->dossiers->readLocked($supplier, $document);
            if (!in_array($recipient, array_column($read['parties'], 'id'), true)) PropertyInput::invalid('recipient_id');
            $this->q(
                'INSERT INTO property_service_settlement_objections(supplier_id,document_id,recipient_id,received_on,content,created_by,recorded_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',
                [$supplier, $document, $recipient, $receivedOn, $content, $actor]
            );
            return $this->find($supplier, (int)$this->db->pdo()->lastInsertId());
        });
    }

    public function resolve(int $supplier, int $objection, string $resolvedOn, string $resolution, int $actor): array
    {
        PropertyInput::id($objection, 'objection_id');
        PropertyInput::id($actor, 'actor');
        SettlementEvidence::date($resolvedOn);
        $resolution = PropertyInput::text($resolution, 'resolution', 4000);
        return $this->transaction($supplier, function () use ($supplier, $objection, $resolvedOn, $resolution, $actor): array {
            $row = $this->q('SELECT received_on,resolved_on FROM property_service_settlement_objections WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier, $objection])->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new PropertyException('property_not_found', 'Námitka nebyla nalezena.', 404);
            if ($row['resolved_on'] !== null) SettlementEvidence::reject('objection_already_resolved');
            if ($resolvedOn < $row['received_on']) PropertyInput::invalid('resolved_on');
            $this->q(
                'UPDATE property_service_settlement_objections SET resolved_on=?,resolution=?,resolved_by=?,resolved_recorded_at=UTC_TIMESTAMP(6) WHERE supplier_id=? AND id=? AND resolved_on IS NULL',
                [$resolvedOn, $resolution, $actor, $supplier, $objection]
            );
            return $this->find($supplier, $objection);
        });
    }

    private function rows(int $supplier, int $document): array
    {
        $rows = $this->q(
            'SELECT id,recipient_id,received_on,content,resolved_on,resolution,recorded_at FROM property_service_settlement_objections WHERE supplier_id=? AND document_id=? ORDER BY received_on,id',
            [$supplier, $document]
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(self::row(...), $rows);
    }

    private function find(int $supplier, int $id): array
    {
        $row = $this->q(
            'SELECT id,recipient_id,received_on,content,resolved_on,resolution,recorded_at FROM property_service_settlement_objections WHERE supplier_id=? AND id=?',
            [$supplier, $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PropertyException('property_not_found', 'Námitka nebyla nalezena.', 404);
        return self::row($row);
    }

    private static function row(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'recipient_id' => (int)$row['recipient_id'],
            'received_on' => $row['received_on'],
            'content' => $row['content'],
            'resolved_on' => $row['resolved_on'],
            'resolution' => $row['resolution'],
            'recorded_at' => $row['recorded_at'],
        ];
    }
}
