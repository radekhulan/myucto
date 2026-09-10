<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use PDO;

/**
 * Doplnění `bank_transactions.card_last4` u pohybů importovaných před zavedením
 * sloupce (maskované číslo karty zůstalo jen v textu popisu).
 *
 * Proč PHP backfill a ne UPDATE v migraci: koncovku rozpoznává jediná třída
 * {@see CardNumberMask}. Migrace by musela pravidlo zopakovat v SQL a obě verze by
 * se časem rozešly — nový formát výpisu by se naučila jen jedna z nich. SQL tu
 * slouží jen jako předvýběr (MariaDB REGEXP je PCRE, vzor je sdílený), rozhoduje PHP.
 *
 * Idempotentní: bere jen `card_last4 IS NULL` a zapisuje s touž podmínkou. Pohyb se
 * dvěma různými koncovkami v textu zůstane prázdný (nejednoznačný) a do počtu
 * „k doplnění" se nepočítá, takže auto-backfill v migrate.php se nespouští dokola.
 */
final class CardLast4Backfill
{
    private const BATCH = 500;

    public function __construct(private readonly PDO $pdo) {}

    /** Kolik pohybů by backfill doplnil. */
    public function pending(): int
    {
        $count = 0;
        $this->walk(static function () use (&$count): void {
            $count++;
        });
        return $count;
    }

    /**
     * @return array{found:int, updated:int}
     */
    public function run(bool $apply, ?callable $onRow = null): array
    {
        $update = $this->pdo->prepare('UPDATE bank_transactions SET card_last4 = ? WHERE id = ? AND card_last4 IS NULL');
        $found = 0;
        $updated = 0;
        $this->walk(function (int $id, string $last4) use ($apply, $update, $onRow, &$found, &$updated): void {
            $found++;
            if ($onRow !== null) {
                $onRow($id, $last4);
            }
            if ($apply) {
                $update->execute([$last4, $id]);
                $updated += $update->rowCount();
            }
        });
        return ['found' => $found, 'updated' => $updated];
    }

    /** @param callable(int, string): void $visit */
    private function walk(callable $visit): void
    {
        $select = $this->pdo->prepare(
            "SELECT id, counterparty_name, description
               FROM bank_transactions
              WHERE card_last4 IS NULL
                AND id > ?
                AND CONCAT_WS(' ', counterparty_name, description) REGEXP ?
              ORDER BY id
              LIMIT " . self::BATCH
        );
        $lastId = 0;
        do {
            $select->execute([$lastId, CardNumberMask::MASKED_PATTERN]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $last4 = CardNumberMask::last4FromText(
                    $row['counterparty_name'] !== null ? (string) $row['counterparty_name'] : null,
                    $row['description'] !== null ? (string) $row['description'] : null,
                );
                if ($last4 !== null) {
                    $visit($lastId, $last4);
                }
            }
        } while (count($rows) === self::BATCH);
    }
}
