<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;

use MyInvoice\Service\Property\{PropertyInput, PropertyInstant};

final class SettlementDossier
{
    public static function normalize(array $input, string $periodFrom, string $periodTo): array
    {
        $input = SettlementEvidence::detached($input);
        SettlementEvidence::fields($input, ['schema', 'ref', 'issuer', 'issued_at', 'allocation_ref', 'segments', 'deliveries', 'person_changes']);
        if ($input['schema'] !== 1) SettlementEvidence::reject('dossier_schema');
        foreach (['ref', 'allocation_ref'] as $key) PropertyInput::text($input[$key], $key, 4000);
        if(!is_array($input['issuer']))PropertyInput::invalid('issuer'); SettlementEvidence::fields($input['issuer'], ['name']); PropertyInput::text($input['issuer']['name'], 'issuer.name', 500);
        $input['issued_at'] = PropertyInstant::normalize($input['issued_at']);
        $start = PropertyInstant::normalize((new \DateTimeImmutable(SettlementEvidence::date($periodFrom).'T00:00:00', new \DateTimeZone('Europe/Prague')))->format('c'));
        $stop = PropertyInstant::normalize((new \DateTimeImmutable(SettlementEvidence::date($periodTo).'T00:00:00', new \DateTimeZone('Europe/Prague')))->modify('+1 day')->format('c'));
        $segments = []; foreach (PropertyInput::list($input['segments'], 'segments', 1000) as $segment) {
            if(!is_array($segment))PropertyInput::invalid('segment'); SettlementEvidence::fields($segment, ['ordinal', 'valid_from', 'valid_to', 'recipient_ids', 'basis_ref', 'allocation_ref']);
            $ordinal = PropertyInput::id($segment['ordinal'], 'segment.ordinal'); if (isset($segments[$ordinal])) SettlementEvidence::reject('dossier_duplicate_segment');
            [$segment['valid_from'], $segment['valid_to']] = PropertyInstant::interval($segment['valid_from'], $segment['valid_to']);
            if ($segment['valid_to'] === null) SettlementEvidence::reject('dossier_segment_end_required');
            $segment['recipient_ids'] = SettlementEvidence::ids($segment['recipient_ids']); foreach (['basis_ref', 'allocation_ref'] as $key) PropertyInput::text($segment[$key], $key, 4000);
            $segments[$ordinal] = $segment;
        }
        if ($segments === []) SettlementEvidence::reject('dossier_segments_required');
        uasort($segments, fn(array $a, array $b): int => strcmp($a['valid_from'], $b['valid_from'])); $point = $start;
        foreach ($segments as $segment) { if ($segment['valid_from'] !== $point || $segment['valid_to'] > $stop) SettlementEvidence::reject('dossier_segment_coverage'); $point = $segment['valid_to']; }
        if ($point !== $stop) SettlementEvidence::reject('dossier_segment_coverage');
        $changes = []; foreach (PropertyInput::list($input['person_changes'], 'person_changes', 1000) as $change) {if(!is_array($change))PropertyInput::invalid('person_change');$changes[] = self::personChange($change);}
        $prior = null; foreach ($segments as $segment) {
            if ($prior !== null && $prior['recipient_ids'] !== $segment['recipient_ids']) {
                $matching = array_filter($changes, fn(array $c): bool => $c['role'] === 'service_recipient' && $c['from_ids'] === $prior['recipient_ids'] && $c['to_ids'] === $segment['recipient_ids'] && $c['effective_at'] === $segment['valid_from']);
                if (count($matching) !== 1) SettlementEvidence::reject('dossier_recipient_change_title_required');
            } $prior = $segment;
        }
        $deliveries = []; foreach (PropertyInput::list($input['deliveries'], 'deliveries', 10000) as $delivery) {
            if(!is_array($delivery))PropertyInput::invalid('delivery'); $delivery = self::delivery($delivery, $segments); $key = self::deliveryKey($delivery); if (isset($deliveries[$key])) SettlementEvidence::reject('dossier_duplicate_delivery'); $deliveries[$key] = $delivery;
        }
        foreach ($segments as $segment) foreach ($segment['recipient_ids'] as $recipient) if (!isset($deliveries[$segment['ordinal'].':'.$recipient])) SettlementEvidence::reject('dossier_delivery_state_required');
        ksort($deliveries, SORT_NATURAL); $input['segments'] = array_values($segments); $input['deliveries'] = array_values($deliveries); $input['person_changes'] = $changes;
        return $input;
    }

    public static function delivery(array $delivery, array $segments): array
    {
        SettlementEvidence::fields($delivery, ['segment_ordinal', 'recipient_id', 'issued_to_id', 'delivered_to_id', 'recipient_evidence', 'status', 'delivered_at', 'method', 'delivery_evidence', 'due']);
        $segments = array_column($segments, null, 'ordinal'); $ordinal = PropertyInput::id($delivery['segment_ordinal'], 'segment_ordinal');
        $recipient = PropertyInput::id($delivery['recipient_id'], 'recipient_id'); PropertyInput::id($delivery['issued_to_id'], 'issued_to_id');
        if (!isset($segments[$ordinal]) || !in_array($recipient, $segments[$ordinal]['recipient_ids'], true)) SettlementEvidence::reject('dossier_delivery_scope');
        if ($delivery['delivered_to_id'] !== null) PropertyInput::id($delivery['delivered_to_id'], 'delivered_to_id');
        if ($delivery['issued_to_id'] !== $recipient || ($delivery['delivered_to_id'] !== null && $delivery['delivered_to_id'] !== $recipient)) PropertyInput::text($delivery['recipient_evidence'], 'recipient_evidence', 4000);
        elseif (!is_string($delivery['recipient_evidence'])) PropertyInput::invalid('recipient_evidence');
        if (!in_array($delivery['status'], ['pending', 'delivered'], true)) SettlementEvidence::reject('dossier_delivery_status');
        if ($delivery['status'] === 'delivered') {
            $delivery['delivered_at'] = PropertyInstant::normalize($delivery['delivered_at']); PropertyInput::id($delivery['delivered_to_id'], 'delivered_to_id');
            if (!in_array($delivery['method'], ['data_box', 'post', 'personal', 'other'], true)) SettlementEvidence::reject('dossier_delivery_method');
            PropertyInput::text($delivery['delivery_evidence'], 'delivery_evidence', 4000);
        } elseif ($delivery['delivered_at'] !== null || $delivery['delivered_to_id'] !== null || $delivery['method'] !== null || $delivery['delivery_evidence'] !== '') SettlementEvidence::reject('dossier_pending_delivery_facts');
        if(!is_array($delivery['due']))PropertyInput::invalid('due'); SettlementEvidence::fields($delivery['due'], ['date', 'basis_ref']);
        if ($delivery['due']['date'] !== null) { $delivery['due']['date'] = SettlementEvidence::date($delivery['due']['date']); PropertyInput::text($delivery['due']['basis_ref'], 'due.basis_ref', 4000); }
        elseif ($delivery['due']['basis_ref'] !== '') SettlementEvidence::reject('dossier_due_date_required');
        return $delivery;
    }

    public static function personChange(array $change): array
    {
        SettlementEvidence::fields($change, ['role', 'from_ids', 'to_ids', 'effective_at', 'basis', 'evidence']);
        if (!in_array($change['role'], ['service_recipient', 'creditor', 'debtor'], true) || !in_array($change['basis'], ['agreement', 'assignment', 'inheritance', 'other'], true)) SettlementEvidence::reject('dossier_change_basis');
        $change['from_ids'] = SettlementEvidence::ids($change['from_ids']); $change['to_ids'] = SettlementEvidence::ids($change['to_ids']);
        if ($change['from_ids'] === $change['to_ids']) SettlementEvidence::reject('dossier_unchanged_person');
        $change['effective_at'] = PropertyInstant::normalize($change['effective_at']); PropertyInput::text($change['evidence'], 'change.evidence', 4000); return $change;
    }
    public static function deliveryKey(array $delivery): string { return $delivery['segment_ordinal'].':'.$delivery['recipient_id']; }
    public static function recipientIds(array $dossier): array
    {
        $ids=[];foreach($dossier['segments'] as $segment)array_push($ids,...$segment['recipient_ids']);$ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids;
    }
    public static function eventPartyIds(array $event): array
    {
        if ($event['type'] === 'acceptance') return self::partyIds($event['dossier']);
        if ($event['type'] === 'delivery') {
            $d = $event['delivery']; $ids = [$d['recipient_id'],$d['issued_to_id']]; if ($d['delivered_to_id'] !== null) $ids[] = $d['delivered_to_id'];
        } elseif ($event['type'] === 'legal_metadata') $ids = [...$event['person_change']['from_ids'],...$event['person_change']['to_ids']];
        else SettlementEvidence::reject('dossier_history_corrupt');
        foreach ($ids as $id) PropertyInput::id($id, 'dossier_party'); $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }
    public static function historyPartyIds(?array $accepted, array $events): array
    {
        $ids = $accepted === null ? [] : self::partyIds($accepted);
        foreach ($events as $event) array_push($ids, ...self::eventPartyIds($event));
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }
    public static function partyIds(array $dossier): array
    {
        $ids = []; foreach ($dossier['segments'] as $s) array_push($ids, ...$s['recipient_ids']);
        foreach ($dossier['deliveries'] as $d) foreach (['recipient_id', 'issued_to_id', 'delivered_to_id'] as $key) if ($d[$key] !== null) $ids[] = $d[$key];
        foreach ($dossier['person_changes'] as $c) array_push($ids, ...$c['from_ids'], ...$c['to_ids']); $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }
}
