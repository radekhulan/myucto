<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\ImportedIssuedDocumentPolicy;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Support\PaymentMethods;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/import — převzetí faktury nebo dobropisu, který už vystavil jiný
 * systém (typicky e-shop), s původním číslem, položkami, daty a částkami.
 *
 * Doklad se NEvystavuje znovu a NEpřepočítává: InvoiceCalculator by daň zaokrouhlenou
 * po dokladu přepočítal po řádcích a uložený doklad by se o haléře rozešel s tím, který
 * má zákazník v ruce. Částky se proto jen ověří (řádek sám se sebou, sazba, součty proti
 * hlavičce) a zapíšou doslova přes {@see MigratedDocumentWriter} — tutéž cestu, kterou
 * do aplikace přicházejí doklady převzaté z cizích účetních programů.
 *
 * Brány vystavení platí stejně jako u {@see IssueInvoiceAction}: přenesená povinnost
 * bez DIČ odběratele, neplátce s daní k rozhodnému datu a uzamčené období. Navíc se
 * odmítne období, za které už je podané přiznání nebo kontrolní hlášení: převzatý doklad
 * by do něj dodatečně přibyl a výkazy by se rozešly s tím, co je odevzdané.
 *
 * Úhrady, skladové pohyby a OSS do importu nepatří (viz popis v openapi.yaml).
 */
final class ImportIssuedInvoiceAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;

    private const TYPES = ['invoice', 'credit_note'];

    /** Shoda řádku a součtů v měně dokladu — haléř. */
    private const CENT = 0.01;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly DocumentLockService $locks,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly VatStatusService $vatStatus,
        private readonly OssItemPlanner $ossPlanner,
        private readonly OssItemDeriver $ossDeriver,
        private readonly InvoiceDefaults $defaults,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly SnapshotBuilder $snapshots,
        private readonly MigratedDocumentWriter $writer,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly StatsRecomputer $stats,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly CreditNoteOffsetService $creditNoteOffsets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Route permission map pouští import pod `invoices.issue` (vzniká vystavený doklad).
        // Založení dokladu je navíc `invoices.create` — import obojí spojuje, takže musí
        // projít obě laťky, jinak by se jím dalo obejít kterékoli z obou oprávnění.
        if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění zakládat faktury.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $badRefs = $this->tenantRefs->violations($supplierId, $body, ['client_id', 'currency_id']);
        if ($badRefs !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        $errors = [];
        $doc = $this->parseHeader($body, $errors);
        $items = $this->parseItems($body['items'] ?? null, $errors);
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $errors]);
        }

        $client = $this->clients->find($doc['client_id']);
        if (!SupplierGuard::owns($request, $client)) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $currency = $this->resolveCurrency($supplierId, $doc['client_id'], $body);
        if ($currency === null) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, [
                'fields' => ['currency_id' => ['Měna neexistuje nebo nepatří firmě.']],
            ]);
        }
        $exchangeRate = null;
        if ($currency['code'] !== 'CZK') {
            $exchangeRate = self::number($body['exchange_rate'] ?? null);
            if ($exchangeRate === null || $exchangeRate <= 0) {
                return Json::error($response, 'validation_failed', 'Validace selhala', 422, [
                    'fields' => ['exchange_rate' => ['Doklad v cizí měně musí nést kurz, kterým ho vystavil původní systém.']],
                ]);
            }
        }

        // § 42 odst. 3: u dobropisu určuje období opravy den doručení opravného dokladu.
        // Stejně jako při vystavení ({@see IssueInvoiceAction}) přepíše `tax_date`, datum
        // doručení zůstává ve vlastním sloupci.
        $taxDate = $doc['invoice_type'] === 'credit_note' && $doc['corrective_delivered_on'] !== null
            ? $doc['corrective_delivered_on']
            : $doc['tax_date'];

        // Sazba řádku: procento se páruje na číselník tuzemska firmy k DUZP, zadané id musí
        // být tuzemskou sazbou. Tuzemsko je země DODAVATELE, táž definice jako u zakládání
        // dokladu ({@see CreateInvoiceAction}); cizí sazba patří jen na OSS řádek a ten
        // import nepřebírá.
        $rateMap = $this->repo->vatRateMap();
        $rateCountries = $this->repo->vatRateCountryMap();
        $domesticCountry = strtoupper($this->ossDeriver->domesticCountry($supplierId));
        $rateErrors = [];
        foreach ($items as $i => &$item) {
            if ($item['vat_rate_id'] !== null) {
                if (!isset($rateMap[$item['vat_rate_id']])
                    || strtoupper((string) ($rateCountries[$item['vat_rate_id']] ?? '')) !== $domesticCountry) {
                    $rateErrors["items.{$i}.vat_rate_id"][] = 'Sazba DPH neexistuje nebo není tuzemskou sazbou firmy.';
                    continue;
                }
                $item['vat_rate'] = (float) $rateMap[$item['vat_rate_id']];
            } else {
                $match = $this->ossPlanner->resolveDomesticRate($supplierId, (float) $item['vat_rate'], $taxDate);
                if (!$match->found()) {
                    $rateErrors["items.{$i}.vat_rate"][] = $match->message;
                    continue;
                }
                $item['vat_rate_id'] = (int) $match->id;
                $item['vat_rate'] = (float) $match->ratePercent;
            }
        }
        unset($item);
        if ($rateErrors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $rateErrors]);
        }

        $amountErrors = $this->amountErrors($doc, $items);
        if ($amountErrors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $amountErrors]);
        }

        $codeErrors = $this->classificationCodeErrors($supplierId, $doc, $items);
        if ($codeErrors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $codeErrors]);
        }

        $parentId = null;
        if ($doc['invoice_type'] === 'credit_note'
            && ($doc['parent_invoice_id'] !== null || $doc['parent_varsymbol'] !== null)) {
            $parentId = $this->resolveParent($supplierId, $doc['parent_invoice_id'], $doc['parent_varsymbol']);
            if ($parentId === null) {
                return Json::error(
                    $response,
                    'parent_invoice_not_found',
                    'Opravovaná faktura neexistuje, není vystavená, je stornovaná nebo patří jiné firmě.',
                    422,
                );
            }
        }

        $existing = $this->existingId($supplierId, $doc['varsymbol']);
        if ($existing !== null) {
            return $this->duplicate($response, $doc['varsymbol'], $existing);
        }

        // Zámek dokladu: tatáž brána jako při vystavení. Doklad ještě nemá id, takže
        // zaúčtování ani zápis v deníku nést nemůže a o zámku rozhoduje jen datum —
        // proto `forDate()` nad datem, ze kterého by ho počítal `forInvoice()`.
        $refDate = DocumentLockService::invoiceRefDate(['tax_date' => $taxDate, 'issue_date' => $doc['issue_date']]);
        if ($refDate !== null
            && ($deny = $this->denyIfLocked($request, $response, $this->locks->forDate($supplierId, $refDate), 'invoice', null))) {
            return $deny;
        }
        // Podané OSS přiznání import neblokuje: převzatý řádek je vždy tuzemský.
        if ($refDate !== null && ($filed = $this->filedPeriods->describe($supplierId, $refDate, includeOss: false)) !== null) {
            return Json::error(
                $response,
                'period_filed',
                'Období dokladu už bylo podáno (' . $filed . '). Převzatý doklad by do něj dodatečně '
                    . 'přibyl — zaevidujte ho a podejte opravné nebo dodatečné tvrzení.',
                409,
            );
        }

        // § 29 odst. 2 ZDPH — stejná brána jako při vystavení.
        if ($doc['reverse_charge'] && KontrolniHlaseniBuilder::cleanDic((string) ($client['dic'] ?? '')) === '') {
            return Json::error(
                $response,
                'reverse_charge_dic_missing',
                'Doklad v režimu přenesené daňové povinnosti musí nést DIČ odběratele '
                    . '(§ 29 odst. 2 ZDPH). Bez něj plnění nelze uvést v kontrolním hlášení. '
                    . 'Doplňte DIČ u odběratele, nebo režim přenesené povinnosti vypněte.',
                422,
            );
        }

        // § 108 odst. 4 ZDPH — rozhoduje stav k rozhodnému datu dokladu ({@see IssueInvoiceAction}).
        if (abs($doc['total_vat']) > 0.0 && !$doc['reverse_charge']
            && !$this->vatStatus->isVatPayerAt($supplierId, $taxDate)) {
            return Json::error(
                $response,
                'not_vat_payer_at_date',
                sprintf(
                    'Firma není k rozhodnému datu dokladu (%s) plátcem DPH — doklad s DPH nelze vystavit. '
                        . 'Přepněte položky na sazbu 0 %%, nebo opravte datum zdanitelného plnění.',
                    date('j. n. Y', strtotime($taxDate)),
                ),
                422,
            );
        }

        $this->applyClassificationDefaults($supplierId, $doc, $items, $client, $taxDate);

        try {
            $snapshots = $this->snapshots->build($doc['client_id'], $currency['id'], $supplierId, null, $taxDate);
        } catch (\RuntimeException $e) {
            return Json::internalError($response, 'snapshot_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                supplierId: $supplierId,
                invoiceType: $doc['invoice_type'],
                clientId: $doc['client_id'],
                varsymbol: $doc['varsymbol'],
                issueDate: $doc['issue_date'],
                taxDate: $taxDate,
                dueDate: $doc['due_date'],
                currencyId: $currency['id'],
                exchangeRate: $exchangeRate,
                pricesIncludeVat: $doc['prices_include_vat'],
                reverseCharge: $doc['reverse_charge'],
                noteAboveItems: $doc['note_above_items'],
                noteBelowItems: $doc['note_below_items'],
                clientSnapshot: (string) json_encode($snapshots['client'], JSON_UNESCAPED_UNICODE),
                totalWithoutVat: $doc['total_without_vat'],
                totalVat: $doc['total_vat'],
                totalWithVat: $doc['total_with_vat'],
                rounding: $doc['rounding'],
                status: 'issued',
                createdBy: $userId,
                paymentVariableSymbol: $doc['payment_variable_symbol'],
                vatClassificationCode: $doc['vat_classification_code'],
                paymentMethod: $doc['payment_method'],
                supplierSnapshot: (string) json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
                bankSnapshot: $snapshots['bank'] !== null ? (string) json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
                parentInvoiceId: $parentId,
                correctiveDeliveredOn: $doc['invoice_type'] === 'credit_note' ? $doc['corrective_delivered_on'] : null,
            ));
            foreach ($items as $i => $item) {
                $this->writer->insertIssuedItem($id, MigratedDocumentItem::issued(
                    $item['description'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    (int) $item['vat_rate_id'],
                    (float) $item['vat_rate'],
                    $item['total_without_vat'],
                    $item['total_vat'],
                    $item['total_with_vat'],
                    $item['vat_classification_code'],
                    // OSS prodej import nepřebírá (openapi.yaml) — řádek je tuzemský.
                    OssMigrationPolicy::DOMESTIC_COLUMNS,
                ), $i);
            }
            $this->clients->markAsCustomer($doc['client_id'], $supplierId);
            if ($ownTransaction) $pdo->commit();
        } catch (\PDOException $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            // Souběžný import téhož čísla: předběžná kontrola výš ho nezachytila,
            // unikátní index ano. I tady vracíme id existujícího dokladu.
            if (self::varsymbolDuplicateMessage($e, $doc['varsymbol']) !== null) {
                return $this->duplicate($response, $doc['varsymbol'], $this->existingId($supplierId, $doc['varsymbol']));
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // Číselná řada nesmí příště přidělit číslo, které už má převzatý doklad.
        try {
            $this->varsymbol->syncCounter(
                $supplierId,
                $doc['invoice_type'],
                new \DateTimeImmutable($doc['issue_date']),
                $doc['client_id'],
            );
        } catch (\Throwable) {
            // best-effort dorovnání stejně jako u importu souborů; next() kolizi přeskočí sám
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $this->logger->log('invoice.imported', $userId, 'invoice', $id, [
            'varsymbol' => $doc['varsymbol'],
            'type'      => $doc['invoice_type'],
            'total'     => $doc['total_with_vat'],
            'currency'  => $currency['code'],
            'source'    => 'api',
        ], $ip, $ua);

        $this->autoPoster->maybeAutoPost($supplierId, 'invoice', $id, $userId, $ip, $ua);

        // Zápočet dobropisu proti opravované faktuře — měkce, stejně jako při vystavení.
        if ($doc['invoice_type'] === 'credit_note') {
            try {
                $this->creditNoteOffsets->autoApplyForInvoice($supplierId, $id, $userId);
            } catch (\Throwable $e) {
                $this->logger->log('invoice.credit_note_offset_failed', $userId, 'invoice', $id, [
                    'error' => $e->getMessage(),
                ], $ip, $ua);
            }
        }

        // Stejně jako po vystavení: cache až za zaúčtováním a její chyba import neshodí.
        $this->stats->recomputeAfterCommit($id);

        return Json::ok($response, $this->repo->find($id), 201);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     * @return array<string,mixed>
     */
    private function parseHeader(array $body, array &$errors): array
    {
        $type = (string) ($body['invoice_type'] ?? 'invoice');
        if (!in_array($type, self::TYPES, true)) {
            $errors['invoice_type'][] = 'Importovat lze jen fakturu (invoice) nebo dobropis (credit_note).';
        }

        $varsymbol = is_scalar($body['varsymbol'] ?? null) ? trim((string) $body['varsymbol']) : '';
        if ($varsymbol === '') {
            $errors['varsymbol'][] = 'Číslo dokladu je povinné.';
        } elseif (mb_strlen($varsymbol) > 20) {
            $errors['varsymbol'][] = 'Číslo dokladu může mít nejvýše 20 znaků.';
        }

        $clientId = is_numeric($body['client_id'] ?? null) ? (int) $body['client_id'] : 0;
        if ($clientId <= 0) {
            $errors['client_id'][] = 'Odběratel je povinný.';
        }

        $taxDate = self::date($body['tax_date'] ?? null);
        if ($taxDate === null) {
            $errors['tax_date'][] = 'Datum zdanitelného plnění (Y-m-d) je povinné.';
        }
        $issueDate = array_key_exists('issue_date', $body) && $body['issue_date'] !== null
            ? self::date($body['issue_date'])
            : $taxDate;
        if ($issueDate === null && ($body['issue_date'] ?? null) !== null) {
            $errors['issue_date'][] = 'Neplatné datum vystavení (Y-m-d).';
        }
        $dueDate = array_key_exists('due_date', $body) && $body['due_date'] !== null
            ? self::date($body['due_date'])
            : $issueDate;
        if ($dueDate === null && ($body['due_date'] ?? null) !== null) {
            $errors['due_date'][] = 'Neplatné datum splatnosti (Y-m-d).';
        }
        $delivered = null;
        if (($body['corrective_delivered_on'] ?? null) !== null) {
            $delivered = self::date($body['corrective_delivered_on']);
            if ($delivered === null) {
                $errors['corrective_delivered_on'][] = 'Neplatné datum doručení opravného dokladu (Y-m-d).';
            } elseif ($type !== 'credit_note') {
                $errors['corrective_delivered_on'][] = 'Datum doručení se uvádí jen u dobropisu.';
            }
        }

        if (!is_bool($body['prices_include_vat'] ?? null)) {
            $errors['prices_include_vat'][] = 'Režim cen (s DPH / bez DPH) je povinný.';
        }
        if (array_key_exists('reverse_charge', $body) && !is_bool($body['reverse_charge'])) {
            $errors['reverse_charge'][] = 'Očekává se true nebo false.';
        }

        $paymentMethod = $body['payment_method'] ?? null;
        if ($paymentMethod === null || $paymentMethod === '') {
            $paymentMethod = PaymentMethods::DEFAULT;
        } elseif (!PaymentMethods::isValid($paymentMethod)) {
            $errors['payment_method'][] = 'Neplatný způsob úhrady';
        }
        $pvs = null;
        try {
            $pvs = InvoiceRepository::normalizePaymentVariableSymbol($body['payment_variable_symbol'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $errors['payment_variable_symbol'][] = $e->getMessage();
        }

        $totals = [];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $key) {
            $totals[$key] = self::number($body[$key] ?? null);
            if ($totals[$key] === null) {
                $errors[$key][] = 'Částka je povinná.';
            }
        }
        $rounding = array_key_exists('rounding', $body) && $body['rounding'] !== null ? self::number($body['rounding']) : 0.0;
        if ($rounding === null) {
            $errors['rounding'][] = 'Neplatná částka zaokrouhlení.';
        }

        $parentInvoiceId = null;
        if (($body['parent_invoice_id'] ?? null) !== null) {
            $parentInvoiceId = is_numeric($body['parent_invoice_id']) ? (int) $body['parent_invoice_id'] : 0;
            if ($parentInvoiceId <= 0) {
                $errors['parent_invoice_id'][] = 'Neplatné id opravované faktury.';
            }
        }
        $parentVarsymbol = is_scalar($body['parent_varsymbol'] ?? null) ? trim((string) $body['parent_varsymbol']) : '';
        if (($parentInvoiceId !== null || $parentVarsymbol !== '') && $type !== 'credit_note') {
            $errors['parent_invoice_id'][] = 'Opravovanou fakturu lze uvést jen u dobropisu.';
        }

        return [
            'invoice_type'            => $type,
            'varsymbol'               => $varsymbol,
            'client_id'               => $clientId,
            'issue_date'              => (string) $issueDate,
            'tax_date'                => (string) $taxDate,
            'due_date'                => (string) $dueDate,
            'corrective_delivered_on' => $delivered,
            'prices_include_vat'      => ($body['prices_include_vat'] ?? null) === true,
            'reverse_charge'          => ($body['reverse_charge'] ?? false) === true,
            'payment_method'          => (string) $paymentMethod,
            'payment_variable_symbol' => $pvs,
            'note_above_items'        => self::text($body['note_above_items'] ?? null),
            'note_below_items'        => self::text($body['note_below_items'] ?? null),
            'vat_classification_code' => self::code($body['vat_classification_code'] ?? null),
            'total_without_vat'       => (float) $totals['total_without_vat'],
            'total_vat'               => (float) $totals['total_vat'],
            'total_with_vat'          => (float) $totals['total_with_vat'],
            'rounding'                => (float) $rounding,
            'parent_invoice_id'       => $parentInvoiceId,
            'parent_varsymbol'        => $parentVarsymbol !== '' ? $parentVarsymbol : null,
        ];
    }

    /**
     * @param array<string,list<string>> $errors
     * @return list<array<string,mixed>>
     */
    private function parseItems(mixed $raw, array &$errors): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            $errors['items'][] = 'Doklad musí obsahovat alespoň jednu položku.';
            return [];
        }
        $items = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                $errors["items.{$i}"][] = 'Položka musí být objekt.';
                continue;
            }
            $description = is_scalar($row['description'] ?? null) ? trim((string) $row['description']) : '';
            if ($description === '') {
                $errors["items.{$i}.description"][] = 'Popis položky je povinný.';
            }
            $item = [
                'description' => $description,
                'unit'        => is_scalar($row['unit'] ?? null) && trim((string) $row['unit']) !== '' ? trim((string) $row['unit']) : 'ks',
                'vat_classification_code' => self::code($row['vat_classification_code'] ?? null),
                'vat_rate_id' => null,
                'vat_rate'    => null,
            ];
            foreach (['quantity', 'unit_price_without_vat', 'total_without_vat', 'total_vat', 'total_with_vat'] as $key) {
                $item[$key] = self::number($row[$key] ?? null);
                if ($item[$key] === null) {
                    $errors["items.{$i}.{$key}"][] = 'Hodnota je povinná a musí být číslo.';
                }
            }
            if (($row['vat_rate_id'] ?? null) !== null) {
                $item['vat_rate_id'] = is_numeric($row['vat_rate_id']) ? (int) $row['vat_rate_id'] : 0;
                if ($item['vat_rate_id'] <= 0) {
                    $errors["items.{$i}.vat_rate_id"][] = 'Neplatné id sazby DPH.';
                }
            } else {
                $item['vat_rate'] = self::number($row['vat_rate'] ?? null);
                if ($item['vat_rate'] === null || $item['vat_rate'] < 0) {
                    $errors["items.{$i}.vat_rate"][] = 'Uveďte sazbu DPH v procentech (vat_rate) nebo její id (vat_rate_id).';
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Částky dokladu se neupravují, jen ověřují. Řádek musí sedět sám se sebou na haléř,
     * daň řádku na sazbu v toleranci převzatého dokladu (zaokrouhlení daně po dokladu),
     * součty řádků na hlavičku na haléř. Znaménko řídí druh dokladu: dobropis aplikace
     * nese se zápornými řádky ({@see CancelInvoiceAction}), takže i převzatý musí.
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $items
     * @return array<string,list<string>>
     */
    private function amountErrors(array $doc, array $items): array
    {
        $errors = [];
        $sumBase = 0.0;
        $sumVat = 0.0;
        $sumGross = 0.0;
        foreach ($items as $i => $item) {
            $base = (float) $item['total_without_vat'];
            $vat = (float) $item['total_vat'];
            $gross = (float) $item['total_with_vat'];
            if (abs(round($base + $vat - $gross, 2)) > self::CENT) {
                $errors["items.{$i}.total_with_vat"][] = 'Částka s DPH neodpovídá součtu základu a DPH položky.';
            }
            // Přenesená povinnost: řádek daň nenese, ať je na něm sazba jakákoli (InvoiceMath).
            $expectedVat = $doc['reverse_charge'] ? 0.0 : $base * (float) $item['vat_rate'] / 100;
            if (abs($vat - $expectedVat) > ImportedIssuedDocumentPolicy::TOTAL_TOLERANCE) {
                $errors["items.{$i}.total_vat"][] = sprintf(
                    'DPH položky %s neodpovídá sazbě %s %% ze základu %s.',
                    number_format($vat, 2, ',', ' '),
                    rtrim(rtrim(number_format((float) $item['vat_rate'], 2, ',', ''), '0'), ','),
                    number_format($base, 2, ',', ' '),
                );
            }
            $sumBase += $base;
            $sumVat += $vat;
            $sumGross += $gross;
        }

        if (abs(round($sumBase - $doc['total_without_vat'], 2)) > self::CENT) {
            $errors['total_without_vat'][] = 'Součet základů položek neodpovídá základu dokladu.';
        }
        if (abs(round($sumVat - $doc['total_vat'], 2)) > self::CENT) {
            $errors['total_vat'][] = 'Součet DPH položek neodpovídá DPH dokladu.';
        }
        if (abs(round($sumGross + $doc['rounding'] - $doc['total_with_vat'], 2)) > self::CENT) {
            $errors['total_with_vat'][] = 'Součet položek s DPH a zaokrouhlení neodpovídá celkové částce dokladu.';
        }
        if ($doc['invoice_type'] === 'credit_note' && $doc['total_with_vat'] >= 0) {
            $errors['total_with_vat'][] = 'Dobropis se importuje se zápornými částkami.';
        }
        if ($doc['invoice_type'] === 'invoice' && $doc['total_with_vat'] <= 0) {
            $errors['total_with_vat'][] = 'Faktura musí mít kladnou celkovou částku.';
        }

        return $errors;
    }

    /**
     * Zadaný kód členění musí existovat v číselníku firmy (globální nebo její vlastní).
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $items
     * @return array<string,list<string>>
     */
    private function classificationCodeErrors(int $supplierId, array $doc, array $items): array
    {
        $codes = ['vat_classification_code' => $doc['vat_classification_code']];
        foreach ($items as $i => $item) {
            $codes["items.{$i}.vat_classification_code"] = $item['vat_classification_code'];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM vat_classifications WHERE code = ? AND (supplier_id = ? OR supplier_id IS NULL) LIMIT 1'
        );
        $errors = [];
        foreach ($codes as $field => $code) {
            if ($code === null) {
                continue;
            }
            $stmt->execute([$code, $supplierId]);
            if ($stmt->fetchColumn() === false) {
                $errors[$field][] = "Kód členění plnění '{$code}' v číselníku neexistuje.";
            }
        }

        return $errors;
    }

    /**
     * Chybějící členění se dopočítá stejnými SSOT jako u dokladu z aplikace: řádek přes
     * {@see InvoiceRepository::defaultSaleClassificationCode()} (zápis položek vystavené
     * faktury), hlavička přes {@see VatClassificationDefaulter::suggestHeaderForInvoice()}
     * ({@see CreateInvoiceAction}). Vlastní mapování sazba → řádek přiznání tu být nesmí.
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $client
     */
    private function applyClassificationDefaults(int $supplierId, array &$doc, array &$items, array $client, string $taxDate): void
    {
        $standardRate = $this->taxConstants->vatRateStandard((int) substr($taxDate, 0, 4));
        $iso = trim((string) ($client['country_iso2'] ?? ''));
        foreach ($items as &$item) {
            if ($item['vat_classification_code'] !== null) {
                continue;
            }
            $item['vat_classification_code'] = InvoiceRepository::defaultSaleClassificationCode(
                (float) $item['vat_rate'],
                $doc['reverse_charge'],
                $iso !== '' ? $iso : null,
                $item['unit'] !== '' ? $item['unit'] : null,
                $standardRate,
            );
        }
        unset($item);

        if ($doc['vat_classification_code'] === null) {
            $doc['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                array_map(static fn (array $it): array => [
                    'vat_rate'       => (float) $it['vat_rate'],
                    'total_with_vat' => (float) $it['total_with_vat'],
                    'unit'           => (string) $it['unit'],
                ], $items),
                $doc['reverse_charge'],
                'sale',
                $taxDate,
                $supplierId,
                $doc['reverse_charge'] && $this->repo->clientIsEuForeign($doc['client_id']),
            );
        }
    }

    /**
     * Měna stejně jako u zakládání draftu ({@see InvoiceDefaults::resolve()}): `currency_id`,
     * jinak kód `currency` v rámci firmy, jinak výchozí měna odběratele/firmy. Z resolveru
     * se bere jen měna — ostatní výchozí hodnoty (splatnost, poznámka) převzatý doklad nese sám.
     *
     * @param array<string,mixed> $body
     * @return array{id:int, code:string}|null
     */
    private function resolveCurrency(int $supplierId, int $clientId, array $body): ?array
    {
        try {
            $resolved = $this->defaults->resolve([
                'client_id'   => $clientId,
                'currency_id' => $body['currency_id'] ?? null,
                'currency'    => $body['currency'] ?? null,
            ]);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, code FROM currencies WHERE id = ? AND supplier_id = ?');
        $stmt->execute([(int) ($resolved['currency_id'] ?? 0), $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? ['id' => (int) $row['id'], 'code' => strtoupper((string) $row['code'])] : null;
    }

    private function resolveParent(int $supplierId, ?int $parentId, ?string $parentVarsymbol): ?int
    {
        $sql = "SELECT id FROM invoices
                 WHERE supplier_id = ? AND invoice_type = 'invoice' AND status NOT IN ('draft', 'cancelled')";
        $params = [$supplierId];
        if ($parentId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $parentId;
        }
        if ($parentVarsymbol !== null) {
            $sql .= ' AND varsymbol = ?';
            $params[] = $parentVarsymbol;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function existingId(int $supplierId, string $varsymbol): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1');
        $stmt->execute([$supplierId, $varsymbol]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Opakovaný import téhož čísla (retry e-shopu) se pozná podle `existing_invoice_id`. */
    private function duplicate(Response $response, string $varsymbol, ?int $existingId): Response
    {
        return Json::error(
            $response,
            'varsymbol_duplicate',
            "Doklad číslo '{$varsymbol}' už u této firmy existuje.",
            409,
            ['existing_invoice_id' => $existingId],
        );
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private static function code(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
