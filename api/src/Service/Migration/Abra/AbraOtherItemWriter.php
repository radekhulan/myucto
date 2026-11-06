<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PDO;

/**
 * Ostatní pohledávky a závazky ABRA Flexi (`pohledavka`, `zavazek`) mimo DPH jako doklady
 * agendy Ostatní pohledávky a závazky, ne jako faktury. Mzdy, splátky úvěrů, odvody nebo
 * závazky ke společníkům jinak nafukují náklady a saldo přijatých faktur.
 *
 * Doklad s jedním vlastním zápisem převedeného deníku ho převezme i s kontací (bez nového
 * účtování). Doklad, jehož zápis nemění účty (třeba splátkový kalendář 379/379), nebo
 * doklad minulého roku, který je v deníku jen počátečním stavem, se převede jako potvrzený
 * bez vlastního zápisu: v agendě je vidět a páruje úhrady, ale znovu se neúčtuje.
 * Předpisy mezd, odvodů a DPH ({@see MigratedOtherItemPolicy::moduleOwned()}) zůstávají
 * jen v deníku, stejně jako u převodu z POHODY.
 */
final class AbraOtherItemWriter
{
    public const ROUTE_DOCUMENT = 'document';
    public const ROUTE_OTHER_ITEM = 'other_item';
    public const ROUTE_MODULE = 'module';
    public const ROUTE_REVIEW = 'review';

    public function __construct(private readonly Connection $db) {}

    /**
     * Kam patří doklad: faktura (`document`), ostatní položka, předpis modulu mezd a daní
     * (`module`), nebo jen deník s upozorněním (`review`). Závazek s DPH zůstává přijatou
     * fakturou, pohledávka s DPH se nepřevádí.
     *
     * @param array<string,mixed> $plan
     */
    public static function route(array $plan): string
    {
        $evidence = (string) $plan['evidence'];
        if (!in_array($evidence, ['zavazek', 'pohledavka'], true)) {
            return self::ROUTE_DOCUMENT;
        }
        if (MigratedOtherItemPolicy::moduleOwned((string) ($plan['settlement_account'] ?? ''))) {
            return self::ROUTE_MODULE;
        }
        $withoutVat = abs((float) $plan['total_vat']) < 0.005
            && !array_any($plan['items'], static fn (array $item): bool => abs((float) $item['vat']) >= 0.005
                || ($item['vat_classification'] ?? null) !== null);
        if (!$withoutVat) {
            return $evidence === 'zavazek' ? self::ROUTE_DOCUMENT : self::ROUTE_REVIEW;
        }
        if ($plan['source_cancelled'] || $plan['currency'] !== 'CZK' || (float) $plan['total_with_vat'] < 0.005
            || (string) ($plan['settlement_account'] ?? '') === '') {
            return self::ROUTE_REVIEW;
        }
        return self::ROUTE_OTHER_ITEM;
    }

    /** @param array<string,mixed> $plan */
    public static function side(array $plan): string
    {
        return $plan['evidence'] === 'pohledavka' ? 'receivable' : 'payable';
    }

    /**
     * Kontace ostatní položky z jejích zápisů v převzatém deníku.
     *
     * @param array<string,mixed> $plan
     * @param list<array{id:int,source_type:string}> $entries
     * @return array{status:string,entry_id:?int,account:string,counter:?string,lines:list<array{account_code:string,amount:float}>,date:string,posted_at:?string,note:string}|null
     *         null = doklad zůstane jen v deníku
     */
    public function posting(int $supplierId, array $plan, array $entries): ?array
    {
        $number = (string) $plan['document_no'];
        $manual = array_values(array_filter($entries, static fn (array $entry): bool => $entry['source_type'] !== 'opening'));
        $unposted = [
            'status' => 'confirmed', 'entry_id' => null, 'account' => (string) $plan['settlement_account'],
            'counter' => (string) ($plan['counter_account'] ?? '') ?: null, 'lines' => [], 'date' => (string) $plan['date'],
            'posted_at' => null,
        ];
        if ($manual === []) {
            return $unposted + ['note' => "Převzato z ABRA Flexi, doklad {$number}. Předpis je v deníku počátečním stavem"
                . ' nebo jiným dokladem, položka se proto neúčtuje.'];
        }
        $amount = round((float) $plan['total_with_vat'], 2);
        if (count($manual) === 1) {
            $entryId = (int) $manual[0]['id'];
            $head = $this->db->pdo()->prepare("SELECT entry_date, posted_at FROM journal_entries
                WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL AND reversed_by IS NULL");
            $head->execute([$entryId, $supplierId]);
            $entry = $head->fetch(PDO::FETCH_ASSOC);
            $posting = $entry === false ? null
                : MigratedOtherItemPolicy::postingLines($this->lines($supplierId, [$entryId]), self::side($plan), $amount);
            if ($posting !== null) {
                return [
                    'status' => 'posted', 'entry_id' => $entryId, 'account' => $posting['account'],
                    'counter' => count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null,
                    'lines' => $posting['lines'], 'date' => (string) $entry['entry_date'],
                    'posted_at' => $entry['posted_at'] !== null ? (string) $entry['posted_at'] : null,
                    'note' => "Převzato z ABRA Flexi, doklad {$number}.",
                ];
            }
        }
        // Doklad o více řádcích (splátka = jistina 379/379 + úrok 562/379) nebo se zápisem,
        // který nemění zůstatky: jeho zápisy převzal deník a dohromady dávají na jediný
        // saldokontní účet celou částku dokladu. Položka je v agendě, ale znovu se neúčtuje.
        $account = $this->settlementAccount($this->lines($supplierId, array_column($manual, 'id')), self::side($plan), $amount);
        if ($account === null) {
            return null;
        }
        return array_replace($unposted, ['account' => $account, 'counter' => null]) + [
            'note' => "Převzato z ABRA Flexi, doklad {$number}. Jeho zápisy v ABRA (" . count($manual)
                . ') jsou převzaté v deníku na účtu ' . $account . ', položka se proto znovu neúčtuje.',
        ];
    }

    /**
     * Saldokontní účet, na který zápisy dokladu dávají (na straně závazku D, pohledávky MD)
     * právě jeho celou částku. Jiný účet na té straně nebo jiný součet = doklad ke kontrole.
     *
     * @param list<array{side:string,amount:string|float,account_code:string}> $rows
     */
    private function settlementAccount(array $rows, string $side, float $amount): ?string
    {
        $balanceSide = $side === 'receivable' ? 'debit' : 'credit';
        $account = null;
        $cents = 0;
        foreach ($rows as $row) {
            if ($row['side'] !== $balanceSide) continue;
            if ($account !== null && $account !== (string) $row['account_code']) return null;
            $account = (string) $row['account_code'];
            $cents += (int) round((float) $row['amount'] * 100);
        }
        return $account !== null && $cents === (int) round($amount * 100) ? $account : null;
    }

    /** @param list<int> $entryIds @return list<array<string,mixed>> */
    private function lines(int $supplierId, array $entryIds): array
    {
        if ($entryIds === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT l.side, l.amount, a.account_code, a.account_type
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND e.reversed_by IS NULL AND l.entry_id IN ('
                . implode(',', array_fill(0, count($entryIds), '?')) . ')
            ORDER BY l.entry_id, l.line_no, l.id');
        $stmt->execute([$supplierId, ...array_map('intval', $entryIds)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Založí ostatní položku. Číslo dokladu obsazené jinou položkou dostane příponu ze zdroje.
     *
     * @param array<string,mixed> $plan
     * @param array{status:string,entry_id:?int,account:string,counter:?string,lines:list<array{account_code:string,amount:float}>,date:string,posted_at:?string,note:string} $posting
     */
    public function insert(int $supplierId, ?int $userId, array $plan, ?int $partnerId, array $posting): int
    {
        $pdo = $this->db->pdo();
        $amount = number_format(round((float) $plan['total_with_vat'], 2), 2, '.', '');
        $symbol = preg_match('/^[0-9]{1,20}$/D', (string) $plan['variable_symbol']) === 1 ? (string) $plan['variable_symbol'] : null;
        $insert = $pdo->prepare('INSERT INTO other_items
            (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
             currency, amount, amount_czk, variable_symbol, account_code, counter_account_code,
             note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $documentNo = mb_substr((string) $plan['document_no'], 0, 50);
        $taken = $pdo->prepare('SELECT 1 FROM other_items WHERE supplier_id = ? AND document_no = ? LIMIT 1');
        $taken->execute([$supplierId, $documentNo]);
        if ($taken->fetchColumn() !== false) {
            $documentNo = mb_substr($documentNo, 0, 41) . '-' . substr(hash('sha256', (string) $plan['source_key']), 0, 8);
        }
        $insert->execute([
            $supplierId, self::side($plan), self::kind($plan), mb_substr((string) $plan['title'], 0, 255),
            $partnerId, mb_substr((string) ($plan['partner']['name'] ?? ''), 0, 190) ?: null,
            $plan['date'], $posting['date'], $plan['due_date'], 'CZK', $amount, $amount, $symbol,
            $posting['account'], $posting['counter'], $posting['note'], $posting['status'], $documentNo,
            $posting['entry_id'], $posting['posted_at'], $userId, $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($posting['counter'] === null && $posting['lines'] !== []) {
            $line = $pdo->prepare('INSERT INTO other_item_posting_lines
                (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($posting['lines'] as $position => $row) {
                $line->execute([$supplierId, $id, $position + 1, $row['account_code'], number_format($row['amount'], 2, '.', '')]);
            }
        }
        if ($posting['entry_id'] !== null) {
            $owner = $pdo->prepare("UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL");
            $owner->execute([$id, $posting['entry_id'], $supplierId]);
            if ($owner->rowCount() !== 1) {
                throw new AbraException('other_item_journal_claim', 'Převzatý zápis deníku už vlastní jiný doklad.');
            }
        }
        return $id;
    }

    /** @param array<string,mixed> $plan */
    private static function kind(array $plan): string
    {
        $text = mb_strtolower((string) ($plan['document_type'] ?? '') . ' ' . (string) $plan['title']);
        if (str_starts_with((string) $plan['settlement_account'], '46') || preg_match('/\b(spk|leasing|úvěr|uver|splátk)/u', $text) === 1) {
            return 'loan';
        }
        return 'other';
    }
}
