<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Evidence tržeb z pokladny (EET-05): příjmový pokladní doklad účelu `sale`
 * a `invoice_payment`.
 *
 * Tržba vzniká při zaúčtování dokladu, v jeho transakci: platební událost (hotovost,
 * osobní kontakt), alokace na pokladní doklad i na úhradu faktury, kterou zaúčtování
 * právě založilo, a EET záznam s číslem z řady zařízení. Úhrada faktury z PPD tak
 * nese stejnou identitu platby a druhá tržba z cesty úhrady nevznikne. Odeslání
 * běží až po commitu ({@see EetDispatchService::dispatchAfterCommit()}).
 *
 * Doklad se eviduje u firmy, která k okamžiku tržby ({@see saleTime()}) eviduje.
 * Zařízení: výslovně zvolené u dokladu, jinak namapované na pokladnu
 * (`eet_devices.cash_register_id`), jinak výchozí zařízení firmy. Povinnou hotovost
 * bez zařízení doklad nezaúčtuje (422 `eet_device_required`), tiše se neobchází.
 * Převod mezi pokladnami, vklad, výdaj ani jiný pohyb tržbou nejsou (příjmový
 * doklad sám nic nerozhoduje). Povinná tržba, kterou MVP neumí, doklad nezaúčtuje:
 * politika se nesmí tiše obejít. Prodej z valutové pokladny se eviduje v CZK
 * ekvivalentu dokladu.
 *
 * Storno dokladu s tržbou je skutečné vrácení peněz z pokladny, proto vytvoří
 * zápornou opravu celé dosud neopravené části (výchozí důvod `refund`, nebo
 * `wrong_data` / `duplicate` u chybně pořízeného dokladu). Oprava je vázaná na
 * storno idempotentním klíčem dokladu a účetně ji vypořádává samotné storno.
 *
 * Výdajový doklad k dobropisu (EET-08) je vrácení peněz v hotovosti: zaúčtování
 * vytvoří záporné opravy evidovaných tržeb opravované faktury
 * ({@see afterRefundPost()}, {@see EetCorrectionService::refundInvoiceSales()}).
 * Storno takového dokladu znamená, že se peníze vrátily do pokladny (nebo vrácení
 * neproběhlo). Zápornou opravu nejde vzít zpět (EET opravu opravy nezná, metodika FS
 * „Popis položek datové zprávy“ v1.0, kap. 4, opravuje vždy novou datovou zprávou), proto storno zaeviduje novou kladnou tržbu
 * ve výši oprav dokladu, navázanou na doklad i na opravy ({@see reverseCashRefund()}).
 */
final class EetCashDocumentRecorder
{
    public const SUBJECT_PURPOSES = ['sale', 'invoice_payment'];
    public const REVERSAL_REASONS = ['refund', 'wrong_data', 'duplicate'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordingPolicy $policy,
        private readonly EetRecordFactory $records,
        private readonly EetSettingsService $settings,
        private readonly EetCorrectionService $corrections,
        private readonly EetDispatchService $dispatch,
        private readonly ClockInterface $clock,
        private readonly EetStoredValueCalculator $storedValue,
    ) {}

    /**
     * Po zaúčtování dokladu (v jeho transakci). Vrací id EET záznamu, nebo null.
     *
     * @param array<string,mixed> $doc řádek cash_documents (stav před označením posted)
     * @throws EetCommandException
     */
    public function afterPost(int $supplierId, array $doc, ?int $invoicePaymentId, ?int $explicitDeviceId, ?int $userId): ?int
    {
        if (($doc['doc_type'] ?? null) !== 'in' || !in_array((string) ($doc['purpose'] ?? ''), self::SUBJECT_PURPOSES, true)) {
            return null;
        }
        $cashDocumentId = (int) $doc['id'];
        $invoiceId = ($doc['invoice_id'] ?? null) !== null ? (int) $doc['invoice_id'] : null;
        if ($invoiceId !== null) {
            $this->assertNoQrOverlap($supplierId, $invoiceId, $doc);
        }
        $now = EetValues::local($this->clock->now());
        $saleAt = self::saleTime($doc, $now);
        $settings = $this->settings->get($supplierId);
        if (!$this->policy->activeAt($settings, $saleAt)) {
            return null;
        }
        // Tržbu dokladu pozná alokace na doklad, ne klíč: klíč by šel obsadit zvenčí.
        if ($this->saleRecordId($supplierId, $cashDocumentId) !== null) {
            return null;
        }
        $key = PaymentEventService::internalKey('cash-document', $cashDocumentId);
        $invoiceType = $invoiceId !== null ? $this->invoiceType($supplierId, $invoiceId) : null;
        $purpose = $invoiceType === 'proforma' ? 'order_deposit' : 'sale';
        $currency = strtoupper((string) ($doc['currency_code'] ?? 'CZK'));
        // Valutová pokladna: do EET jde CZK ekvivalent dokladu přesně tak, jak se zaúčtuje
        // (total_amount = cizí částka × kurz dokladu, u DPH součet přepočtených řádků),
        // s původní částkou a kurzem dokladu jako stopou přepočtu.
        $fx = EetCurrency::fromDocument(
            $currency,
            EetValues::amount((float) $doc['total_amount']),
            ($doc['amount_foreign'] ?? null) !== null ? EetValues::amount((float) $doc['amount_foreign']) : null,
            ($doc['fx_rate'] ?? null) !== null ? (float) $doc['fx_rate'] : null,
            'cash_document:' . substr((string) ($doc['issue_date'] ?? ''), 0, 10),
        );
        $amount = $fx['amount'];
        $origin = $invoiceId !== null ? 'invoice_payment' : 'cash_document';

        $decision = $this->policy->evaluate(new EetPolicyInput(
            $settings, $saleAt, 'cash', 'in_person', $purpose, $currency, $fx['original_amount'] ?? $amount, $origin, 'payment',
        ));
        if ($decision->decision === EetPolicyDecision::UNSUPPORTED) {
            throw EetCommandException::unsupported($decision->messageCs, $decision->reasonCode);
        }
        if (!$decision->createsRecord()) {
            return null;
        }
        $deviceId = $explicitDeviceId !== null && $explicitDeviceId > 0
            ? $this->ownedDevice($supplierId, $explicitDeviceId)
            : ($this->deviceForRegister($supplierId, (int) $doc['register_id']) ?? $this->defaultDevice($supplierId, $settings));
        if ($deviceId === null) {
            throw new EetCommandException('eet_device_required',
                'Firma eviduje tržby (EET) a hotovostní příjem se musí evidovat, ale pokladna nemá přiřazené pokladní zařízení '
                . 'a firma nemá výchozí aktivní zařízení. V Nastavení EET přiřaďte pokladně zařízení, nebo zvolte zařízení u dokladu.',
                422, ['field' => 'eet_device_id']);
        }

        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => PaymentEventService::requestHash('cash-documents.post', ['cash_document_id' => $cashDocumentId]),
            'kind' => 'payment',
            'origin' => 'cash_document',
            'payment_method' => 'cash',
            'channel' => 'cash',
            'contact_context' => 'in_person',
            'business_purpose' => $purpose,
            'amount' => $amount,
            'currency' => 'CZK',
            'original_currency' => $fx['original_currency'],
            'original_amount' => $fx['original_amount'],
            'exchange_rate' => $fx['exchange_rate'],
            'exchange_rate_source' => $fx['exchange_rate_source'],
            'received_at' => EetValues::dbTime($saleAt),
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => (string) $doc['issue_date'],
            'payment_order_status' => 'settled',
            'bank_verification_status' => 'not_applicable',
            'accounting_settlement_status' => 'settled',
            'invoice_id' => $invoiceId,
            'eet_decision' => $decision->decision,
            'eet_reason_code' => $decision->reasonCode,
            'policy_version' => EetRecordingPolicy::VERSION,
            'note' => isset($doc['doc_number']) ? mb_substr((string) $doc['doc_number'], 0, 255) : null,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
        ]);
        $this->events->allocate($supplierId, $eventId, $amount, [
            'invoice_id' => $invoiceId,
            'invoice_payment_id' => $invoicePaymentId,
            'cash_document_id' => $cashDocumentId,
        ]);
        // Úhrada faktury z PPD nese podíl položek hodnotového poukazu / kreditu faktury.
        $topUp = $this->storedValue->forCashDocument($supplierId, $doc, EetValues::toCents($amount));
        $record = $this->records->create($supplierId, $eventId, $settings, [
            'device_id' => $deviceId,
            'device_field' => 'eet_device_id',
            'sale_at' => $saleAt,
            'total_amount' => $amount,
            'credit_topup_amount' => $topUp !== null ? EetValues::fromCents($topUp) : null,
            'policy_decision' => $decision->decision,
            'policy_reason' => $decision->reasonCode,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
            'now' => $now,
        ]);
        $this->db->pdo()->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
        $this->dispatch->dispatchAfterCommit($record['id']);
        return $record['id'];
    }

    /**
     * Při stornu dokladu (v jeho transakci), PŘED zrušením navázané úhrady faktury.
     * Vrací id opravy, nebo null (doklad bez tržby, tržba už opravená celá).
     *
     * @throws EetCommandException
     */
    public function afterReverse(int $supplierId, int $cashDocumentId, ?string $reason, ?int $userId, ?string $note = null, ?int $deviceId = null): ?int
    {
        if ($deviceId !== null && $deviceId > 0) {
            $deviceId = $this->ownedDevice($supplierId, $deviceId);
        }
        if ($this->refundEvents($supplierId, $cashDocumentId) !== []) {
            return $this->reverseCashRefund($supplierId, $cashDocumentId, $userId, $note, $deviceId);
        }
        $recordId = $this->saleRecordId($supplierId, $cashDocumentId);
        if ($recordId === null) {
            return null;
        }
        $reason = $reason === null || $reason === '' ? 'refund' : $reason;
        if (!in_array($reason, self::REVERSAL_REASONS, true)) {
            throw EetCommandException::validation('Neplatný důvod opravy EET při stornu.', 'eet_correction_reason',
                ['allowed' => self::REVERSAL_REASONS]);
        }
        $created = $this->corrections->createWithinTransaction(
            $supplierId, $userId, $recordId, PaymentEventService::internalKey('cash-reversal', $cashDocumentId), $reason,
            $note !== null ? mb_substr($note, 0, 255) : null, $deviceId !== null && $deviceId > 0 ? $deviceId : null,
        );
        if ($created === null) {
            return null;
        }
        // Vrácení peněz účtuje samo storno dokladu: oprava je vypořádaná a vázaná na doklad.
        $this->db->pdo()->prepare(
            "UPDATE payment_events SET accounting_settlement_status = IF(accounting_settlement_status = 'pending', 'settled', accounting_settlement_status)
              WHERE id = ? AND supplier_id = ?"
        )->execute([$created['event_id'], $supplierId]);
        $exists = $this->db->pdo()->prepare('SELECT 1 FROM payment_event_allocations WHERE supplier_id = ? AND payment_event_id = ? AND cash_document_id = ?');
        $exists->execute([$supplierId, $created['event_id'], $cashDocumentId]);
        if ($exists->fetchColumn() === false) {
            $amount = $this->db->pdo()->prepare('SELECT amount FROM payment_events WHERE id = ? AND supplier_id = ?');
            $amount->execute([$created['event_id'], $supplierId]);
            $this->events->allocate($supplierId, $created['event_id'], (string) $amount->fetchColumn(), ['cash_document_id' => $cashDocumentId]);
        }
        return $created['record_id'];
    }

    /**
     * Po zaúčtování výdajového dokladu k dobropisu (v jeho transakci): vrácení peněz
     * v hotovosti = záporné opravy evidovaných tržeb opravované faktury. Doklad, který
     * dobropis nevrací, nebo dobropis bez opravované faktury nic nezaloží. Vrací souhrn
     * oprav ({@see EetCorrectionService::refundInvoiceSales()}), nebo null.
     *
     * @param array<string,mixed> $doc řádek cash_documents (stav před označením posted)
     * @return array<string,mixed>|null
     * @throws EetCommandException
     */
    public function afterRefundPost(int $supplierId, array $doc, ?int $explicitDeviceId, ?int $userId): ?array
    {
        if (($doc['doc_type'] ?? null) !== 'out' || ($doc['purpose'] ?? null) !== 'invoice_payment' || ($doc['invoice_id'] ?? null) === null) {
            return null;
        }
        $creditNote = $this->creditNote($supplierId, (int) $doc['invoice_id']);
        if ($creditNote === null) {
            return null;
        }
        $now = EetValues::local($this->clock->now());
        $at = self::saleTime($doc, $now);
        $settings = $this->settings->get($supplierId);
        // Hotovost z pokladny je vždy kontaktní vrácení.
        if (!$this->policy->refundDecision($settings, $at, 'cash', 'in_person')->createsRecord()) {
            return null;
        }
        $cashDocumentId = (int) $doc['id'];
        $deviceId = $explicitDeviceId !== null && $explicitDeviceId > 0
            ? $this->ownedDevice($supplierId, $explicitDeviceId)
            : ($this->deviceForRegister($supplierId, (int) $doc['register_id']) ?? $this->defaultDevice($supplierId, $settings));
        return $this->corrections->refundInvoiceSales(
            $supplierId, $userId, $creditNote['parent_invoice_id'], $creditNote['currency'],
            EetValues::toCents(EetValues::amount((float) $doc['total_amount'])),
            'refund-cash:' . $cashDocumentId, $deviceId,
            isset($doc['doc_number']) ? mb_substr('Vrácení k dobropisu ' . (string) $doc['doc_number'], 0, 255) : null,
            ['invoice_id' => (int) $doc['invoice_id'], 'cash_document_id' => $cashDocumentId],
            $at,
        );
    }

    /**
     * Storno výdajového dokladu k dobropisu, ke kterému vznikly záporné opravy: peníze
     * jsou zpět v pokladně, takže se zaeviduje nová kladná tržba ve výši oprav dokladu
     * (oprava opravy v EET neexistuje). Tržba nese vazbu na doklad, dobropis i první
     * opravu (`corrects_event_id`) a patří k opravované faktuře, takže další vrácení
     * k ní ji zase může opravit. Idempotentní podle klíče dokladu.
     *
     * @throws EetCommandException
     */
    private function reverseCashRefund(int $supplierId, int $cashDocumentId, ?int $userId, ?string $note, ?int $deviceId): ?int
    {
        $key = PaymentEventService::internalKey('refund-cash-reversal', $cashDocumentId);
        $existing = $this->events->findByKey($supplierId, $key);
        if ($existing !== null) {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? AND kind = 'sale'");
            $stmt->execute([$supplierId, (int) $existing['id']]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        }
        $now = EetValues::local($this->clock->now());
        $settings = $this->settings->get($supplierId);
        if (!$this->policy->activeAt($settings, $now)) {
            return null;
        }
        $refunds = $this->refundEvents($supplierId, $cashDocumentId);
        $cents = 0;
        foreach ($refunds as $refund) {
            $cents -= EetValues::toCents(EetValues::amount((string) $refund['amount']));
        }
        if ($cents <= 0) {
            return null;
        }
        $delegation = $this->refundDelegation($supplierId, $refunds);
        $docStmt = $this->db->pdo()->prepare('SELECT register_id, invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $docStmt->execute([$cashDocumentId, $supplierId]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: ['register_id' => 0, 'invoice_id' => null];
        $deviceId = $deviceId !== null && $deviceId > 0
            ? $deviceId
            : ($this->deviceForRegister($supplierId, (int) $doc['register_id']) ?? $this->defaultDevice($supplierId, $settings));
        if ($deviceId === null) {
            throw new EetCommandException('eet_device_required',
                'Storno vrácení peněz k dobropisu se eviduje jako tržba, ale pokladna nemá přiřazené pokladní zařízení '
                . 'a firma nemá výchozí aktivní zařízení. Zvolte zařízení u storna.',
                422, ['field' => 'eet_device_id']);
        }
        $amount = EetValues::fromCents($cents);
        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => PaymentEventService::requestHash('cash-documents.refund-reversal', ['cash_document_id' => $cashDocumentId]),
            'kind' => 'payment',
            'origin' => 'cash_document',
            'payment_method' => 'cash',
            'channel' => 'cash',
            'contact_context' => 'in_person',
            'business_purpose' => 'sale',
            'amount' => $amount,
            'currency' => 'CZK',
            'received_at' => EetValues::dbTime($now),
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => $now->format('Y-m-d'),
            'payment_order_status' => 'settled',
            'bank_verification_status' => 'not_applicable',
            'accounting_settlement_status' => 'settled',
            'invoice_id' => $refunds[0]['invoice_id'] !== null ? (int) $refunds[0]['invoice_id'] : null,
            'eet_decision' => EetPolicyDecision::REQUIRED,
            'eet_reason_code' => 'refund_reversal',
            'policy_version' => EetRecordingPolicy::VERSION,
            'corrects_event_id' => (int) $refunds[0]['id'],
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
        ]);
        $this->events->allocate($supplierId, $eventId, $amount, [
            'invoice_id' => $doc['invoice_id'] !== null ? (int) $doc['invoice_id'] : null,
            'cash_document_id' => $cashDocumentId,
        ]);
        $record = $this->records->create($supplierId, $eventId, $settings, [
            'device_id' => $deviceId,
            'device_field' => 'eet_device_id',
            'sale_at' => $now,
            'total_amount' => $amount,
            // Tržba vrací zpět opravy, proto nese jejich pověření, ne pověření zařízení.
            'delegation' => $delegation,
            'policy_decision' => EetPolicyDecision::REQUIRED,
            'policy_reason' => 'refund_reversal',
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
            'now' => $now,
        ]);
        $this->db->pdo()->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
        $this->dispatch->dispatchAfterCommit($record['id']);
        return $record['id'];
    }

    /**
     * Pověření oprav, které storno vrací. Opravy různých pověření (tržby faktury přijaté
     * přes různá zařízení) by jedna kladná tržba nevrátila správně, proto se storno odmítne.
     *
     * @param list<array{id:int|string, amount:string, invoice_id:int|string|null}> $refunds
     * @throws EetCommandException
     */
    private function refundDelegation(int $supplierId, array $refunds): EetDelegation
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $refunds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT delegating_eic, multiple_delegators FROM eet_records
              WHERE supplier_id = ? AND kind = 'correction' AND payment_event_id IN ({$in})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw EetCommandException::validation(
                'Vrácení k dobropisu opravilo tržby evidované v různém pověření. Storno nejde zaevidovat jednou tržbou; '
                . 'vraťte peníze novým příjmovým dokladem na zařízení odpovídajícího pověření.',
                'eet_device_id',
            );
        }
        return $rows === [] ? EetDelegation::none() : EetDelegation::fromRecord($rows[0]);
    }

    /**
     * Záporné opravy, které vznikly zaúčtováním výdajového dokladu k dobropisu.
     *
     * @return list<array{id:int|string, amount:string, invoice_id:int|string|null}>
     */
    private function refundEvents(int $supplierId, int $cashDocumentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.amount, e.invoice_id
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id
                                    AND e.kind = 'refund' AND e.origin = 'correction'
              WHERE a.supplier_id = ? AND a.cash_document_id = ? AND e.idempotency_key LIKE ?
              ORDER BY e.id"
        );
        $stmt->execute([$supplierId, $cashDocumentId, PaymentEventService::INTERNAL_KEY_PREFIX . 'refund-cash:' . $cashDocumentId . ':%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Dobropis s opravovanou fakturou, nebo null (jiný doklad k vyplacení, dobropis bez vazby).
     *
     * @return array{parent_invoice_id:int, currency:string}|null
     */
    private function creditNote(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.invoice_type, i.parent_invoice_id, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['invoice_type'] !== 'credit_note' || (int) ($row['parent_invoice_id'] ?? 0) <= 0) {
            return null;
        }
        return ['parent_invoice_id' => (int) $row['parent_invoice_id'], 'currency' => (string) $row['currency']];
    }

    /** Má doklad evidovanou tržbu (záznam kind = sale)? */
    public function hasSale(int $supplierId, int $cashDocumentId): bool
    {
        return $this->saleRecordId($supplierId, $cashDocumentId) !== null;
    }

    private function saleRecordId(int $supplierId, int $cashDocumentId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id AND e.kind = 'payment'
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'sale'
              WHERE a.supplier_id = ? AND a.cash_document_id = ?
              ORDER BY r.id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $cashDocumentId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Okamžik tržby z dokladu, ne čas zaúčtování: doklad vystavený dnes → teď; jinak čas
     * pořízení dokladu, pokud padá do dne vystavení; jinak poledne dne vystavení.
     * Nikdy ne v budoucnosti. Pozdní evidence se neodmítá, záznam dostane lhůtu od
     * času tržby a příznak po lhůtě ({@see EetRecordFactory}).
     *
     * @param array<string,mixed> $doc
     */
    public static function saleTime(array $doc, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $now = EetValues::local($now);
        $issueDate = substr((string) ($doc['issue_date'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $issueDate) !== 1 || $issueDate === $now->format('Y-m-d')) {
            return $now;
        }
        $created = $doc['created_at'] ?? null;
        if (is_string($created) && $created !== '') {
            try {
                $createdAt = new \DateTimeImmutable($created, EetValues::zone());
                if ($createdAt->format('Y-m-d') === $issueDate) {
                    return min($createdAt, $now);
                }
            } catch (\Exception) {
            }
        }
        return min(new \DateTimeImmutable($issueDate . ' 12:00:00', EetValues::zone()), $now);
    }

    /**
     * Úhrada faktury z PPD nesmí krýt dluh, který drží potvrzený QR příkaz čekající na banku
     * (jinak dvě tržby na jedny peníze). Úhrada je v té chvíli zapsaná a faktura zamčená.
     *
     * @param array<string,mixed> $doc
     * @throws EetCommandException
     */
    private function assertNoQrOverlap(int $supplierId, int $invoiceId, array $doc): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT amount_to_pay, paid_total FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            return;
        }
        $docCents = EetValues::toCents(EetValues::amount((float) ($doc['total_amount'] ?? 0)));
        $paidAfter = EetValues::toCents(EetValues::amount((string) ($invoice['paid_total'] ?? '0')));
        $this->events->assertNoQrOverlap($supplierId, $invoiceId,
            EetValues::toCents(EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0'))),
            $paidAfter - $docCents, $docCents,
            (int) round(\MyInvoice\Service\Invoice\InvoicePaymentService::TOLERANCE * 100));
    }

    /** @param array<string,mixed> $settings */
    private function defaultDevice(int $supplierId, array $settings): ?int
    {
        $deviceId = (int) ($settings['default_device_id'] ?? 0);
        if ($deviceId <= 0) {
            return null;
        }
        // Výchozí zařízení dostává vlastní tržby; zařízení v pověření jen výslovnou volbou.
        $stmt = $this->db->pdo()->prepare("SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ? AND active = 1 AND delegation = 'none'");
        $stmt->execute([$deviceId, $supplierId]);
        return $stmt->fetchColumn() === false ? null : $deviceId;
    }

    private function deviceForRegister(int $supplierId, int $registerId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM eet_devices WHERE supplier_id = ? AND cash_register_id = ? AND active = 1 AND delegation = 'none' ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId, $registerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @throws EetCommandException */
    private function ownedDevice(int $supplierId, int $deviceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'eet_device_id');
        }
        return $deviceId;
    }

    private function invoiceType(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string) $type;
    }
}
