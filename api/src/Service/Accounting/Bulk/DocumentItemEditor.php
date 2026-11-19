<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Action\Accounting\DraftPostingPreviewAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ChangePurchaseInvoiceVatTreatmentAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use MyInvoice\Service\Vat\PurchaseFiledVatPeriod;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

/**
 * Změna položek vydané nebo přijaté faktury z hromadné akce PŘESNĚ cestou úpravy jednoho
 * dokladu: tytéž akce jako tlačítko Uložit v editoru, za uživatele úlohy a s jeho právy.
 * Hodnoty DPH (členění, sazba) ani částky se tu nikdy nezapisují vlastním SQL, akce sama
 * přepočte položky, součty a rekapitulaci DPH, dopočítá kód hlavičky, zneplatní PDF
 * a zaúčtovaný doklad přeúčtuje ({@see \MyInvoice\Service\Accounting\DocumentJournalSync::repostForceEdit()}).
 *
 *   • vydaná faktura: {@see UpdateInvoiceAction} (`PUT /api/invoices/{id}`, u vystavené
 *     s `?force=1`), celý seznam položek jako po načtení dokladu, změněné jen vybrané,
 *   • přijatá faktura, jen členění DPH: {@see ChangePurchaseInvoiceVatTreatmentAction}
 *     (úzká oprava DPH zařazení, i u zaúčtovaného dokladu bez storna),
 *   • přijatá faktura se změnou sazby: {@see UpdatePurchaseInvoiceAction}.
 *
 * Položky se adresují POŘADÍM (bez systémových slevových řádků), ne id: uložení vydané
 * faktury řádky zapisuje znovu a id se mění. Otisk dokladu hlídá, že pořadí mezi běhy
 * zůstalo.
 *
 * Běh nanečisto volá tutéž akci v transakci, která se odvolá (stejně jako náhled zápisu
 * v editoru, {@see DraftPostingPreviewAction}); stav po změně přečte ještě před odvoláním.
 */
final class DocumentItemEditor
{
    /** Pole položky, která hromadná akce mění. */
    public const FIELDS = ['vat_classification_code', 'vat_rate_id'];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly UpdateInvoiceAction $updateInvoice,
        private readonly UpdatePurchaseInvoiceAction $updatePurchase,
        private readonly ChangePurchaseInvoiceVatTreatmentAction $purchaseVatTreatment,
        private readonly DocumentLockService $locks,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly PurchaseFiledVatPeriod $purchaseFiledPeriods,
    ) {}

    /** Uložený doklad s položkami (NULL = není nebo patří jiné firmě). */
    public function document(int $supplierId, string $type, int $docId): ?array
    {
        if ($type === 'invoice') {
            $doc = $this->invoices->find($docId);
            return $doc !== null && (int) $doc['supplier_id'] === $supplierId ? $doc : null;
        }
        return $this->purchases->find($docId, $supplierId);
    }

    /**
     * Stav dokladu pro hromadnou akci: hlavička, položky v pořadí (bez slevových řádků),
     * aktivní zápis v deníku a otisk všeho.
     *
     * @return array{header:array<string,mixed>, items:list<array<string,mixed>>, entry:?array{id:int, row_version:int}, fingerprint:string}|null
     */
    public function state(int $supplierId, string $type, int $docId): ?array
    {
        $doc = $this->document($supplierId, $type, $docId);
        return $doc === null ? null : $this->stateOf($supplierId, $type, $doc);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array{header:array<string,mixed>, items:list<array<string,mixed>>, entry:?array{id:int, row_version:int}, fingerprint:string}
     */
    public function stateOf(int $supplierId, string $type, array $doc): array
    {
        $items = [];
        foreach (self::lines($doc) as $position => $item) {
            $items[] = [
                'position'                => $position,
                'item_id'                 => (int) $item['id'],
                'description'             => mb_substr((string) ($item['description'] ?? ''), 0, 120),
                'standard'                => ($item['item_kind'] ?? 'standard') === 'standard',
                'oss'                     => !empty($item['oss_applicable']),
                'vat_classification_code' => ($item['vat_classification_code'] ?? null) ?: null,
                'vat_rate_id'             => (int) $item['vat_rate_id'],
                'vat_rate'                => round((float) ($item['vat_rate_snapshot'] ?? 0), 2),
                'account_code'            => (($type === 'invoice' ? $item['revenue_account_code'] ?? null : $item['expense_account_code'] ?? null)) ?: null,
                'total_without_vat'       => round((float) $item['total_without_vat'], 2),
                'total_vat'               => round((float) $item['total_vat'], 2),
                'total_with_vat'          => round((float) $item['total_with_vat'], 2),
            ];
        }
        $header = [
            'status'                  => (string) $doc['status'],
            'vat_classification_code' => ($doc['vat_classification_code'] ?? null) ?: null,
            'prices_include_vat'      => !empty($doc['prices_include_vat']),
            'total_without_vat'       => round((float) $doc['total_without_vat'], 2),
            'total_vat'               => round((float) $doc['total_vat'], 2),
            'total_with_vat'          => round((float) $doc['total_with_vat'], 2),
        ];
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_version FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND active_source_id = ? AND posted_at IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $type, (int) $doc['id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        $entry = $entry === false ? null : ['id' => (int) $entry['id'], 'row_version' => (int) $entry['row_version']];
        $comparable = array_map(static fn (array $i): array => array_diff_key($i, ['item_id' => true]), $items);
        return [
            'header' => $header,
            'items' => $items,
            'entry' => $entry,
            'fingerprint' => hash('sha256', json_encode([$header, $comparable, $entry, (string) ($doc['updated_at'] ?? '')], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Proč doklad hromadně měnit nejde (NULL = jde): stornovaný, uzavřené účetní období,
     * zamčené datum a u změny, která se promítne do evidence DPH, podané období DPH.
     * Podané období se přeskočí VŽDY, i u správce: oprava podaného přiznání nebo KH jde
     * opravným či dodatečným tvrzením, ne hromadnou změnou. Koncept do výkazů nevstupuje.
     *
     * @param array<string,mixed> $doc
     * @return array{0:string, 1:string}|null
     */
    public function blocker(int $supplierId, string $type, array $doc, bool $vatRelevant): ?array
    {
        if ($doc['status'] === 'cancelled') {
            return ['document_cancelled', 'Stornovaný doklad se neupravuje.'];
        }
        $lock = $type === 'invoice' ? $this->locks->forInvoice($doc) : $this->locks->forPurchaseInvoice($doc);
        if ($lock->inClosedPeriod) {
            return ['period_not_open', 'Doklad leží v uzavřeném účetním období.'];
        }
        if ($lock->dateLocked) {
            return ['date_locked', 'Účetnictví je k datu dokladu uzamčené.'];
        }
        if ($vatRelevant && $doc['status'] !== 'draft') {
            $filed = $type === 'invoice'
                ? $this->filedPeriods->describe($supplierId, (string) (DocumentLockService::invoiceRefDate($doc) ?? $doc['issue_date']),
                    array_filter(self::lines($doc), static fn (array $i): bool => !empty($i['oss_applicable'])) !== [])
                : $this->purchaseFiledPeriods->describe($supplierId, (int) $doc['id']);
            if ($filed !== null) {
                return ['vat_period_filed', 'Období DPH dokladu už je podané (' . $filed . '). Hromadná akce ho nemění; '
                    . 'opravu řešte opravným nebo dodatečným tvrzením.'];
            }
        }
        return null;
    }

    /**
     * Uloží změny položek akcí úpravy jednoho dokladu, v transakci položky. `$dryRun`
     * transakci po uložení odvolá. Vrací stav po změně, nebo důvod odmítnutí, který
     * vrátila akce (kód a hláška jako u jednoho dokladu).
     *
     * `$verify` dostane stav po uložení ještě v transakci; vrácená hláška změnu odvolá
     * (vrácení hromadné akce tak buď obnoví stav přesně, nebo nezmění nic).
     *
     * @param array<int, array<string,mixed>> $changes pořadí položky => pole ({@see FIELDS})
     * @param (callable(array<string,mixed>):?string)|null $verify
     * @return array{ok:bool, code:?string, message:?string, after:?array<string,mixed>}
     */
    public function save(BulkContext $ctx, string $type, int $docId, array $changes, bool $dryRun, ?callable $verify = null): array
    {
        $pdo = $this->db->pdo();
        try {
            return TransactionFailure::retrying($pdo, fn (): array => BulkTransaction::run($pdo, function () use ($ctx, $type, $docId, $changes, $dryRun, $verify): array {
                $doc = $this->document($ctx->supplierId, $type, $docId);
                if ($doc === null) {
                    throw new DocumentItemEditRejected('not_found', 'Doklad nenalezen.');
                }
                $response = $this->call($ctx, $type, $doc, $changes, $dryRun);
                if ($response->getStatusCode() >= 300) {
                    $body = json_decode((string) $response->getBody(), true);
                    $error = is_array($body) ? (array) ($body['error'] ?? []) : [];
                    throw new DocumentItemEditRejected(
                        mb_substr((string) ($error['code'] ?? 'save_failed'), 0, 80),
                        isset($error['message']) ? mb_substr((string) $error['message'], 0, 500) : null,
                    );
                }
                $after = $this->state($ctx->supplierId, $type, $docId);
                if ($after === null) {
                    throw new DocumentItemEditRejected('not_found', 'Doklad nenalezen.');
                }
                if ($verify !== null && ($why = $verify($after)) !== null) {
                    throw new DocumentItemEditRejected('rollback_not_exact', $why);
                }
                return ['ok' => true, 'code' => null, 'message' => null, 'after' => $after];
            }, $dryRun));
        } catch (DocumentItemEditRejected $e) {
            return ['ok' => false, 'code' => $e->reasonCode, 'message' => $e->getMessage() !== '' ? $e->getMessage() : null, 'after' => null];
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<int, array<string,mixed>> $changes
     */
    private function call(BulkContext $ctx, string $type, array $doc, array $changes, bool $dryRun): Response
    {
        $request = RequestAuthorization::asBulkActionSession($ctx->request())->withMethod('PUT');
        if ($dryRun) {
            // Uložení v náhledu nesahá na soubory mimo databázi (PDF), stejně jako náhled zápisu.
            $request = $request->withAttribute(DraftPostingPreviewAction::ATTR, true);
        }
        $args = ['id' => (string) $doc['id']];
        $lines = self::lines($doc);
        $force = $doc['status'] !== 'draft' ? ['force' => '1'] : [];
        if ($type === 'purchase_invoice' && self::onlyClassification($changes, $lines)) {
            $items = [];
            foreach ($changes as $position => $fields) {
                $items[] = ['id' => (int) $lines[$position]['id'], 'vat_classification_code' => $fields['vat_classification_code']];
            }
            return ($this->purchaseVatTreatment)($request->withMethod('POST')->withQueryParams([])->withParsedBody(['items' => $items]),
                new SlimResponse(), $args);
        }
        foreach ($changes as $position => $fields) {
            foreach ($fields as $field => $value) {
                $lines[$position][$field] = $value;
            }
        }
        $request = $request->withQueryParams($force)->withParsedBody(['items' => array_values($lines)]);
        return $type === 'invoice'
            ? ($this->updateInvoice)($request, new SlimResponse(), $args)
            : ($this->updatePurchase)($request, new SlimResponse(), $args);
    }

    /**
     * Mění se jen členění DPH a všude na kód (úzká oprava DPH zařazení přijaté faktury
     * položku bez kódu nepřijme, to umí jen úprava dokladu).
     *
     * @param array<int, array<string,mixed>> $changes
     * @param list<array<string,mixed>> $lines
     */
    private static function onlyClassification(array $changes, array $lines): bool
    {
        foreach ($changes as $position => $fields) {
            if (!isset($lines[$position]) || array_keys($fields) !== ['vat_classification_code'] || $fields['vat_classification_code'] === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Položky dokladu tak, jak je vrací detail (a jak se posílají zpět k uložení), bez
     * systémových slevových řádků, které server generuje sám.
     *
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    public static function lines(array $doc): array
    {
        return array_values(array_filter(
            (array) ($doc['items'] ?? []),
            static fn (mixed $item): bool => is_array($item) && ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }
}
