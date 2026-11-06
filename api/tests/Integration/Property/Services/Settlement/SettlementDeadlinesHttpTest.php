<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Service\Property\Services\Settlement\SettlementDeadlines;
use PHPUnit\Framework\TestCase;

/**
 * Lhůty zákona č. 67/2013 Sb. jako upozornění a evidence námitek. Neplatný vstup
 * vypořádání vyúčtování je validační chyba, ne 500.
 */
final class SettlementDeadlinesHttpTest extends TestCase
{
    private SettlementHttpTest $fixture;

    protected function setUp(): void
    {
        $this->fixture = new SettlementHttpTest('testExternalDossierKeepsPerSegmentRecipientsDeliveryAndDueSeparateFromAccounting');
        (new \ReflectionMethod($this->fixture, 'setUp'))->invoke($this->fixture);
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture, 'tearDown'))->invoke($this->fixture);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$args);
    }

    private function saved(): array
    {
        $f = $this->call('openingFixture');
        (new \ReflectionProperty($this->fixture, 'f'))->setValue($this->fixture, $f);
        $request = $this->call('documentRequest', $f);
        [$saved] = $this->call('saveHttp', $request, 'deadline-document');
        return [$f, $saved];
    }

    public function testReceivableCardReportsActualSettlementState(): void
    {
        $data = $this->call('appliedFixture', false);
        $f = $data['f'];
        $db = (new \ReflectionProperty($this->fixture, 'db'))->getValue($this->fixture);
        $projection = new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db);
        $unit = $data['document']->snapshot()['unit_id'];
        $state = fn (int $scope) => $projection->at($f['supplier'], '2027-12-31T12:00:00+01:00', '2030-01-01T00:00:00Z', ['unit_id' => $scope], true)['coverage']['service_settlements'];
        self::assertSame('pending', $state($unit));
        $other = array_values(array_filter($f['units'], fn ($id) => $id !== $unit))[0];
        self::assertSame('none', $state($other));
        $data['owner']->apply($f['supplier'], $data['saved']['id'], $data['request'], 0, $data['preview']['preview_hash'], 's8-apply-state', $f['actor']);
        self::assertSame('applied', $state($unit));
    }

    public function testMonthDeadlinesEndOnMatchingOrLastDay(): void
    {
        self::assertSame('2027-04-30', SettlementDeadlines::addMonths('2026-12-31', 4));
        self::assertSame('2026-10-30', SettlementDeadlines::addMonths('2026-06-30', 4));
        self::assertSame('2027-09-10', SettlementDeadlines::addMonths('2027-05-10', 4));
        self::assertSame('2027-06-09', SettlementDeadlines::addDays('2027-05-10', 30));
    }

    public function testDeadlineOnWeekendOrHolidayMovesToNextWorkingDay(): void
    {
        self::assertSame('2027-11-01', SettlementDeadlines::evaluate('2027-06-30', null, [], '2027-07-01')['delivery_deadline']);
        self::assertSame('2027-12-27', SettlementDeadlines::evaluate('2027-08-24', null, [], '2027-08-25')['delivery_deadline']);
        $objection = SettlementDeadlines::evaluate('2027-06-30', null, [['id' => 1, 'recipient_id' => 1, 'received_on' => '2027-05-07', 'resolved_on' => null]], '2027-06-08')['objections'][0];
        self::assertSame('2027-06-07', $objection['resolution_deadline']);
    }

    public function testMalformedApplicationRequestIsValidationError(): void
    {
        [, $saved] = $this->saved();
        $url = '/api/property/service-settlements/'.$saved['id'].'/preview';
        $valid = ['accounting_date' => '2026-12-31', 'evidence' => 'Synthetic disposition', 'advances' => [], 'reliefs' => [], 'opening_credits' => [], 'new_claim' => null];
        foreach ([
            ['accounting_date' => '2026-02-31'] + $valid,
            ['accounting_date' => null] + $valid,
            ['advances' => 'all'] + $valid,
            ['reliefs' => [['allocation_id' => 'x', 'amount_cents' => '1', 'entitlement' => []]]] + $valid,
            ['opening_credits' => [['origin_key' => 7, 'amount_cents' => '1', 'entitlement' => []]]] + $valid,
            ['new_claim' => 'yes'] + $valid,
            array_diff_key($valid, ['evidence' => true]),
        ] as $i => $request) {
            [$status, $body] = $this->call('http', 'POST', $url, ['request' => $request, 'expected_version' => 0]);
            self::assertSame(422, $status, 'case '.$i.': '.json_encode($body));
            self::assertSame('property_invalid_input', $body['error']['code']);
        }
    }

    public function testDeadlinesWarnAndObjectionsAreRecorded(): void
    {
        [$f, $saved] = $this->saved();
        $base = '/api/property/service-settlements/'.$saved['id'];
        [$status, $none] = $this->call('http', 'GET', $base.'/deadlines', [], null, false, true, ['today' => '2027-05-15']);
        self::assertSame(200, $status, json_encode($none));
        self::assertSame('2027-04-30', $none['delivery_deadline']);
        self::assertSame(['delivery_overdue'], array_column($none['warnings'], 'code'));
        self::assertFalse($none['blocking']);

        $dossier = $this->call('externalDossier', $f);
        [$status, $accepted] = $this->call('http', 'POST', $base.'/dossier-events', ['event' => ['type' => 'acceptance', 'dossier' => $dossier], 'expected_revision' => 0, 'reason' => 'Synthetic dossier acceptance', 'idempotency_key' => 'deadline-acceptance']);
        self::assertSame(200, $status, json_encode($accepted));
        $delivery = array_replace($dossier['deliveries'][0], ['status' => 'delivered', 'delivered_to_id' => $dossier['deliveries'][0]['recipient_id'], 'delivered_at' => '2027-05-10T09:30:00+02:00', 'method' => 'post', 'delivery_evidence' => 'Synthetic postal receipt', 'due' => ['date' => '2027-10-31', 'basis_ref' => 'Synthetic due agreement']]);
        [$status] = $this->call('http', 'POST', $base.'/dossier-events', ['event' => ['type' => 'delivery', 'delivery' => $delivery, 'supersedes_event_id' => $accepted['id']], 'expected_revision' => 1, 'reason' => 'Synthetic late delivery', 'idempotency_key' => 'deadline-delivery']);
        self::assertSame(200, $status);

        [$status, $objection] = $this->call('http', 'POST', $base.'/objections', ['recipient_id' => $delivery['recipient_id'], 'received_on' => '2027-06-20', 'content' => 'Synthetic objection to allocation key']);
        self::assertSame(200, $status, json_encode($objection));
        [$status, $error] = $this->call('http', 'POST', $base.'/objections', ['recipient_id' => 999999, 'received_on' => '2027-06-20', 'content' => 'Synthetic foreign person']);
        self::assertSame(422, $status, json_encode($error));

        [$status, $read] = $this->call('http', 'GET', $base.'/deadlines', [], null, false, true, ['today' => '2027-08-01']);
        self::assertSame(200, $status, json_encode($read));
        $row = $read['deliveries'][0];
        self::assertSame('2027-05-10', $row['delivered_on']);
        self::assertSame('2027-09-10', $row['due_limit']);
        self::assertSame(['delivery_late', 'due_beyond_limit'], $row['warnings']);
        self::assertSame('2027-06-09', $read['objections'][0]['submission_deadline']);
        self::assertSame(['objection_late', 'objection_resolution_overdue'], $read['objections'][0]['warnings']);

        [$status, $resolved] = $this->call('http', 'POST', '/api/property/service-settlements/objections/'.$objection['id'].'/resolve', ['resolved_on' => '2027-07-25', 'resolution' => 'Synthetic objection rejected with reasons']);
        self::assertSame(200, $status, json_encode($resolved));
        [$status, $again] = $this->call('http', 'POST', '/api/property/service-settlements/objections/'.$objection['id'].'/resolve', ['resolved_on' => '2027-07-26', 'resolution' => 'Synthetic second resolution']);
        self::assertSame(409, $status, json_encode($again));
        [, $after] = $this->call('http', 'GET', $base.'/deadlines', [], null, false, true, ['today' => '2027-08-01']);
        self::assertSame(['objection_late', 'objection_resolved_late'], $after['objections'][0]['warnings']);

        [$status] = $this->call('http', 'POST', $base.'/objections', ['recipient_id' => $delivery['recipient_id'], 'received_on' => '2027-06-20', 'content' => 'Synthetic denied'], ['property' => 1, 'property.settlements.manage' => 0]);
        self::assertSame(403, $status);
    }
}
