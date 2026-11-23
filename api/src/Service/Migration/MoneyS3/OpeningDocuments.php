<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use PDO;

/**
 * Doklady z let před prvním převáděným rokem (volba „Převést od roku"), které byly k začátku
 * prvního převáděného období ještě otevřené: v MyÚčtu vzniknou jako doklady počátečního stavu,
 * navázané na otevírací zápis období ({@see \MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments}).
 * Saldokonto je pak ukáže proti počátečnímu stavu 311/321 a jejich pozdější úhrady z převedené
 * banky jde spárovat ({@see DocumentLinker::matchPayments()}).
 *
 * Money vede u dokladu jen datum úhrady (`Uhrazeno`) a zbytek k úhradě dnes (`Proplatit`).
 * Otevřený k začátku období `S` je proto:
 *  - faktura (`Druh` N, ne dobropis, ne stornovaná ani smazaná) uhrazená v `S` nebo později
 *    (celá částka, částečné úhrady před `S` Money nerozliší),
 *  - faktura bez data úhrady s nenulovým `Proplatit` se ohlásí k ověření a nezakládá: Money
 *    staré roky často neaktualizovalo a počáteční stav ji nemusí nést.
 *
 * Pojistka: součet otevřených dokladů strany nesmí převýšit počáteční stav 311 (vydané),
 * resp. 321 (přijaté) v otevíracím zápisu prvního období. Jinak se nezaloží žádný doklad
 * té strany a protokol to ohlásí.
 */
final class OpeningDocuments
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const UNVERIFIED = 'unverified';
    public const SKIP = 'skip';

    /** Saldokontní účet strany a znaménko jeho počátečního stavu (MD − D). */
    private const BALANCE = ['issued' => ['311', 1], 'purchase' => ['321', -1]];

    /**
     * Stav dokladu Money k začátku prvního převáděného období.
     *
     * @param array<string,mixed> $r řádek `VFaktury` / `PFaktury`
     */
    public static function status(array $r, string $periodStart): string
    {
        if ((int) ($r['FlagDel'] ?? 0) === 1 || (int) ($r['Storno'] ?? 0) === 1 || (int) ($r['Dobropis'] ?? 0) === 1) {
            return self::SKIP;
        }
        $kind = strtoupper(trim((string) ($r['Druh'] ?? '')));
        if ($kind !== '' && $kind !== 'N') {
            return self::SKIP;
        }
        if (round((float) ($r['CelkemSDPH'] ?? 0), 2) <= 0.0) {
            return self::SKIP;
        }
        $paid = InvoiceImporter::date($r, ['Uhrazeno']);
        if ($paid !== null) {
            return $paid >= $periodStart ? self::OPEN : self::CLOSED;
        }
        return abs(round((float) ($r['Proplatit'] ?? 0), 2)) >= 0.005 ? self::UNVERIFIED : self::CLOSED;
    }

    /**
     * Rok adresáře, pokud je doklad ověřený doklad počátečního stavu ({@see plan()}), jinak null.
     *
     * @param array<string,mixed> $r
     */
    public static function yearOf(ImportContext $ctx, array $r, bool $issued): ?int
    {
        $dir = (string) ($r['__dir'] ?? '');
        if (!isset($ctx->openingDocumentDirs[$dir])) {
            return null;
        }
        return $ctx->openingCandidates[$issued ? 'issued' : 'purchase'][$dir . '|' . trim((string) ($r['Doklad'] ?? ''))] ?? null;
    }

    /**
     * Vybere doklady počátečního stavu jedné strany a ověří je proti počátečnímu stavu
     * (viz třída). Výsledek zapíše do kontextu, zprávy do protokolu kroku `$step`.
     */
    public static function plan(ImportContext $ctx, PDO $pdo, bool $issued, string $step): void
    {
        $side = $issued ? 'issued' : 'purchase';
        $ctx->openingCandidates ??= ['issued' => [], 'purchase' => []];
        $ctx->openingCandidates[$side] = [];
        if ($ctx->openingDocumentDirs === [] || $ctx->periods === []) {
            return;
        }
        $first = $ctx->periods[min(array_keys($ctx->periods))];
        $start = (string) $first['starts_on'];
        $open = [];
        $unverified = [];
        $sum = 0.0;
        foreach ($ctx->backup->rowsAcrossYears($issued ? 'VFaktury' : 'PFaktury') as $r) {
            $dir = (string) ($r['__dir'] ?? '');
            $year = $ctx->openingDocumentDirs[$dir] ?? null;
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $total = round((float) ($r['CelkemSDPH'] ?? 0), 2);
            switch (self::status($r, $start)) {
                case self::OPEN:
                    $open[$dir . '|' . $docNo] = $year;
                    $sum += $total;
                    break;
                case self::UNVERIFIED:
                    $unverified[] = ['year' => $year, 'document_no' => $docNo, 'total' => $total, 'remaining' => round((float) ($r['Proplatit'] ?? 0), 2)];
                    break;
            }
        }
        $label = $issued ? 'vydaných' : 'přijatých';
        if ($unverified !== []) {
            $ctx->protocol->warn($step, 'opening_documents_unverified', sprintf(
                '%d %s faktur z let před rokem %d nemá v Money datum úhrady, ale vede zbytek k úhradě (celkem %s Kč): %s. '
                . 'Money je ve starých letech nejspíš neaktualizovalo, převod je jako otevřené nezaložil. Ověřte je proti počátečnímu stavu a případně založte položku ze zůstatku.',
                count($unverified), $label, (int) substr($start, 0, 4), number_format(array_sum(array_column($unverified, 'remaining')), 2, ',', ' '),
                implode(', ', array_map(static fn (array $d): string => "{$d['document_no']} ({$d['year']})", array_slice($unverified, 0, 20))) . (count($unverified) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($unverified, 0, ImportProtocol::LIST_LIMIT)]);
        }
        if ($open === []) {
            return;
        }
        [$account, $sign] = self::BALANCE[$side];
        $balance = self::openingBalance($pdo, $ctx->supplierId, (int) $first['id'], $account, $sign);
        if ($balance === null) {
            $ctx->protocol->warn($step, 'opening_documents_no_opening', sprintf(
                '%d %s faktur z let před rokem %d bylo k jeho začátku otevřených, první převáděné období ale nemá otevírací zápis. Doklady počátečního stavu převod nezaložil.',
                count($open), $label, (int) substr($start, 0, 4),
            ));
            return;
        }
        if (round($sum - $balance, 2) > 1.0) {
            $ctx->protocol->warn($step, 'opening_documents_exceed_balance', sprintf(
                'Otevřené %s faktury z let před rokem %d (%d dokladů, %s Kč) převyšují počáteční stav účtu %s (%s Kč). Doklady počátečního stavu převod nezaložil, ověřte úhrady v Money.',
                $issued ? 'vydané' : 'přijaté', (int) substr($start, 0, 4), count($open), number_format($sum, 2, ',', ' '), $account, number_format($balance, 2, ',', ' '),
            ), ['documents' => count($open), 'amount' => round($sum, 2), 'balance' => $balance]);
            return;
        }
        $ctx->openingCandidates[$side] = $open;
        $ctx->protocol->info($step, 'opening_documents', sprintf(
            '%d %s faktur z let před rokem %d bylo k jeho začátku otevřených (%s Kč, počáteční stav účtu %s %s Kč). Převádějí se jako doklady počátečního stavu navázané na otevírací zápis.',
            count($open), $label, (int) substr($start, 0, 4), number_format($sum, 2, ',', ' '), $account, number_format($balance, 2, ',', ' '),
        ), ['documents' => count($open), 'amount' => round($sum, 2), 'balance' => $balance]);
    }

    /**
     * Počáteční stav syntetického účtu (se znaménkem strany) v živém otevíracím zápisu
     * období, nebo null, když období otevírací zápis nemá.
     */
    private static function openingBalance(PDO $pdo, int $supplierId, int $periodId, string $account, int $sign): ?float
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT e.id), COALESCE(SUM(CASE WHEN a.account_code LIKE ? THEN IF(l.side = 'debit', l.amount, -l.amount) ELSE 0 END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type = 'opening' AND e.reversed_by IS NULL"
        );
        $stmt->execute([$account . '%', $supplierId, $periodId]);
        [$entries, $balance] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return (int) $entries === 0 ? null : round($sign * (float) $balance, 2);
    }
}
