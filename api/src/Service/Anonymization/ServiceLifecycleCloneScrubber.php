<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\{PaymentJournalProof,PaymentLocks};
use PDO;

final class ServiceLifecycleCloneScrubber
{
    public const TABLES = ['property_service_lifecycle_claims','property_service_lifecycle_events','property_service_lifecycle_cases'];
    public const COMMANDS = 'property_service_lifecycle_commands';
    private array $rows = [];
    private array $nodes = [];
    private array $resolved = [];
    private array $resolving = [];
    private array $targets = [];

    private function __construct(private readonly Connection $db, private readonly TextScrubber $scrubber, private array $hashes, private readonly \Closure $text, private readonly \Closure $map)
    {
        foreach ($hashes as $hash) $this->targets[$hash] = true;
    }

    public static function captureInputs(Connection $db): array
    {
        self::context($db);
        $owner = new ServiceLifecycleRepository($db);
        $inputs = [];
        foreach ($db->pdo()->query('SELECT supplier_id,id FROM property_service_lifecycle_cases ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $case) {
            (new PaymentLocks($db))->acquire((int) $case['supplier_id']);
            $owner->current((int) $case['supplier_id'],(int) $case['id']);
        }
        $events = $db->pdo()->query("SELECT supplier_id,request_json,receipt_json FROM property_service_lifecycle_events WHERE event_key='final' ORDER BY supplier_id,id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as $event) {
            if ($event['receipt_json'] === null) continue;
            $request = json_decode($event['request_json'],true,flags:JSON_THROW_ON_ERROR);
            $receipt = json_decode($event['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
            $supplier = (int) $event['supplier_id'];
            (new PaymentLocks($db))->acquire($supplier);
            $input = $owner->invoiceHashInput($supplier,$request['invoice_id']);
            if (PropertyInput::hash($input) !== ($receipt['invoice']['document_hash'] ?? null)) throw new \LogicException('Lifecycle clone invoice source mismatch.');
            $inputs[] = ['lifecycle_invoice'=>['supplier_id'=>$supplier,'invoice_id'=>$request['invoice_id'],'input'=>$input]];
        }
        return $inputs;
    }

    public static function run(Connection $db, TextScrubber $scrubber, array $hashes, callable $text, callable $map, array $inputs): array
    {
        self::context($db);
        $owner = new self($db,$scrubber,$hashes,\Closure::fromCallable($text),\Closure::fromCallable($map));
        $owner->capture();
        $repository = new ServiceLifecycleRepository($db);
        foreach ($inputs as $input) {
            if (!isset($input['lifecycle_invoice'])) continue;
            $invoice = $input['lifecycle_invoice'];
            $before = PropertyInput::hash($invoice['input']);
            $after = $repository->invoiceHashInput($invoice['supplier_id'],$invoice['invoice_id']);
            $owner->hashes[$before] = ($owner->map)($before,$after);
        }
        $owner->rewrite();
        $owner->verify();
        foreach (array_keys($owner->nodes) as $hash) {
            $data = $owner->transform($owner->nodes[$hash]);
            $expected = $owner->hash($hash);
            if (($owner->map)($hash,$data) !== $expected) throw new \LogicException('Lifecycle clone graph mapping mismatch.');
        }
        return $owner->resolved + $owner->hashes;
    }

    private static function context(Connection $db): void
    {
        if (!$db->pdo()->inTransaction() || TriggerMetadata::any($db->pdo())) throw new \LogicException('Lifecycle clone requires its owner transaction before trigger installation.');
    }

    private function capture(): void
    {
        foreach (self::TABLES as $table) {
            $order = $table === 'property_service_lifecycle_claims' ? 'supplier_id,line_id,claim_role' : 'supplier_id,id';
            $this->rows[$table] = $this->db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order.' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($this->rows[$table] as $row) foreach ($row as $key=>$value) {
                if (!str_ends_with($key,'_json')) continue;
                $hashKey = substr($key,0,-5).'_hash';
                if ($value === null) {
                    if (($row[$hashKey] ?? null) !== null) throw new \LogicException('Lifecycle clone null receipt hash mismatch.');
                    continue;
                }
                $data = json_decode($value,true,flags:JSON_THROW_ON_ERROR);
                if (!isset($row[$hashKey]) || PropertyInput::hash($data) !== $row[$hashKey]) throw new \LogicException('Lifecycle clone source hash mismatch.');
                $this->index($data);
            }
        }
    }

    private function index(array $data): void
    {
        $this->nodes[PropertyInput::hash($data)] = $data;
        foreach ($data as $value) if (is_array($value)) $this->index($value);
    }

    private function hash(string $old): string
    {
        if (isset($this->hashes[$old])) return $this->hashes[$old];
        if (isset($this->resolved[$old])) return $this->resolved[$old];
        if (!isset($this->nodes[$old])) {
            if (isset($this->targets[$old])) return $old;
            throw new \LogicException('Lifecycle clone hash source missing.');
        }
        if (isset($this->resolving[$old])) throw new \LogicException('Lifecycle clone hash cycle.');
        $this->resolving[$old] = true;
        $new = PropertyInput::hash($this->transform($this->nodes[$old]));
        unset($this->resolving[$old]);
        return $this->resolved[$old] = $new;
    }

    private function transform(array $data): array
    {
        $data = ($this->text)($data);
        foreach ($data as $key=>&$value) {
            if (is_array($value)) $value = $this->transform($value);
            elseif (is_string($value) && in_array((string) $key,['title','coverage_title'],true)) $value = $this->scrubber->scrub($value);
            elseif (is_string($value) && str_ends_with((string) $key,'_hash') && preg_match('/^[a-f0-9]{64}$/D',$value)) $value = $this->hash($value);
        }
        unset($value);
        return $data;
    }

    private function rewrite(): void
    {
        foreach (['property_service_lifecycle_cases','property_service_lifecycle_events'] as $table) foreach ($this->rows[$table] as $row) {
            $changes = [];
            foreach ($row as $key=>$value) {
                if (!str_ends_with($key,'_json') || $value === null) continue;
                $data = $this->transform(json_decode($value,true,flags:JSON_THROW_ON_ERROR));
                $hashKey = substr($key,0,-5).'_hash';
                $changes[$key] = json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
                $changes[$hashKey] = ($this->map)($row[$hashKey],$data);
                if ($changes[$hashKey] !== $this->hash($row[$hashKey])) throw new \LogicException('Lifecycle clone materialization mismatch.');
            }
            if ($changes !== []) $this->db->pdo()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($changes))).' WHERE supplier_id=? AND id=?')->execute([...array_values($changes),$row['supplier_id'],$row['id']]);
        }
    }

    private function verify(): void
    {
        $repository = new ServiceLifecycleRepository($this->db);
        foreach ($this->rows['property_service_lifecycle_cases'] as $case) {
            $supplier = (int) $case['supplier_id'];
            (new PaymentLocks($this->db))->acquire($supplier);
            $repository->current($supplier,(int) $case['id']);
        }
        foreach ($this->rows['property_service_lifecycle_events'] as $event) {
            if ($event['receipt_json'] === null) continue;
            $q = $this->db->pdo()->prepare('SELECT receipt_json FROM property_service_lifecycle_events WHERE supplier_id=? AND id=? FOR UPDATE');
            $q->execute([$event['supplier_id'],$event['id']]);
            $receipt = json_decode($q->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
            if (isset($receipt['ledger'])) {
                $ledger = (new PaymentJournalProof($this->db))->current((int) $event['supplier_id'],(int) $event['journal_entry_id']);
                if (PropertyInput::hash($ledger) !== PropertyInput::hash($receipt['ledger'])) throw new \LogicException('Lifecycle clone ledger mismatch.');
            }
        }
    }
}
