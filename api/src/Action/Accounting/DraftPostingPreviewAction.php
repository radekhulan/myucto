<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\InvoicePostingProposal;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as Psr7Response;

/**
 * Náhled zápisu faktury přímo v editoru, ještě před uložením.
 *
 *   POST /api/accounting/journal/draft-posting-preview/{invoices|purchase-invoices}
 *   tělo: { "doc_id": 123 | null, "document": { …stejné tělo jako uložení dokladu… } }
 *
 * Náhled si nic nepočítá sám. V transakci, která se vždy odvolá, doklad ULOŽÍ tytéž
 * akce jako tlačítko Uložit (založení / úprava), převede ho z konceptu do stavu, ve
 * kterém se účtuje, a řádky sestaví {@see InvoicePostingProposal} — tentýž výpočet,
 * jaký použije „Zaúčtovat". Validace, zámky období a podané DPH tak hlásí náhled
 * stejně jako uložení a výsledek se nemůže rozejít se zápisem, který pak vznikne.
 *
 * Zaúčtovaný doklad se nesimuluje: jeho úprava by šla přes přeúčtování a náhled
 * vrátí jen odkaz na živý zápis (`already_posted`).
 */
final class DraftPostingPreviewAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Atribut požadavku, podle kterého uložení v náhledu vynechá zásahy mimo databázi (PDF). */
    public const ATTR = 'draft_posting_preview';

    private const SAVEPOINT = 'draft_posting_preview';

    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePostingProposal $proposal,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly CreatePurchaseInvoiceAction $createPurchase,
        private readonly UpdatePurchaseInvoiceAction $updatePurchase,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly UpdateInvoiceAction $updateInvoice,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Náhled zápisu je jen pro účetní.', 403);
        }

        $isPurchase = (string) ($args['source'] ?? '') === 'purchase-invoices';
        $sourceType = $isPurchase ? 'purchase_invoice' : 'invoice';
        $table = $isPurchase ? 'purchase_invoices' : 'invoices';
        $body = (array) ($request->getParsedBody() ?? []);
        $docId = (int) ($body['doc_id'] ?? 0);
        $document = is_array($body['document'] ?? null) ? $body['document'] : null;
        if ($document === null) {
            return Json::error($response, 'validation_failed', 'Chybí tělo dokladu (document).', 422);
        }

        // Náhled ukládá doklad stejně jako Uložit, proto potřebuje stejné právo.
        $permission = ($isPurchase ? 'purchase_invoices' : 'invoices') . ($docId > 0 ? '' : '.create');
        if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $err)) return $err;

        if ($docId > 0) {
            $exists = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
            $exists->execute([$docId, $supplierId]);
            if ($exists->fetchColumn() === false) {
                return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
            }
            $posted = $this->postedEntryId($supplierId, $sourceType, $docId);
            if ($posted !== null) {
                return Json::ok($response, self::payload($sourceType, $docId, null, [], true, $posted));
            }
        }

        // Podání z příchozí schránky by uložení dokončilo i se soubory — to do náhledu nepatří.
        unset($document['submission_id']);

        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec('SAVEPOINT ' . self::SAVEPOINT) : $pdo->beginTransaction();
        try {
            $result = $this->simulate($request, $response, $supplierId, $isPurchase, $sourceType, $docId, $document);
        } catch (\Throwable $e) {
            // Odvolanou transakci (deadlock, lock wait) už vrátit nejde; řeší ji volající.
            if (!TransactionFailure::rollbackIfAborted($pdo, $e)) {
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                    $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
        // Náhled nic neukládá: uložení dokladu se vždy odvolá.
        if ($nested) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return $result;
    }

    /**
     * Uloží doklad akcí Uložit, převede koncept do účtovaného stavu a sestaví návrh
     * zápisu. Běží uvnitř transakce náhledu, kterou volající vždy odvolá.
     *
     * @param array<string,mixed> $document
     */
    private function simulate(Request $request, Response $response, int $supplierId, bool $isPurchase, string $sourceType, int $docId, array $document): Response
    {
        $pdo = $this->db->pdo();
        $saveRequest = $request
            ->withMethod($docId > 0 ? 'PUT' : 'POST')
            ->withParsedBody($document)
            ->withQueryParams([])
            ->withAttribute(self::ATTR, true);
        $saved = match (true) {
            $isPurchase && $docId > 0 => ($this->updatePurchase)($saveRequest, new Psr7Response(), ['id' => (string) $docId]),
            $isPurchase               => ($this->createPurchase)($saveRequest, new Psr7Response()),
            $docId > 0                => ($this->updateInvoice)($saveRequest, new Psr7Response(), ['id' => (string) $docId]),
            default                   => ($this->createInvoice)($saveRequest, new Psr7Response()),
        };
        if ($saved->getStatusCode() >= 300) {
            // Chyba uložení (validace, zámek období, podané DPH) je i odpovědí náhledu.
            return $saved;
        }
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Uložení v náhledu zápisu ukončilo transakci náhledu.');
        }
        $saved->getBody()->rewind();
        $savedDoc = (array) json_decode((string) $saved->getBody(), true);
        $id = (int) ($savedDoc['id'] ?? 0);
        if ($id <= 0) {
            throw new \LogicException('Uložení v náhledu zápisu nevrátilo doklad.');
        }

        // Koncept se neúčtuje. Přijatá faktura přejde do „přijato" toutéž cestou jako
        // tlačítko Přijmout, vydaná do stavu vystavená (vystavení přiděluje číslo
        // a snapshoty, částky ani kurz nemění).
        if ($isPurchase) {
            if ((string) ($savedDoc['status'] ?? '') === 'draft') {
                $this->purchases->setStatus($id, 'received', $supplierId);
            }
        } elseif ((string) ($savedDoc['status'] ?? '') === 'draft') {
            $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ? AND supplier_id = ? AND status = 'draft'")
                ->execute([$id, $supplierId]);
        }

        $entryDate = $this->proposal->documentDate($supplierId, $sourceType, $id);
        if ($entryDate === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }
        try {
            // Náhled ukazuje řádky tak, jak je zápis uloží (analytika, dvojice), ne surový návrh.
            $lines = $this->proposal->prepared(
                $supplierId,
                $sourceType,
                $id,
                $this->proposal->lines($supplierId, $sourceType, $id, $entryDate),
                $entryDate,
            );
        } catch (PostingException|UnbalancedEntryException $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, self::payload(
            $sourceType,
            $docId > 0 ? $docId : null,
            $entryDate,
            $this->proposal->describe($supplierId, $lines),
            InvoicePostingProposal::balanced($lines),
            null,
        ));
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    private static function payload(string $sourceType, ?int $docId, ?string $entryDate, array $lines, bool $balanced, ?int $alreadyPosted): array
    {
        return [
            'source_type'    => $sourceType,
            'doc_id'         => $docId,
            'entry_date'     => $entryDate,
            'lines'          => $lines,
            'balanced'       => $balanced,
            'already_posted' => $alreadyPosted,
        ];
    }

    private function postedEntryId(int $supplierId, string $sourceType, int $docId): ?int
    {
        $group = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $docId);
        if ($group !== []) return (int) $group[0]['id'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
