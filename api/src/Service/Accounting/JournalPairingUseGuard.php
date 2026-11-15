<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Řádek zápisu spárovaný v okruhu otevřených položek (`journal_line_pairing_items`,
 * migrace 1895) se nesmí ztratit smazáním zápisu. Položky okruhu visí na zápisu cizím
 * klíčem s kaskádou, takže smazání zápisu by je tiše odneslo a protějšky v okruhu by
 * zůstaly spárované s řádkem, který už neexistuje.
 *
 * Jedna kontrola pro všechna mazání zápisu bez protizápisu: deník (jeden zápis i storno
 * dvojice), doklad mazaný i se zápisem ({@see DocumentJournalPurge}) a pokladní doklad.
 * Storno sem nepatří: protizápis okruhy originálu uvolní sám s auditní stopou
 * ({@see \MyInvoice\Repository\JournalLinePairingRepository::releaseEntry()}).
 *
 * Blokuje jen okruh, ve kterém po smazání zůstane řádek jiného zápisu. Okruh celý uvnitř
 * mazané sady (faktura spárovaná se svým stornem, storno dvojice spárovaná mezi sebou)
 * zmizí celý a nic se neztratí; zbytek okruhu o jediném řádku čtení bere jako nespárovaný
 * a uživatel ho v opisu účtu ani nevidí. Prázdnou hlavičku okruhu uklidí
 * {@see \MyInvoice\Repository\JournalLinePairingRepository::dissolveDegenerate()} při
 * nejbližším párování.
 */
final class JournalPairingUseGuard
{
    public const CODE = 'entry_paired';

    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $entryIds zápisy mazané v jednom kroku */
    public function assertEntriesFree(int $supplierId, array $entryIds): void
    {
        $hit = $this->pairedEntry($supplierId, $entryIds);
        if ($hit !== null) {
            $block = self::block($hit);
            throw new PostingException($block['code'], $block['message'], 409, [
                'entry_id'   => $hit['entry_id'],
                'pairing_id' => $hit['pairing_id'],
                'account_id' => $hit['account_id'],
            ]);
        }
    }

    /**
     * První zápis sady, jehož řádek leží v okruhu s řádkem zápisu mimo sadu.
     *
     * @param list<int> $entryIds
     * @return array{entry_id:int, pairing_id:int, account_id:int, account_code:string}|null
     */
    public function pairedEntry(int $supplierId, array $entryIds): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.entry_id, i.pairing_id, p.account_id, ca.account_code
               FROM journal_line_pairing_items i
               JOIN journal_line_pairings p ON p.supplier_id = i.supplier_id AND p.id = i.pairing_id
               JOIN chart_of_accounts ca    ON ca.supplier_id = p.supplier_id AND ca.id = p.account_id
              WHERE i.supplier_id = ? AND i.entry_id IN ({$in})
                AND EXISTS (SELECT 1 FROM journal_line_pairing_items o
                             WHERE o.supplier_id = i.supplier_id AND o.pairing_id = i.pairing_id
                               AND o.entry_id NOT IN ({$in}))
              ORDER BY i.entry_id, i.line_no
              LIMIT 1"
        );
        $stmt->execute([$supplierId, ...$ids, ...$ids]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'entry_id'     => (int) $row['entry_id'],
            'pairing_id'   => (int) $row['pairing_id'],
            'account_id'   => (int) $row['account_id'],
            'account_code' => (string) $row['account_code'],
        ];
    }

    /**
     * @param array{entry_id:int, pairing_id:int, account_id:int, account_code:string} $hit
     * @return array{code:string, message:string}
     */
    public static function block(array $hit): array
    {
        return [
            'code'    => self::CODE,
            'message' => 'Řádek zápisu na účtu ' . $hit['account_code'] . ' je spárovaný s jiným zápisem v okruhu otevřených položek. '
                . 'Nejdřív ho z okruhu odeberte nebo okruh zrušte (opis účtu ' . $hit['account_code']
                . ', záložka Otevřené položky - párování), pak mazání opakujte.',
        ];
    }
}
