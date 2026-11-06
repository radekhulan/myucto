<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use PDO;
final class PaymentPostingDate
{
    public function __construct(private readonly Connection $db){}
    /** Kontrola se v jedné transakci opakuje jen po zápisu ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}). */
    public function assertOpen(int $supplier,string $date):void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Payment date proof requires owner locks.');
        \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'payment-date|'.$supplier.'|'.$date,function()use($supplier,$date):bool{$this->assertOpenNow($supplier,$date);return true;});
    }
    private function assertOpenNow(int $supplier,string $date):void
    {
        (new IssuanceLocks($this->db))->assertFramework($supplier,$date);
        $q=$this->db->pdo()->prepare("SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? AND status='open' ORDER BY starts_on,id FOR UPDATE");$q->execute([$supplier,$date,$date]);if(count($q->fetchAll(PDO::FETCH_COLUMN))!==1)PaymentException::reject('target_period_not_open');
        $q=$this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');$q->execute([$supplier]);$locked=$q->fetchColumn();if(is_string($locked)&&$locked!==''&&$date<=$locked)PaymentException::reject('target_date_locked');
    }
}
