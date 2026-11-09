<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Storno a přeúčtování vybraných dvojic jednoduchého zápisu.
 *
 *   POST /api/accounting/journal/{id}/pairs/reverse           { pair_nos: [..], entry_date?, description? }
 *   POST /api/accounting/journal/{id}/pairs/{pairNo}/repost   { debit_account_code, credit_account_code, line_text?, entry_date?, description? }
 *
 * Action jen ověří právo a tvar vstupu; pravidla (zámky, doklady, mzdy) drží
 * {@see JournalPairCorrectionService}.
 */
final class JournalPairAction
{
    use AccountingActionSupport;

    private const MAX_PAIR_NO = 65535;

    public function __construct(
        private readonly JournalPairCorrectionService $corrections,
        private readonly JournalEntryRepository $journal,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** POST /api/accounting/journal/{id}/pairs/reverse */
    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $entryId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $raw = $body['pair_nos'] ?? null;
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_PAIR_NO) {
            return Json::error($response, 'validation_failed', 'pair_nos musí být neprázdný seznam čísel dvojic.', 422);
        }
        $pairNos = [];
        foreach ($raw as $value) {
            $pairNo = self::pairNo($value);
            if ($pairNo === null) {
                return Json::error($response, 'validation_failed', 'pair_nos musí obsahovat kladná celá čísla dvojic.', 422);
            }
            $pairNos[$pairNo] = $pairNo;
        }
        $meta = $this->meta($request, $body, $response, $err);
        if ($meta === null) return $err;

        try {
            $reversalId = $this->corrections->reversePairs($supplierId, $entryId, array_values($pairNos), $meta);
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, $this->journal->find($reversalId, $supplierId), 201);
    }

    /** POST /api/accounting/journal/{id}/pairs/{pairNo}/repost */
    public function repost(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $entryId = (int) ($args['id'] ?? 0);
        $pairNo = self::pairNo($args['pairNo'] ?? null);
        if ($pairNo === null) {
            return Json::error($response, 'validation_failed', 'Neplatné číslo dvojice.', 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $debit = is_string($body['debit_account_code'] ?? null) ? trim($body['debit_account_code']) : '';
        $credit = is_string($body['credit_account_code'] ?? null) ? trim($body['credit_account_code']) : '';
        if ($debit === '') {
            return Json::error($response, 'pair_missing_debit_account', 'Dvojici chybí účet MD.', 422);
        }
        if ($credit === '') {
            return Json::error($response, 'pair_missing_credit_account', 'Dvojici chybí účet Dal.', 422);
        }
        $newPair = ['debit_account_code' => mb_substr($debit, 0, 20), 'credit_account_code' => mb_substr($credit, 0, 20)];
        $text = is_string($body['line_text'] ?? null) ? trim($body['line_text']) : '';
        if ($text !== '') {
            $newPair['line_text'] = mb_substr($text, 0, 255);
        }
        $meta = $this->meta($request, $body, $response, $err);
        if ($meta === null) return $err;

        try {
            $result = $this->corrections->repostPair($supplierId, $entryId, $pairNo, $newPair, $meta);
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, [
            'mode'    => $result['mode'],
            'pair_no' => $result['pair_no'],
            'entry'   => $this->journal->find($result['entry_id'], $supplierId),
        ]);
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function meta(Request $request, array $body, Response $response, ?Response &$err): ?array
    {
        $entryDate = is_string($body['entry_date'] ?? null) ? trim($body['entry_date']) : '';
        if ($entryDate !== '' && !IsoDate::isValid($entryDate)) {
            $err = Json::error($response, 'validation_failed', 'entry_date musí být datum (YYYY-MM-DD).', 422);
            return null;
        }
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
        $err = null;
        return array_merge($this->auditMeta($request), array_filter([
            'entry_date'  => $entryDate !== '' ? $entryDate : null,
            'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
        ], static fn ($v) => $v !== null));
    }

    private static function pairNo(mixed $value): ?int
    {
        if (is_int($value)) {
            $pairNo = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,5}$/D', $value) === 1) {
            $pairNo = (int) $value;
        } else {
            return null;
        }
        return $pairNo >= 1 && $pairNo <= self::MAX_PAIR_NO ? $pairNo : null;
    }
}
