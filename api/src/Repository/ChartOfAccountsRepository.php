<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro chart_of_accounts — účtová osnova per firma (Epic F1).
 * Per tenant (supplier_id), UNIQUE (supplier_id, account_code).
 */
final class ChartOfAccountsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeInactive = false, ?string $date = null): array
    {
        $rows=(new AccountDefinitionRepository($this->db))->listForDate($supplierId,$date??date('Y-m-d'));
        usort($rows,fn($a,$b)=>strcmp($a['account_code'],$b['account_code']));
        return array_values(array_map(fn($row)=>$this->project($row),array_filter($rows,fn($row)=>$includeInactive||(bool)$row['is_active'])));
    }

    public function findByCode(int $supplierId, string $code, ?string $date = null): ?array
    {
        // Kód porovnává databáze (collation sloupce, unikátní index) stejně jako před
        // historií definic; k datu se dočte jen nalezený účet, ne celá osnova.
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $row = (new AccountDefinitionRepository($this->db))->findForDate($supplierId, (int) $id, $date ?? date('Y-m-d'));
        return $row === null ? null : $this->project($row);
    }

    /**
     * Mapa account_code => {id, is_active, account_type} pro suppliera — používá
     * PostingService k překladu CODE z posting_rules na account_id a k validaci
     * účtu (aktivní / typ) přímo v resolveLines. Ostatní volající (existenční
     * kontrola přes isset) rozšíření hodnoty nevadí.
     *
     * @return array<string, array{id:int, is_active:bool, account_type:string}>
     */
    public function codeToIdMap(int $supplierId, ?string $date = null, bool $currentRead = false): array
    {
        $map=[];
        foreach((new AccountDefinitionRepository($this->db))->listForDate($supplierId,$date??date('Y-m-d'),$currentRead) as $r)$map[$r['account_code']]=['id'=>(int)$r['id'],'is_active'=>(bool)$r['is_active'],'account_type'=>$r['account_type']];
        return $map;
    }

    public function physicalCodeMap(int $supplierId): array
    {
        $q=$this->db->pdo()->prepare('SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=?');$q->execute([$supplierId]);return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function codeExists(int $supplierId,string $code,bool $currentRead=false): bool
    {
        $q=$this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=?'.($currentRead?' FOR UPDATE':''));$q->execute([$supplierId,$code]);return $q->fetchColumn()!==false;
    }

    public function count(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $supplierId, int $id, ?string $date = null): ?array
    {
        $row=(new AccountDefinitionRepository($this->db))->findForDate($supplierId,$id,$date??date('Y-m-d'));
        return $row===null?null:$this->project($row,true);
    }

    /**
     * Analytiky pod syntetickým účtem (přímé děti) — karta účtu (drill-through
     * syntetika → analytiky). Neaktivní se vrací taky, aby zůstatek z historie
     * nezmizel; příznak `is_active` si zobrazí volající.
     *
     * @return list<array<string,mixed>>
     */
    public function childrenOf(int $supplierId, int $parentId, ?string $date = null): array
    {
        $rows=(new AccountDefinitionRepository($this->db))->listForDate($supplierId,$date??date('Y-m-d'));
        usort($rows,fn($a,$b)=>strcmp($a['account_code'],$b['account_code']));
        return array_values(array_map(fn($row)=>$this->project($row),array_filter($rows,fn($row)=>(int)($row['parent_id']??0)===$parentId)));
    }

    /**
     * Vloží účet (analytiku i syntetiku) do osnovy firmy. Vrací id.
     *
     * @param array{account_code:string, name:string, account_type:string,
     *              normal_side:?string, is_synthetic?:bool, parent_id?:?int, is_active?:bool,
     *              tax_deductibility?:string} $data
     */
    public function insert(int $supplierId, array $data, ?string $effectiveFrom = null, ?int $actor = null): int
    {
        $definitions=new AccountDefinitionRepository($this->db);
        $insert=function()use($supplierId,$data):int {
            $pdo = $this->db->pdo();
            $pdo->prepare(
                'INSERT INTO chart_of_accounts
                    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $data['account_code'],
                $data['name'],
                $data['account_type'],
                $data['normal_side'],
                (int) ($data['is_synthetic'] ?? false),
                $data['parent_id'] ?? null,
                (int) ($data['is_active'] ?? true),
                $data['tax_deductibility'] ?? 'deductible',
            ]);
            return (int) $pdo->lastInsertId();

        };
        return $definitions->transaction($supplierId,function()use($insert,$definitions,$supplierId,$effectiveFrom,$actor,$data){$historical=$definitions->hasHistory($supplierId,null,true);if($historical&&$this->codeExists($supplierId,$data['account_code'],true))throw new \MyInvoice\Service\Accounting\AccountDefinitionException('account_definition_code_exists');$id=$insert();if($historical)$definitions->initializeCreated($supplierId,$id,$effectiveFrom??date('Y-m-d'),$actor);return $id;});
    }

    /**
     * Přejmenuje / (de)aktivuje účet. Účty se NEmažou (§ auditní stopa), jen deaktivují.
     *
     * @param array{name?:string, is_active?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data, ?string $effectiveFrom = null, ?string $expectedHash = null, ?int $actor = null): bool
    {
            return (new AccountDefinitionRepository($this->db))->transaction($supplierId,function()use($supplierId,$id,$data,$effectiveFrom,$expectedHash,$actor) {

            $definitions=new AccountDefinitionRepository($this->db);
            if($definitions->hasHistory($supplierId,null,true))return $definitions->transaction($supplierId,function()use($definitions,$supplierId,$id,$data,$effectiveFrom,$expectedHash,$actor){$before=$definitions->stateHash($supplierId,$id,true);$after=$definitions->change($supplierId,$id,$effectiveFrom??date('Y-m-d'),array_intersect_key($data,['name'=>true,'is_active'=>true]),$expectedHash??$before,$actor,'account_configuration');return $before!==$after;});
            $sets = [];
            $params = [];
            if (array_key_exists('name', $data)) {
                $sets[] = 'name = ?';
                $params[] = $data['name'];
            }
            if (array_key_exists('is_active', $data)) {
                $sets[] = 'is_active = ?';
                $params[] = (int) $data['is_active'];
            }
            if ($sets === []) {
                return false;
            }
            $params[] = $id;
            $params[] = $supplierId;
            $stmt = $this->db->pdo()->prepare(
                'UPDATE chart_of_accounts SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        });
    }

    /** Daňový příznak převzatého výsledkového účtu. */
    public function setTaxDeductibility(int $supplierId, int $id, string $value, ?string $effectiveFrom = null): void
    {
            (new AccountDefinitionRepository($this->db))->transaction($supplierId,function()use($supplierId,$id,$value,$effectiveFrom) {

            $definitions=new AccountDefinitionRepository($this->db);
            if($definitions->hasHistory($supplierId,null,true)){$definitions->transaction($supplierId,function()use($definitions,$supplierId,$id,$value,$effectiveFrom){$definitions->change($supplierId,$id,$effectiveFrom??date('Y-m-d'),['tax_deductibility'=>$value],$definitions->stateHash($supplierId,$id,true),null,'account_tax_configuration');});return;}
            $this->db->pdo()->prepare('UPDATE chart_of_accounts SET tax_deductibility = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$value, $id, $supplierId]);
        });
    }

    /**
     * Smaže účet. Volající MUSÍ nejdřív ověřit, že na něm nic nevisí
     * ({@see \MyInvoice\Service\Accounting\ChartAccountUsage}) — repository
     * jen provede výmaz.
     */
    public function delete(int $supplierId, int $id): bool
    {
            return (new AccountDefinitionRepository($this->db))->transaction($supplierId,function()use($supplierId,$id) {

            if((new AccountDefinitionRepository($this->db))->hasHistory($supplierId,$id,true))throw new \MyInvoice\Service\Accounting\AccountDefinitionException('account_definition_history_in_use');
            $stmt = $this->db->pdo()->prepare('DELETE FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$id, $supplierId]);
            return $stmt->rowCount() > 0;
        });
    }

    /**
     * Účtová osnova jako strom: kořeny (parent_id NULL) s vnořenými `children`.
     *
     * @return list<array<string,mixed>>
     */
    public function tree(int $supplierId, bool $includeInactive = false, ?string $date = null): array
    {
        $flat = $this->listForTenant($supplierId, $includeInactive, $date);
        $byId = [];
        foreach ($flat as $row) {
            $row['children'] = [];
            $byId[(int) $row['id']] = $row;
        }
        $roots = [];
        foreach ($byId as $id => $_) {
            $parentId = $byId[$id]['parent_id'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }
        return $roots;
    }

    /**
     * Mapa id => {code, name, account_type, tax_deductibility} pro obohacení řádků deníku
     * o kód/název účtu. Typ a daňová uznatelnost slouží
     * {@see \MyInvoice\Service\Accounting\TaxNeutralReclassification}.
     *
     * @return array<int,array{code:string,name:string,account_type:string,tax_deductibility:string}>
     */
    public function idToAccountMap(int $supplierId, ?string $date = null, bool $currentRead = false): array
    {
        $map=[];
        foreach((new AccountDefinitionRepository($this->db))->listForDate($supplierId,$date??date('Y-m-d'),$currentRead) as $r)$map[(int)$r['id']]=['code'=>$r['account_code'],'name'=>$r['name'],'account_type'=>$r['account_type'],'tax_deductibility'=>$r['tax_deductibility']];
        return $map;
    }

    private function project(array $row,bool $tax=false): array
    {
        $keys=['id','supplier_id','account_code','name','account_type','normal_side','is_synthetic','parent_id','is_active'];if($tax)$keys[]='tax_deductibility';$keys[]='created_at';$out=[];foreach($keys as $key)$out[$key]=$row[$key];return $this->cast($out);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['is_synthetic'] = (bool) $r['is_synthetic'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
        return $r;
    }
}
