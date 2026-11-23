<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\JournalEntryTemplateRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\TemplateCsvMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Šablony ručních zápisů (Fáze F, audit 2026-07 — mzdy a leasing se každý měsíc
 * vyklikávaly ručně znovu). Čistě datové CRUD nad journal_entry_templates(_lines);
 * vytvoření zápisu ze šablony jde vždy přes POST /accounting/journal — šablona jen
 * FE (ManualEntry.vue) předvyplní řádky, PostingService zůstává jediné místo, které
 * cokoli zaúčtovává.
 *
 *   GET    /api/accounting/journal-templates                — seznam (+ lazy seed „Mzdy" a předuzávěrkových šablon, Task 34)
 *   POST   /api/accounting/journal-templates                 — uložit rozepsaný zápis jako šablonu
 *   GET    /api/accounting/journal-templates/{id}             — detail s řádky
 *   PUT    /api/accounting/journal-templates/{id}             — upravit šablonu a její řádky
 *   DELETE /api/accounting/journal-templates/{id}             — smazat
 *   POST   /api/accounting/journal-templates/{id}/import-csv  — napárovat CSV rekapitulaci na řádky šablony
 */
final class JournalTemplateAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_CSV_BYTES = 512 * 1024;
    private const MAX_LINES = 100;

    public function __construct(
        private readonly JournalEntryTemplateRepository $templates,
        private readonly TemplateCsvMatcher $matcher,
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        if (!DemoReadOnlyMiddleware::enabled($request)) {
            $this->templates->ensurePayrollSeed($supplierId);
            $this->templates->ensureClosingTemplatesSeed($supplierId);
        }
        return Json::ok($response, ['items' => $this->templates->listForSupplier($supplierId)]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $template = $this->templates->find($supplierId, $id);
        if ($template === null) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $this->withDimensions($supplierId, $template));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return Json::error($response, 'validation_failed', 'Název šablony musí mít 1–255 znaků.', 422);
        }
        $description = $this->nullableString($body['description'] ?? null);
        if ($description !== null && mb_strlen($description) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        $lineDims = [];
        $lines = $this->parseLines($body, $response, $err, $lineDims);
        if ($lines === null) return $err;

        $id = $this->templates->create($supplierId, $name, $description, $this->userId($request), $lines);
        if ($err = $this->saveDimensions($supplierId, $id, $lineDims, $response)) return $err;
        return Json::ok($response, $this->withDimensions($supplierId, (array) $this->templates->find($supplierId, $id)), 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        if (!$this->templates->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $this->dimensions->forgetDocument($supplierId, 'journal_template', $id);
        return Json::ok($response, ['ok' => true]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        // Chybějící klíč = uložená hodnota; bez klíče lines zůstávají řádky i jejich dimenze.
        $current = $this->templates->find($supplierId, $id);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $name = trim((string) (array_key_exists('name', $body) ? $body['name'] : $current['name']));
        if ($name === '' || mb_strlen($name) > 255) {
            return Json::error($response, 'validation_failed', 'Název šablony musí mít 1–255 znaků.', 422);
        }
        $description = $this->nullableString(array_key_exists('description', $body) ? $body['description'] : $current['description']);
        if ($description !== null && mb_strlen($description) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        if (!array_key_exists('lines', $body) && !array_key_exists('pairs', $body)) {
            $this->templates->update($supplierId, $id, $name, $description, null);
            return Json::ok($response, $this->withDimensions($supplierId, (array) $this->templates->find($supplierId, $id)));
        }
        $lineDims = [];
        $lines = $this->parseLines($body, $response, $err, $lineDims);
        if ($lines === null) return $err;

        if (!$this->templates->update($supplierId, $id, $name, $description, $lines)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if ($err = $this->saveDimensions($supplierId, $id, $lineDims, $response)) return $err;
        return Json::ok($response, $this->withDimensions($supplierId, (array) $this->templates->find($supplierId, $id)));
    }

    /**
     * POST /journal-templates/{id}/import-csv — jen NÁHLED (žádný zápis do DB): napáruje
     * CSV na řádky šablony a vrátí je s doplněnými částkami; FE výsledkem předvyplní
     * ManualEntry, uživatel zápis uloží běžným POST /accounting/journal.
     */
    public function importCsv(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $template = $this->templates->find($supplierId, $id);
        if ($template === null) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'bad_file', 'Nahrajte CSV soubor.', 415);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_CSV_BYTES) {
            return Json::error($response, 'bad_file', 'Soubor je větší než 512 kB.', 415);
        }
        $filename = (string) ($file->getClientFilename() ?? '');
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return Json::error($response, 'bad_file', 'Podporovaný formát je CSV.', 415);
        }
        $content = (string) $file->getStream()->getContents();
        if ($content === '' || str_contains($content, "\0")) {
            return Json::error($response, 'bad_file', 'Soubor nelze přečíst jako CSV.', 415);
        }

        return Json::ok($response, $this->matcher->match($template['lines'], $content));
    }

    /**
     * Řádky šablony z těla požadavku. Buď `lines` jako dosud (volitelně s `pair_no`
     * a `line_text` pro jednoduchý zápis), nebo `pairs`: dvojice
     * {debit_account_code, credit_account_code, amount?, label?, line_text?, cost_center?},
     * ze kterých vzniknou dva řádky se stejným číslem dvojice. Dimenze se zadávají jen
     * přes `lines` (patří na výsledkový řádek, ne na celou dvojici).
     *
     * @param array<string,mixed> $body
     * @param array<int,array<int|string,mixed>> $lineDims
     * @return list<array<string,mixed>>|null
     */
    private function parseLines(array $body, Response $response, ?Response &$err, array &$lineDims): ?array
    {
        $err = null;
        $rawPairs = $body['pairs'] ?? null;
        if ($rawPairs !== null) {
            if (!is_array($rawPairs) || $rawPairs === [] || count($rawPairs) * 2 > self::MAX_LINES) {
                $err = Json::error($response, 'validation_failed', 'Šablona musí mít 1 až ' . intdiv(self::MAX_LINES, 2) . ' dvojic.', 422);
                return null;
            }
            $rawLines = [];
            foreach (array_values($rawPairs) as $i => $pair) {
                if (!is_array($pair)) {
                    $err = Json::error($response, 'validation_failed', 'Dvojice #' . $i . ' má neplatný formát.', 422, ['pair_index' => $i]);
                    return null;
                }
                foreach (['debit' => 'debit_account_code', 'credit' => 'credit_account_code'] as $side => $key) {
                    $rawLines[] = [
                        'account_code' => $pair[$key] ?? '',
                        'side'         => $side,
                        'amount'       => $pair['amount'] ?? null,
                        'label'        => $pair['label'] ?? null,
                        'line_text'    => $pair['line_text'] ?? null,
                        'cost_center'  => $pair['cost_center'] ?? null,
                        'pair_no'      => $i + 1,
                    ];
                }
            }
        } else {
            $rawLines = $body['lines'] ?? null;
            if (!is_array($rawLines) || $rawLines === []) {
                $err = Json::error($response, 'validation_failed', 'Šablona musí mít alespoň jeden řádek.', 422);
                return null;
            }
            if (count($rawLines) > self::MAX_LINES) {
                $err = Json::error($response, 'validation_failed', 'Šablona smí mít nejvýše ' . self::MAX_LINES . ' řádků.', 422);
                return null;
            }
        }

        $lines = [];
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) {
                $err = Json::error($response, 'validation_failed', "Řádek #{$i} má neplatný formát.", 422);
                return null;
            }
            if (!empty($l['is_red_storno'])) {
                $err = Json::error($response, 'red_storno_template_unsupported', 'Červené storno nelze uložit do šablony.', 422);
                return null;
            }
            $accountCode = trim((string) ($l['account_code'] ?? ''));
            if ($accountCode === '' || mb_strlen($accountCode) > 20) {
                $code = isset($l['pair_no'], $rawPairs)
                    ? ($l['side'] === 'debit' ? JournalPairInvariant::MISSING_DEBIT_ACCOUNT : JournalPairInvariant::MISSING_CREDIT_ACCOUNT)
                    : 'validation_failed';
                $err = Json::error($response, $code, "Řádek #{$i}: chybí kód účtu.", 422);
                return null;
            }
            $side = (string) ($l['side'] ?? '');
            if ($side !== 'debit' && $side !== 'credit') {
                $err = Json::error($response, 'validation_failed', "Řádek #{$i}: side musí být 'debit' nebo 'credit'.", 422);
                return null;
            }
            $amount = null;
            if (array_key_exists('amount', $l) && $l['amount'] !== null && $l['amount'] !== '') {
                if (!is_numeric($l['amount']) || (float) $l['amount'] < 0) {
                    $err = Json::error($response, 'validation_failed', "Řádek #{$i}: částka musí být nezáporné číslo.", 422);
                    return null;
                }
                $amount = round((float) $l['amount'], 2);
            }
            $line = [
                'account_code' => $accountCode,
                'side'         => $side,
                'amount'       => $amount,
                'label'        => $this->nullableString($l['label'] ?? null),
                'cost_center'  => $this->nullableString($l['cost_center'] ?? null),
            ];
            if (isset($l['pair_no']) && $l['pair_no'] !== '') {
                $pairNo = JournalPairInvariant::pairNoOf($l);
                if ($pairNo === null || $pairNo < 1) {
                    $err = Json::error($response, JournalPairInvariant::NUMBERING, JournalPairInvariant::message(JournalPairInvariant::NUMBERING), 422);
                    return null;
                }
                $line['pair_no'] = $pairNo;
                $text = $this->nullableString($l['line_text'] ?? null);
                if ($text !== null) {
                    $line['line_text'] = mb_substr($text, 0, 255);
                }
            }
            $lines[] = $line;
            if (array_key_exists('dimensions', $l)) {
                $lineDims[count($lines)] = is_array($l['dimensions']) ? $l['dimensions'] : [];
            }
        }

        $violation = self::pairViolation($lines);
        if ($violation !== null) {
            $err = Json::error($response, $violation[0], JournalPairInvariant::message($violation[0]), 422, ['pair_no' => $violation[1]]);
            return null;
        }
        return $lines;
    }

    /**
     * Dvojice šablony: buď nemá číslo dvojice žádný řádek, nebo všechny; každá dvojice
     * má řádek MD a Dal s různými účty a stejnou výchozí částkou (prázdná = doplní se
     * při použití). Kladnou částku šablona nevyžaduje, tu hlídá až zápis.
     *
     * @param list<array<string,mixed>> $lines
     * @return array{string,?int}|null
     */
    private static function pairViolation(array $lines): ?array
    {
        $paired = array_filter($lines, static fn (array $l): bool => isset($l['pair_no']));
        if ($paired === []) {
            return null;
        }
        if (count($paired) !== count($lines)) {
            return [JournalPairInvariant::INCOMPLETE, null];
        }
        $groups = [];
        $order = [];
        foreach ($lines as $line) {
            if (!isset($groups[$line['pair_no']])) {
                $order[] = $line['pair_no'];
            }
            $groups[$line['pair_no']][] = $line;
        }
        if ($order !== range(1, count($order))) {
            return [JournalPairInvariant::NUMBERING, null];
        }
        foreach ($groups as $pairNo => $pair) {
            if (count($pair) !== 2 || $pair[0]['side'] === $pair[1]['side'] || $pair[0]['amount'] !== $pair[1]['amount']) {
                return [JournalPairInvariant::UNBALANCED, $pairNo];
            }
            if ($pair[0]['account_code'] === $pair[1]['account_code']) {
                return [JournalPairInvariant::SAME_ACCOUNT, $pairNo];
            }
        }
        return null;
    }

    /**
     * Dimenze řádků šablony (Firma → Dimenze) — podle pořadí řádku od 1. Ukládají se
     * jen u firmy se zapnutými dimenzemi; šablona je pak předvyplní do ručního zápisu.
     *
     * @param array<int,array<int|string,mixed>> $lineDims pořadí (od 1) => mapa typ => hodnota
     */
    private function saveDimensions(int $supplierId, int $templateId, array $lineDims, Response $response): ?Response
    {
        if (!$this->dimensions->enabled($supplierId)) {
            return null;
        }
        // Klient, který dimenze neposílá (starší rozhraní, API), je nesmaže.
        if ($lineDims === []) {
            return null;
        }
        try {
            $this->dimensions->saveDocument($supplierId, 'journal_template', $templateId, [], $lineDims);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    private function withDimensions(int $supplierId, array $template): array
    {
        $dims = $this->dimensions->documentDimensions($supplierId, 'journal_template', (int) $template['id'])['items'];
        foreach ((array) ($template['lines'] ?? []) as $i => $line) {
            $template['lines'][$i]['dimensions'] = (object) ($dims[$i + 1] ?? []);
        }
        return $template;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }
        return null;
    }
}
