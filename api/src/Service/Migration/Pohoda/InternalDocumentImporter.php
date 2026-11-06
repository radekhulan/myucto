<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Interní doklady POHODY v agendě Účetnictví → Interní doklady. Účetně jsou už v deníku
 * ({@see ChartJournalImporter}), tady nad jejich zápisem vznikne hlavička stejnou cestou jako
 * akce „Vytvořit interní doklad ze zápisu". Deník ani výkazy se nemění.
 *
 * Partnera a druh dokladu POHODA v deníku nevede jako údaj hlavičky: partner se bere z řádků
 * zápisu, jen když je jednoznačný, druh odvozuje {@see classify()}. Karty partnerů se tu
 * nezakládají, neznámý partner zůstane jen jménem na dokladu.
 *
 * Běží po navázání dokladů: zápis, který patří k zaúčtování faktury, řídí faktura a hlavičku
 * nedostane. Opakovaný převod doplní jen chybějící doklady a srovná datum přesunutého zápisu,
 * druh ani partnera existujícího dokladu nemění.
 */
final class InternalDocumentImporter
{
    private const FX_ACCOUNTS = ['563', '663'];

    /**
     * Pohledávky a závazky, proti kterým vzniká kurzový rozdíl. Širší než `SALDO_BLACKLIST`
     * bankovního účtování: ten chrání účty párované na faktury, kurzový rozdíl vzniká i na
     * ostatních pohledávkách a závazcích.
     */
    private const SALDO_ACCOUNTS = ['311', '314', '315', '321', '324', '325', '326', '365', '366', '378', '379'];

    private const ACCRUAL_ACCOUNTS = ['381', '383', '384', '385', '388', '389'];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly InternalDocumentService $documents,
        private readonly PartnerImporter $partners,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $entryIds = [];
        $groups = [];
        foreach ($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            if ((explode('|', (string) $key, 3)[1] ?? '') === PohodaJournal::INTERNAL) {
                $entryIds[] = (int) $entryId;
                $groups[(int) $entryId] = explode('|', (string) $key, 2)[1];
            }
        }
        $step = DocumentLinker::STEP_PAYMENTS;
        $unnumbered = 0;
        $refused = [];
        $facts = null;
        $settling = self::settlingNumbers($ctx);
        foreach (array_chunk($entryIds, 500) as $chunk) {
            foreach ($this->entries($ctx->supplierId, $chunk) as $entry) {
                if ($entry['document_id'] !== null) {
                    $this->alignDate($ctx->supplierId, $entry);
                    $ctx->protocol->count($step, 'internal_documents_existing');
                    continue;
                }
                if (trim((string) $entry['document_no']) === '') {
                    $unnumbered++;
                    continue;
                }
                // Deník se čte až pro první nový doklad: běh bez nových dokladů soubor neotevře.
                $facts ??= self::journalFacts($ctx);
                $fact = $facts[$groups[(int) $entry['id']] ?? ''] ?? ['postings' => [], 'partners' => []];
                $title = self::title((string) $entry['description']);
                $input = [
                    'kind' => self::classify($title, $fact['postings'], isset($settling[trim((string) $entry['document_no'])])),
                    'title' => $title,
                ] + $this->partnerInput($ctx, $fact['partners']);
                try {
                    $created = $this->create($ctx, (int) $entry['id'], $input);
                } catch (InternalDocumentException $e) {
                    $refused[$e->errorCode][] = (string) $entry['document_no'];
                    continue;
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_INTERNAL_DOCUMENT, (string) $entry['id'], (int) $created['document']['id'], $ctx->runId);
                $ctx->protocol->count($step, 'internal_documents');
            }
        }
        if ($unnumbered > 0) {
            $ctx->protocol->warn($step, 'internal_document_without_number', "{$unnumbered} interních dokladů POHODY nemá číslo, zůstávají jen v deníku.", ['entries' => $unnumbered]);
        }
        foreach ($refused as $code => $numbers) {
            $ctx->protocol->warn($step, 'internal_document_not_created', sprintf(
                'Interní doklad nevznikl (%s) u %d zápisů, zůstávají jen v deníku: %s.',
                $code, count($numbers), implode(', ', array_slice($numbers, 0, 10)) . (count($numbers) > 10 ? ', …' : ''),
            ), ['code' => $code, 'documents' => array_slice($numbers, 0, 200)]);
        }
    }

    /**
     * Druh interního dokladu podle jeho textu a zaúčtování, v pochybnostech `other`. Mzdový
     * předpis je vždy `other`, i když účtuje na dohadné položky.
     *
     * @param list<array{debit:string,credit:string}> $postings řádky zápisu ({@see PohodaJournal::effect()})
     * @param bool $settlesDocuments POHODA tímto dokladem uhradila jiné doklady (`liquidations`)
     */
    public static function classify(string $text, array $postings, bool $settlesDocuments): string
    {
        $text = trim($text);
        if (preg_match('~^Mzdy\s+\d{4}/\d{1,2}\b~iu', $text) === 1) {
            return 'other';
        }
        if ($settlesDocuments || preg_match('/^z[aá]po[cč](et|t)/iu', $text) === 1) {
            return 'offset';
        }
        $synthetic = static fn (string $code): string => (string) AccountCode::synthetic($code);
        $isFx = $postings !== [];
        $isAccrual = false;
        foreach ($postings as $posting) {
            $debit = $synthetic($posting['debit']);
            $credit = $synthetic($posting['credit']);
            $isFx = $isFx && (
                (in_array($debit, self::FX_ACCOUNTS, true) && in_array($credit, self::SALDO_ACCOUNTS, true))
                || (in_array($credit, self::FX_ACCOUNTS, true) && in_array($debit, self::SALDO_ACCOUNTS, true))
            );
            $isAccrual = $isAccrual || in_array($debit, self::ACCRUAL_ACCOUNTS, true) || in_array($credit, self::ACCRUAL_ACCOUNTS, true);
        }
        if ($isFx) {
            return 'fx_difference';
        }
        return $isAccrual ? 'accrual' : 'other';
    }

    /**
     * Jediný partner řádků zápisu, řádky bez partnera a s IČO vlastní firmy se nepočítají.
     * `null` = žádný nebo víc různých. Tentýž partner může mít řádky různě úplné: rozhoduje
     * IČO, pak vazba na adresář, pak název. Údaje, které nejde porovnat, znamenají různé partnery.
     *
     * @param list<array{pohoda_id:string,name:string,ico:string}> $partners
     * @return array{pohoda_id:string,name:string,ico:string}|null
     */
    public static function solePartner(array $partners, string $ownIco): ?array
    {
        $sole = null;
        foreach ($partners as $partner) {
            if (($partner['pohoda_id'] === '' && $partner['name'] === '' && $partner['ico'] === '') || ($partner['ico'] !== '' && $partner['ico'] === $ownIco)) {
                continue;
            }
            if ($sole === null) {
                $sole = $partner;
                continue;
            }
            if (!self::samePartner($sole, $partner)) {
                return null;
            }
            foreach (['pohoda_id', 'name', 'ico'] as $field) {
                if ($sole[$field] === '') {
                    $sole[$field] = $partner[$field];
                }
            }
        }
        return $sole;
    }

    /**
     * @param array{pohoda_id:string,name:string,ico:string} $a
     * @param array{pohoda_id:string,name:string,ico:string} $b
     */
    private static function samePartner(array $a, array $b): bool
    {
        if ($a['ico'] !== '' && $b['ico'] !== '') {
            return $a['ico'] === $b['ico'];
        }
        if ($a['pohoda_id'] !== '' && $b['pohoda_id'] !== '') {
            return $a['pohoda_id'] === $b['pohoda_id'];
        }
        return $a['name'] !== '' && mb_strtolower($a['name']) === mb_strtolower($b['name']);
    }

    /**
     * Čísla interních dokladů, kterými POHODA hradila jiné doklady. Číslo je oříznuté na šířku
     * `journal_entries.document_no` ({@see ChartJournalImporter}).
     *
     * @return array<string,true>
     */
    private static function settlingNumbers(PohodaContext $ctx): array
    {
        $numbers = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['agenda'] === 'internalDocuments' && trim($l['source']) !== '') {
                $numbers[mb_substr(trim($l['source']), 0, 50)] = true;
            }
        }
        return $numbers;
    }

    /**
     * Zaúčtování a partneři zápisů interních dokladů podle klíče zápisu v deníku.
     *
     * @return array<string,array{postings:list<array{debit:string,credit:string}>,partners:list<array<string,string>>}>
     */
    private static function journalFacts(PohodaContext $ctx): array
    {
        $facts = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::source($item) !== PohodaJournal::INTERNAL) {
                continue;
            }
            $key = PohodaJournal::groupKey($item);
            $facts[$key] ??= ['postings' => [], 'partners' => []];
            $effect = PohodaJournal::effect($item);
            if ($effect !== null) {
                $facts[$key]['postings'][] = ['debit' => $effect['debit'], 'credit' => $effect['credit']];
            }
            $facts[$key]['partners'][] = PohodaJournal::partner($item);
        }
        return $facts;
    }

    /**
     * @param list<array<string,string>> $partners
     * @return array{partner_id?:int,partner_name?:string}
     */
    private function partnerInput(PohodaContext $ctx, array $partners): array
    {
        $sole = self::solePartner($partners, PartnerImporter::ico($ctx->export->ico));
        if ($sole === null) {
            return [];
        }
        $input = [];
        $clientId = $this->partners->knownPartner($ctx, $sole);
        if ($clientId !== null) {
            $input['partner_id'] = $clientId;
        }
        if ($sole['name'] !== '') {
            $input['partner_name'] = mb_substr($sole['name'], 0, 190);
        }
        return $input;
    }

    /**
     * Karta z mapy převodu mohla být mezi převody smazaná. Doklad kvůli ní nesmí zůstat bez
     * hlavičky, partner na něm zůstane jen jménem.
     *
     * @param array<string,mixed> $input
     * @return array{document: array<string,mixed>, numbered: bool}
     */
    private function create(PohodaContext $ctx, int $entryId, array $input): array
    {
        $audit = ['user_id' => $ctx->userOrNull()];
        try {
            return $this->documents->createFromEntry($ctx->supplierId, $entryId, $input, $audit);
        } catch (InternalDocumentException $e) {
            if ($e->errorCode !== 'invalid_partner') {
                throw $e;
            }
        }
        unset($input['partner_id']);
        return $this->documents->createFromEntry($ctx->supplierId, $entryId, $input, $audit);
    }

    /**
     * Zápisy, které zůstaly ruční. Interní doklad převzatý jako daňový doklad k platbě nebo
     * oprava navázaná na doklad má vlastní agendu a do výběru nepatří.
     *
     * @param list<int> $entryIds
     * @return list<array<string,mixed>>
     */
    private function entries(int $supplierId, array $entryIds): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.document_no, e.description, e.entry_date, d.id AS document_id, d.entry_date AS document_entry_date
               FROM journal_entries e
               LEFT JOIN internal_documents d ON d.supplier_id = e.supplier_id AND d.journal_entry_id = e.id
              WHERE e.supplier_id = ? AND e.source_type = 'manual' AND e.source_id IS NULL
                AND e.id IN (" . implode(',', array_fill(0, count($entryIds), '?')) . ')
              ORDER BY e.id'
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $entry */
    private function alignDate(int $supplierId, array $entry): void
    {
        if ($entry['entry_date'] !== $entry['document_entry_date']) {
            $this->db->pdo()->prepare('UPDATE internal_documents SET entry_date = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$entry['entry_date'], $entry['document_id'], $supplierId]);
        }
    }

    /** Popis zápisu bez úvodní agendy a čísla ({@see ChartJournalImporter}), číslo má doklad zvlášť. */
    private static function title(string $description): string
    {
        return (string) preg_replace('/^' . preg_quote(PohodaJournal::INTERNAL, '/') . '\s+\S*\s*—\s*/u', '', $description);
    }
}
