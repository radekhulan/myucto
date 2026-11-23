<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselné řady účetního deníku (Epic F4, R13) — UZ/OT/KR/PP/ID, pokladní,
 * skladové a další řady per firma a rok. Řádek řady vzniká lazy při prvním
 * výdeji čísla; tady se čte a edituje prefix, tvar čísla i čítač.
 *
 *   GET /api/accounting/document-series               — seznam řad firmy
 *   PUT /api/accounting/document-series/{code}/{year} — prefix / number_format / next_number — účetní|admin
 *
 * Body PUT (aspoň jedna položka + volitelný scope):
 *   { "prefix": "26HP", "number_format": "{PREFIX}{CCCCC}", "next_number": 11, "register_id": 0 }
 *
 * `register_id` > 0 míří na vlastní řadu pokladny (L-3, u cash_in / cash_out) nebo druhu
 * ostatní položky (u other_receivable / other_payable), 0 / vynechané na společnou řadu firmy.
 *
 * Daňová evidence vidí a edituje jen řady, které umí vydat (pokladní, skladové,
 * objednávkové); deníkové řady ({@see DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES})
 * se jí nenabízejí a PUT na ně skončí `wrong_accounting_mode`.
 *
 * `number_format` = "" nebo null vrátí řadu na vestavěné `{PREFIX}-{YYYY}-{CCCC}`,
 * `next_number` je číslo PŘÍŠTÍHO vydaného dokladu (#22 — převzetí řady z jiného
 * systému; stejná sémantika jako PUT /settings/supplier/invoice-counter).
 */
final class DocumentSeriesAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Editovatelné jsou všechny řady, které umí vydat číslo. */
    private const SERIES_CODES = DocumentSeriesService::DEFAULT_PREFIXES;

    public function __construct(
        private readonly DocumentSeriesService $series,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        return Json::ok($response, $this->visibleSeries($supplierId));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);

        $code = (string) ($args['code'] ?? '');
        if (!isset(self::SERIES_CODES[$code])) {
            return Json::error($response, 'validation_failed',
                'Neznámá řada — povolené: ' . implode(', ', array_keys(self::SERIES_CODES)) . '.', 422);
        }
        // Deníkové řady umí vydat číslo jen podvojné účetnictví; pokladní, skladové
        // a objednávkové řady používá i daňová evidence.
        if (!$this->seriesAllowedForMode($supplierId, $code)
            && !$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2000 || $year > 2200) {
            return Json::error($response, 'validation_failed', 'Rok řady musí být rozumný účetní rok.', 422);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $changes = [];

        if (array_key_exists('prefix', $body)) {
            $changes['prefix'] = strtoupper(trim((string) $body['prefix']));
        }
        if (array_key_exists('number_format', $body)) {
            $changes['number_format'] = $body['number_format'];
        }
        // L-3: řada pokladny (register_id > 0) je samostatný řádek — bez scope by se
        // editace vlastní řady propsala do společné řady firmy.
        $registerId = 0;
        if (array_key_exists('register_id', $body) && trim((string) $body['register_id']) !== '') {
            if (!is_numeric($body['register_id']) || (int) $body['register_id'] < 0) {
                return Json::error($response, 'validation_failed', 'register_id musí být nezáporné celé číslo.', 422);
            }
            $registerId = (int) $body['register_id'];
        }
        $isCash = in_array($code, ['cash_in', 'cash_out'], true);
        if ($registerId > 0 && !$isCash && !in_array($code, ['other_receivable', 'other_payable'], true)) {
            return Json::error($response, 'validation_failed',
                'Vlastní řadu má jen pokladna nebo druh ostatní položky — register_id lze zadat u cash_in / cash_out / other_receivable / other_payable.', 422);
        }
        // `accounting_document_series.register_id` nemá FK (0 = společná řada firmy),
        // takže bez téhle kontroly vznikne osiřelý řádek řady na neexistující (nebo
        // cizí) pokladnu či druh — v Nástrojích pak svítí řada, kterou nikdo nikdy nevydá.
        if ($registerId > 0 && $isCash && !$this->registerExists($supplierId, $registerId)) {
            return Json::error($response, 'validation_failed',
                'Pokladna register_id=' . $registerId . ' u této firmy neexistuje.', 422);
        }
        if ($registerId > 0 && !$isCash && !$this->kindSeriesExists($supplierId, $registerId, $code)) {
            return Json::error($response, 'validation_failed',
                'Druh s vlastní řadou register_id=' . $registerId . ' u této firmy pro tuto řadu neexistuje.', 422);
        }

        if (array_key_exists('next_number', $body) && trim((string) $body['next_number']) !== '') {
            if (!is_numeric($body['next_number'])) {
                return Json::error($response, 'validation_failed', 'next_number musí být celé číslo.', 422);
            }
            $changes['next_number'] = (int) $body['next_number'];
        }
        if ($changes === []) {
            return Json::error($response, 'validation_failed',
                'Zadej aspoň jedno z: prefix, number_format, next_number.', 422);
        }

        try {
            $this->series->updateSeries($supplierId, $code, $year, $changes, $registerId);
        } catch (ClosingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $this->visibleSeries($supplierId));
    }

    /** Deníkové řady se firmě v daňové evidenci nenabízejí — nemá je z čeho vydat. */
    private function visibleSeries(int $supplierId): array
    {
        $rows = $this->series->list($supplierId);
        if ($this->accountingModeIs($this->db, $supplierId, 'double_entry')) {
            return $rows;
        }
        return array_values(array_filter(
            $rows,
            fn(array $r): bool => !in_array((string) $r['series_code'], DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES, true),
        ));
    }

    private function registerExists(int $supplierId, int $registerId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT EXISTS (SELECT 1 FROM cash_registers WHERE id = ? AND supplier_id = ?)');
        $stmt->execute([$registerId, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Druh ostatní položky (vestavěný i vlastní) strany řady, který vlastní řadu má: zapnutou,
     * nebo vypnutou, ale už založenou — její prefix a čítač jde upravit i před opětovným
     * zapnutím. Druhu, který vlastní řadu nikdy neměl, se tudy nezakládá.
     */
    private function kindSeriesExists(int $supplierId, int $kindId, string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM other_item_kinds k
                             WHERE k.id = ? AND k.supplier_id = ? AND k.side = ?
                               AND (k.own_series = 1 OR EXISTS (SELECT 1 FROM accounting_document_series s
                                     WHERE s.supplier_id = k.supplier_id AND s.series_code = ? AND s.register_id = k.id)))'
        );
        $stmt->execute([$kindId, $supplierId, $code === 'other_receivable' ? 'receivable' : 'payable', $code]);
        return (bool) $stmt->fetchColumn();
    }

    private function seriesAllowedForMode(int $supplierId, string $code): bool
    {
        return !in_array($code, DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES, true)
            || $this->accountingModeIs($this->db, $supplierId, 'double_entry');
    }
}
