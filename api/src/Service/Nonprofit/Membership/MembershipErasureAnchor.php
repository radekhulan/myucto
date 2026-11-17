<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kotva pečetí výmazu v zřetězené auditní stopě (#321, krok 10).
 *
 * Pečeť (MembershipErasureSeal) se počítá z dat téhož řádku, takže kdo obejde triggery (DDL, obnova ze
 * zálohy), by mohl změnit obsah a pečeť dopočítat. Proto výmaz zapíše otisky všech pečetí člena do
 * activity_log jako jeden článek řetězu a ověřovače (historie a detail člena, předpisy a příkazy, veřejný
 * katalog, podklady J6) porovnají každý zapečetěný řádek s PRVNÍ kotvou jeho položky v pořadí řetězu
 * (seal_seq). Pravá kotva se zapečetí hned po commitu výmazu, takže později připsaná kotva se nepoužije.
 * Nezapečetěnou kotvu (těsně po commitu, nebo v transakci výmazu) uzná jen tehdy, když položka zapečetěnou
 * kotvu nemá, a jen nad hranicí odloženého pečetění; nezapečetěný řádek pod ní verify() nekontroluje.
 * Změnu kotvy odhalí ActivityLogHashChain::verify() (Diagnostika) s jeho omezeními: v promazané části
 * řetězu se návaznost nekontroluje. Akce je mezi trvale uchovávanými (RETAINED_ACTIONS).
 */
final class MembershipErasureAnchor
{
    public const ACTION = 'nonprofit_membership_erasure_sealed';
    public const ENTITY = 'nonprofit_membership_erasure_item';
    public const REVISION = 'r';
    public const OPERATION = 'o';
    public const COMMAND = 'c';

    public function __construct(private readonly Connection $db) {}

    public static function key(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /**
     * Ověří zapečetěné řádky proti kotvám jedním dotazem (na 500 položek). Nezapečetěné řádky přeskočí
     * bez dotazu, takže nevymazaní členové nic nestojí.
     *
     * @param list<array{0:string,1:array<string,mixed>}> $rows [typ, řádek s id, erasure_item_id, erased_digest]
     */
    public function assertRows(int $supplier, array $rows, string $errorCode, string $message): void
    {
        $sealed = array_values(array_filter($rows, static fn (array $pair): bool => MembershipErasureSeal::sealed($pair[1])));
        if ($sealed === []) return;
        $items = array_values(array_unique(array_map(static fn (array $pair): int => (int) $pair[1]['erasure_item_id'], $sealed)));
        $pending = $this->deferredFrom();
        $chained = $pending === null ? 'hash IS NOT NULL' : '(hash IS NOT NULL OR id >= ' . $pending . ')';
        $anchors = [];
        foreach (array_chunk($items, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $order = $this->db->hasColumn('activity_log', 'seal_seq') ? 'hash IS NULL,seal_seq,id' : 'hash IS NULL,id';
            $q = $this->db->pdo()->prepare("SELECT entity_id,payload FROM activity_log FORCE INDEX(idx_al_entity)
                WHERE entity_type=? AND entity_id IN ({$marks}) AND action=? AND supplier_id=? AND {$chained} ORDER BY {$order}");
            $q->execute([self::ENTITY, ...$chunk, self::ACTION, $supplier]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $item = (int) $row['entity_id'];
                if (isset($anchors[$item])) continue;
                $payload = json_decode((string) $row['payload'], true);
                $anchors[$item] = is_array($payload['seals'] ?? null) ? $payload['seals'] : [];
            }
        }
        foreach ($sealed as [$type, $row]) {
            $expected = $anchors[(int) $row['erasure_item_id']][self::key($type, (int) $row['id'])] ?? null;
            if (!is_string($expected) || !is_string($row['erased_digest'] ?? null) || !hash_equals($expected, $row['erased_digest'])) {
                throw new MembershipException($errorCode, $message, 409);
            }
        }
    }

    /**
     * Hranice odloženého pečetění; null = pečetí se hned (bez migrace 2055). Čte se při každém ověření,
     * ne jednou za instanci: instance žije v repozitářích po celou dobu procesu a hranice se může změnit
     * (přepečetění řetězu, testy). Jeden dotaz na primární klíč za dávku ověření.
     */
    private function deferredFrom(): ?int
    {
        if (!$this->db->hasColumn('activity_log_chain_head', 'deferred_from_id')) return null;
        $value = $this->db->pdo()->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }
}
