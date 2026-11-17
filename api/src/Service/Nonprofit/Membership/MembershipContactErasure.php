<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kontakt vymazaného člena (#321, krok 10).
 *
 * Kontakt, který nepoužívá nic jiného než členská evidence, se anonymizuje na místě:
 * `client_id` zůstane (je v hashi pohledávek), zmizí jméno, adresa, e-mail, telefon,
 * identifikátory, bankovní účty a e-mailové kontakty. Kontakt, na kterém stojí jiný doklad
 * (faktura, zakázka, ostatní položka, jiný modul…), drží ten doklad s vlastní lhůtou, proto
 * zůstane beze změny a doklad o výmazu ho jen započítá.
 *
 * Kdo kontakt používá, se zjišťuje z cizích klíčů v databázi, aby ho nový modul s vazbou
 * na kontakt nepřehlédl; vazby bez cizího klíče jsou vyjmenované níže.
 */
final class MembershipContactErasure
{
    public const ANONYMIZED = 'anonymized';
    public const RETAINED = 'retained';

    /** Data samotného kontaktu: při anonymizaci se smažou, nejsou důvodem kontakt držet. */
    private const OWN_TABLES = ['client_bank_accounts', 'client_email_contacts', 'client_revenue_cache', 'bank_counterparty_map'];
    /** Členská evidence; vazba na vymazaného člena kontakt nedrží. */
    private const MEMBERSHIP_TABLES = ['nonprofit_members', 'nonprofit_membership_charge_claims'];
    /** Vazby na kontakt bez cizího klíče. */
    private const UNCONSTRAINED = [['invoice_counters', 'client_id'], ['work_report_links', 'client_id']];

    /** @var list<array{0:string,1:string}>|null */
    private ?array $references = null;
    /** @var array<string,true>|null */
    private ?array $tenantTables = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{contact:string, other_uses:list<string>}
     */
    public function erase(int $supplier, int $client, int $member): array
    {
        $q = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $client]);
        if ($q->fetchColumn() === false) return ['contact' => self::RETAINED, 'other_uses' => ['missing']];
        $uses = $this->otherUses($supplier, $client, $member);
        if ($uses !== []) return ['contact' => self::RETAINED, 'other_uses' => $uses];
        // Párovací mapa banky odkazuje na bankovní účty kontaktu; maže se dřív než účty. Kontakt je výše
        // ověřený a zamčený pro firmu; client_email_contacts a client_revenue_cache sloupec firmy nemají.
        foreach (['bank_counterparty_map', 'client_bank_accounts', 'client_email_contacts', 'client_revenue_cache'] as $table) {
            $this->db->pdo()->prepare("DELETE FROM {$table} WHERE client_id=?")->execute([$client]);
        }
        $this->db->pdo()->prepare("UPDATE clients SET company_name=?,first_name=NULL,last_name=NULL,ic=NULL,dic=NULL,tax_number=NULL,
                street='',city='',zip='',main_email=NULL,phone=NULL,note=NULL,related_party=0,related_party_note=NULL,related_party_type=NULL,
                fakturoid_id=NULL,idoklad_id=NULL,archived_at=COALESCE(archived_at,CURRENT_TIMESTAMP)
            WHERE supplier_id=? AND id=?")->execute([self::placeholderName($client), $supplier, $client]);
        return ['contact' => self::ANONYMIZED, 'other_uses' => []];
    }

    /** Náhradní název je deterministický a neidentifikující; název kontaktu je povinný. */
    public static function placeholderName(int $client): string
    {
        return 'Vymazaný kontakt #' . $client;
    }

    /**
     * Tabulky, které kontakt používají mimo členskou evidenci a jeho vlastní data. Jeden dotaz
     * se samostatným EXISTS pro každou vazbu (po indexu cizího klíče).
     *
     * @return list<string>
     */
    private function otherUses(int $supplier, int $client, int $member): array
    {
        $parts = [];
        $params = [];
        foreach ($this->references() as $index => [$table, $column]) {
            if ($table === 'nonprofit_members') {
                $parts[] = "EXISTS(SELECT 1 FROM nonprofit_members WHERE supplier_id=? AND client_id=? AND id<>?) AS u{$index}";
                array_push($params, $supplier, $client, $member);
            } elseif ($table === 'nonprofit_membership_charge_claims') {
                $parts[] = "EXISTS(SELECT 1 FROM nonprofit_membership_charge_claims WHERE supplier_id=? AND client_id=? AND member_id<>?) AS u{$index}";
                array_push($params, $supplier, $client, $member);
            } elseif ($table === 'document_links') {
                // Dokument připojený ke kontaktu (např. sken přihlášky) nese osobní údaj a kontakt drží.
                $parts[] = "EXISTS(SELECT 1 FROM document_links FORCE INDEX(idx_dl_supplier_entity_document) WHERE supplier_id=? AND entity_type='client' AND entity_id=?) AS u{$index}";
                array_push($params, $supplier, $client);
            } elseif ($this->hasSupplier($table)) {
                // Index cizího klíče začíná u složeného klíče firmou; s firmou dotaz index využije vždy.
                $parts[] = "EXISTS(SELECT 1 FROM `{$table}` WHERE supplier_id=? AND `{$column}`=?) AS u{$index}";
                array_push($params, $supplier, $client);
            } else {
                $parts[] = "EXISTS(SELECT 1 FROM `{$table}` WHERE `{$column}`=?) AS u{$index}";
                $params[] = $client;
            }
        }
        $q = $this->db->pdo()->prepare('SELECT ' . implode(',', $parts));
        $q->execute($params);
        $row = $q->fetch(PDO::FETCH_NUM);
        $uses = [];
        foreach ($this->references() as $index => [$table]) if ((int) $row[$index] === 1) $uses[] = $table;
        return array_values(array_unique($uses));
    }

    /** Má tabulka sloupec firmy? Zjišťuje se jednou za instanci pro všechny vazby. */
    private function hasSupplier(string $table): bool
    {
        if ($this->tenantTables === null) {
            $q = $this->db->pdo()->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='supplier_id'");
            $this->tenantTables = array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        return isset($this->tenantTables[$table]);
    }

    /** @return list<array{0:string,1:string}> */
    private function references(): array
    {
        if ($this->references !== null) return $this->references;
        $q = $this->db->pdo()->query("SELECT k.TABLE_NAME,k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='clients' AND k.REFERENCED_COLUMN_NAME='id'
            ORDER BY k.TABLE_NAME,k.COLUMN_NAME");
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            if (in_array($table, self::OWN_TABLES, true)) continue;
            $out[$table . '.' . $column] = [(string) $table, (string) $column];
        }
        foreach (self::UNCONSTRAINED as [$table, $column]) $out[$table . '.' . $column] = [$table, $column];
        $out['document_links.entity_id'] = ['document_links', 'entity_id'];
        foreach (self::MEMBERSHIP_TABLES as $table) $out[$table . '.client_id'] = [$table, 'client_id'];
        return $this->references = array_values($out);
    }
}
