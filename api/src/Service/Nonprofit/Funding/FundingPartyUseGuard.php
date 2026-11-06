<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding;
use MyInvoice\Infrastructure\Database\Connection;
final class FundingPartyUseGuard
{
    public function __construct(private readonly Connection $db) {}
    public function inUse(int $supplier,int $client):bool
    {
        $q=$this->db->pdo()->prepare('SELECT 1 FROM nonprofit_funding_revisions WHERE supplier_id=? AND provider_id=? LIMIT 1');
        $q->execute([$supplier,$client]);return $q->fetchColumn()!==false;
    }
}
