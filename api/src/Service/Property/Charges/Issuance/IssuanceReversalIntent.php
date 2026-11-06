<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final readonly class IssuanceReversalIntent
{
    private function __construct(public int $supplierId,public int $intentId,public int $chargeId,
        public int $sourceEntryId,public int $expectedVersion,public int $targetPeriodId,
        public string $accountingDate,public string $description,public int $actor,
        private PDO $pdo, private array $charge) {}

    private function __clone() {}

    public function __serialize(): array { throw new \LogicException('Issued reversal proof cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Issued reversal proof cannot be restored.'); }

    public static function fromCurrentRequest(Connection $db,int $supplier,int $intentId): self
    {
        $intent=self::fromRequest($db,$supplier,$intentId);
        try{(new \MyInvoice\Service\Property\Payments\PaymentUseGuard($db))->assertChargeCorrectable($supplier,$intent->chargeId);
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($db))->assertChargeMutable($supplier,$intent->chargeId);}
        catch(\MyInvoice\Service\Property\Payments\PaymentException $e){throw new IssuanceException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
        return $intent;
    }

    public static function fromPaymentCorrection(Connection $db,\MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent $correction):self
    {
        $request=$correction->reversalRequest($db);$supplier=$correction->supplierId;$charge=$correction->chargeId;$actor=$correction->actor;
        $token='payment-correction-'.$correction->correctionId;
        $hash=PropertyInput::hash(['charge_id'=>$charge,'request'=>$request,'idempotency_key'=>$token,'actor'=>$actor]);
        $db->pdo()->prepare('INSERT INTO unit_charge_adjustments (supplier_id,charge_id,adjustment_charge_id,kind,journal_entry_id,effective_at,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,NULL,\'reversal\',NULL,?,?,?,?,?,UTC_TIMESTAMP(6))')
            ->execute([$supplier,$charge,PropertyInstant::sql($request['effective_at']),IssuanceRequest::json($request),$hash,$token,$actor]);
        return self::fromRequest($db,$supplier,(int)$db->pdo()->lastInsertId());
    }

    private static function fromRequest(Connection $db,int $supplier,int $intentId):self
    {
        if(!$db->pdo()->inTransaction()) throw new \LogicException('Property reversal proof requires its owner transaction.');
        $locks=new IssuanceLocks($db); $locks->acquire($supplier);
        (new IssuanceUseGuard($db))->lockClaims($supplier);
        $stmt=$db->pdo()->prepare('SELECT * FROM unit_charge_adjustments WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$supplier,$intentId]); $intent=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$intent||$intent['kind']!=='reversal'||$intent['adjustment_charge_id']!==null||$intent['journal_entry_id']!==null) self::invalid();
        $request=json_decode($intent['request_json'],true,flags:JSON_THROW_ON_ERROR);
        $chargeId=(int)$intent['charge_id']; $actor=(int)$intent['created_by'];
        IssuanceReadRepository::hash($intent['request_hash'],['charge_id'=>$chargeId,'request'=>$request,'idempotency_key'=>$intent['idempotency_key'],'actor'=>$actor]);
        if($intent['effective_at']!==PropertyInstant::sql($request['effective_at'])) self::invalid();
        $charge=(new IssuanceReadRepository($db))->find($supplier,$chargeId,true);
        if($charge['status']!=='posted'||$charge['row_version']!==$request['row_version']||$charge['journal_entry_id']===null
            ||$request['accounting_date']<$charge['accounting_date']||$request['effective_at']<$charge['snapshot']['issued_at']) self::invalid();
        $stmt=$db->pdo()->prepare("SELECT id FROM unit_charges WHERE supplier_id=? AND unit_id=? AND source_key=? AND revision_no>? AND status IN ('draft','posted') ORDER BY id FOR UPDATE");
        $stmt->execute([$supplier,$charge['unit_id'],$charge['source_key'],$charge['revision_no']]);
        if($stmt->fetchColumn()!==false) self::invalid();
        $locks->assertFramework($supplier,$charge['accounting_date']); $locks->assertFramework($supplier,$request['accounting_date']);
        $stmt=$db->pdo()->prepare("SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? AND status='open' ORDER BY starts_on,id FOR UPDATE");
        $stmt->execute([$supplier,$request['accounting_date'],$request['accounting_date']]); $periods=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if(count($periods)!==1) throw new \MyInvoice\Service\Accounting\PostingException('period_not_open','Storno předpisu vyžaduje otevřené cílové období.',409);
        $stmt=$db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE'); $stmt->execute([$supplier]); $locked=$stmt->fetchColumn();
        if(is_string($locked)&&$locked!==''&&$request['accounting_date']<=$locked) throw new \MyInvoice\Service\Accounting\PostingException('date_locked','Datum storna předpisu je uzamčené.',409);
        (new IssuanceJournalProof($db))->assertEntry($supplier,$charge['journal_entry_id'],$charge);
        return new self($supplier,$intentId,$chargeId,$charge['journal_entry_id'],$charge['row_version'],(int)$periods[0],
            $request['accounting_date'],PropertyInput::text($request['reason'],'reason',2000),$actor,$db->pdo(),$charge);
    }

    public function complete(Connection $db, int $reversalId): void
    {
        if ($db->pdo() !== $this->pdo || !$this->pdo->inTransaction()) throw new \LogicException('Issued reversal proof requires its original transaction.');
        (new IssuanceJournalProof($db))->assertEntry($this->supplierId, $reversalId, $this->charge, true);
        $stmt = $this->pdo->prepare("UPDATE unit_charges SET status='reversed',reversal_entry_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='posted' AND journal_entry_id=? AND reversal_entry_id IS NULL");
        $stmt->execute([$reversalId, $this->supplierId, $this->chargeId, $this->expectedVersion, $this->sourceEntryId]);
        if ($stmt->rowCount() !== 1) self::invalid();
        $payload = ['adjustment_id' => $this->intentId, 'reason' => $this->description,
            'snapshot_hash' => $this->charge['snapshot_hash'], 'reversal_entry_id' => $reversalId];
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('INSERT INTO unit_charge_issue_audit (supplier_id,batch_id,charge_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$this->supplierId, $this->charge['batch_id'], $this->chargeId, 'reversed', IssuanceRequest::json($payload), PropertyInput::hash($payload), $this->actor, $now]);
    }

    private static function invalid(): never
    {
        throw new IssuanceException('property_charge_issue_invalid_reversal_intent','Požadavek storna neodpovídá aktuálnímu předpisu a otevřenému cílovému období.',409);
    }
}
