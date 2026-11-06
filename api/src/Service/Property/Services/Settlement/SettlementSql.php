<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
trait SettlementSql
{
    private function execute(string $sql,array $args):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private function one(string $sql,array $args):?array{return $this->execute($sql,$args)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function rows(string $sql,array $args):array{return $this->execute($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private static function json(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $data):array{return json_decode($data,true,flags:JSON_THROW_ON_ERROR);}
}
