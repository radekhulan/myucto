<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účetní období (accounting periods) — REST API (Epic F1; stavový automat Epic F4).
 *
 *   GET  /api/accounting/periods              — seznam období firmy
 *   POST /api/accounting/periods              — nové období — účetní|admin
 *   POST /api/accounting/periods/{id}/status  — přechody stavů dle matice F4 §2.4 — admin
 *   PATCH /api/accounting/periods/{id}        — začátek prvního otevřeného období — účetní|admin
 *
 * Stavový automat (R2): open|closing|closed|reviewed|approved. Přes /status jdou POUZE
 * closed→reviewed (vratná interní kontrola), reviewed→closed (zrušení kontroly, reason),
 * closed→approved a reviewed→approved (NEVRATNÉ zákonné schválení, confirm) a
 * closed→open (reopen, povinný reason + R3 guard). Přechody open↔closing a
 * closing→closed patří výhradně uzávěrkovému workflow (422 use_closing_workflow).
 * Každá změna jde přes CAS setStatusCas s row_version (R4) — konflikt = 409.
 *
 * Neměnnost (§17/7 + §35 ZoÚ, EP-5): rozlišujeme VRATNOU interní kontrolu
 * ('reviewed' — běžný pracovní stav, lze zrušit) od NEVRATNÉHO zákonného schválení
 * ('approved' — uchovává datum, orgán/osobu, odkaz na rozhodnutí a hash dokumentu;
 * tato pole se NIKDY nemažou). Ze stavu 'approved' NEvede žádný přechod stavu ven
 * (422 approval_is_final); chyby po schválení se řeší v období zjištění, ne zrušením
 * schválení. Reopen jen z closed, nikdy z approved; vše auditované.
 */
final class AccountingPeriodAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const STATUSES = ['open', 'closing', 'closed', 'reviewed', 'approved'];
    private const MIN_REASON_LENGTH = 10;
    private const APPROVAL_FIELD_MAX = 190;
    private const APPROVAL_HASH_MAX = 64;

    public function __construct(
        private readonly AccountingPeriodRepository $periods,
        private readonly ClosingRepository $closingRepo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly EntityCategoryService $categories,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->periods->listForTenant($supplierId));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $fiscalYear = (int) ($body['fiscal_year'] ?? 0);
        $startsOn = trim((string) ($body['starts_on'] ?? ''));
        $endsOn = trim((string) ($body['ends_on'] ?? ''));

        if ($fiscalYear < 2000 || $fiscalYear > 2200) {
            return Json::error($response, 'validation_failed', 'fiscal_year musí být rozumný účetní rok.', 422);
        }
        if (!$this->isDate($startsOn) || !$this->isDate($endsOn)) {
            return Json::error($response, 'validation_failed', 'starts_on a ends_on musí být data (YYYY-MM-DD).', 422);
        }
        if ($startsOn >= $endsOn) {
            return Json::error($response, 'validation_failed', 'starts_on musí být před ends_on.', 422);
        }

        if ($this->periods->findByYear($supplierId, $fiscalYear) !== null) {
            return Json::error($response, 'duplicate_period', 'Účetní období pro tento rok už existuje.', 409);
        }

        // Souvislá řada období bez překryvů (R5) — překryv by rozbil uzávěrku i PS výkazů.
        $overlap = $this->periods->overlapping($supplierId, $startsOn, $endsOn, null);
        if ($overlap !== null) {
            return Json::error($response, 'period_overlap',
                'Období se překrývá s existujícím obdobím ' . $overlap['fiscal_year'] . '.', 422);
        }

        // Konvence labelu (jednotná s F4 uzávěrkou i odpisovým enginem): fiscal_year =
        // účetní rok firmy, do kterého počátek období patří — u kalendářního roku i běžného
        // hospodářského roku je to kalendářní rok počátku. Liší se jen zkrácené první
        // období hospodářského roku (vznik 1. 3. 2099, rok od srpna → 2098): odpisový engine
        // počítá měsíce období z labelu (FiscalCalendar::firstMonthIndex) a s labelem 2099
        // by zkrácené období kolidovalo s následujícím 1. 8. 2099 (HR).
        $expectedYear = $this->periods->fiscalCalendar($supplierId)->fiscalYearOfDate($startsOn);
        if ($fiscalYear !== $expectedYear) {
            return Json::error($response, 'validation_failed',
                'fiscal_year musí být účetní rok firmy, do kterého patří počátek období (' . $expectedYear
                . '). U hospodářského roku 1. 7. 2025 – 30. 6. 2026 je to 2025.', 422);
        }

        // EP-4: založení období a jeho auditní událost v jedné transakci (audit sdílí tutéž
        // Connection/PDO) — selhání auditu rollbackne i vytvoření období.
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $id = $this->periods->create($supplierId, $fiscalYear, $startsOn, $endsOn);
            $this->log($request, 'accounting.period_created', $id, ['fiscal_year' => $fiscalYear]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'duplicate_period', 'Účetní období pro tento rok už existuje.', 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Json::ok($response, $this->periods->findById($supplierId, $id), 201);
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        // Změna stavu období (vč. znovuotevření) je admin-only (§35 — uzávěrka).
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $period = $this->periods->findById($supplierId, $id);
        if ($period === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $status = trim((string) ($body['status'] ?? ''));
        if (!in_array($status, self::STATUSES, true)) {
            return Json::error($response, 'validation_failed',
                "status musí být 'open', 'closing', 'closed', 'reviewed' nebo 'approved'.", 422);
        }

        $from = (string) $period['status'];
        if ($from === $status) {
            return Json::ok($response, $period);
        }

        // §17/7 ZoÚ (EP-5): schválená závěrka je definitivní. Ze stavu 'approved' NEvede
        // žádný přechod stavu ven — schválení nelze zrušit ani přepsat; opravy se dělají
        // v období zjištění (§35 ZoÚ). Odmítáme dřív než matici, s jasnou hláškou.
        if ($from === 'approved') {
            return Json::error($response, 'approval_is_final',
                'Schválenou účetní závěrku nelze zrušit ani změnit přechodem stavu — schválení je dle '
                . '§17 odst. 7 ZoÚ definitivní. Případné opravy proveďte v období, kdy byla chyba zjištěna '
                . '(§35 ZoÚ), nikoli zrušením schválení.', 422);
        }

        // Matice přechodů F4 §2.4 — open↔closing a closing→closed jen přes workflow.
        if (in_array("{$from}→{$status}", ['open→closing', 'closing→open', 'closing→closed'], true)) {
            return Json::error($response, 'use_closing_workflow',
                'Tento přechod se provádí uzávěrkovým průvodcem (closing/start, closing/abort, POST /close).', 422);
        }
        $transition = "{$from}→{$status}";
        // Povolené přechody přes /status: vratná interní kontrola (reviewed), nevratné
        // zákonné schválení (approved) a reopen. Rušení zákonného schválení tu NENÍ.
        if (!in_array($transition, [
            'closed→reviewed',   // interní kontrola / review (vratná)
            'reviewed→closed',   // zrušení interní kontroly (vratná, reason)
            'closed→approved',   // zákonné schválení (nevratné, confirm)
            'reviewed→approved', // zákonné schválení (nevratné, confirm)
            'closed→open',       // znovuotevření (reason + R3 guard)
        ], true)) {
            return Json::error($response, 'invalid_status_transition',
                "Přechod '{$from}' → '{$status}' není povolen.", 422);
        }

        $rowVersion = $body['row_version'] ?? null;
        if (!is_numeric($rowVersion) || (int) $rowVersion < 1) {
            return Json::error($response, 'validation_failed', 'row_version je povinný (celé číslo ≥ 1).', 422);
        }
        $rowVersion = (int) $rowVersion;

        $reason = trim((string) ($body['reason'] ?? ''));
        $confirm = (bool) ($body['confirm'] ?? false);
        $isApproval = in_array($transition, ['closed→approved', 'reviewed→approved'], true);

        if ($isApproval && !$confirm) {
            return Json::error($response, 'validation_failed',
                'Schválení závěrky vyžaduje confirm=true — schválení je NEVRATNÉ; po schválení už knihy '
                . 'nelze znovu otevřít ani schválení zrušit (§17 odst. 7 ZoÚ).', 422);
        }
        // Vratné přechody (zrušení interní kontroly, reopen) vyžadují auditní důvod.
        if (in_array($transition, ['closed→open', 'reviewed→closed'], true)
            && mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            return Json::error($response, 'validation_failed',
                'reason je povinný (min. ' . self::MIN_REASON_LENGTH . ' znaků) — auditní stopa dle §17 odst. 7 ZoÚ.', 422);
        }

        // Metadata NEVRATNÉHO zákonného schválení (§17/7): orgán/osoba, odkaz na
        // rozhodnutí o schválení závěrky a hash schváleného dokumentu. Vše volitelné,
        // ukládá se jen při přechodu na 'approved' a už se nikdy nemaže.
        $approval = [];
        if ($isApproval) {
            $body_ = static fn (string $k): string => trim((string) ($body[$k] ?? ''));
            if (mb_strlen($body_('approval_body')) > self::APPROVAL_FIELD_MAX
                || mb_strlen($body_('approval_decision_ref')) > self::APPROVAL_FIELD_MAX
                || mb_strlen($body_('approval_document_hash')) > self::APPROVAL_HASH_MAX) {
                return Json::error($response, 'validation_failed',
                    'approval_body a approval_decision_ref smí mít max. ' . self::APPROVAL_FIELD_MAX
                    . ' znaků, approval_document_hash max. ' . self::APPROVAL_HASH_MAX . '.', 422);
            }
            $approval = [
                'body'          => $body_('approval_body') !== '' ? $body_('approval_body') : null,
                'decision_ref'  => $body_('approval_decision_ref') !== '' ? $body_('approval_decision_ref') : null,
                'document_hash' => $body_('approval_document_hash') !== '' ? $body_('approval_document_hash') : null,
            ];
        }

        // Reopen guard (R3): dokud existují posted closing/fx zápisy období nebo opening
        // zápis následujícího období, musí admin nejprve revertovat kroky open_next a
        // close_books v průvodci — jinak by reopen nechal v deníku osiřelé závěrkové zápisy.
        if ($transition === 'closed→open') {
            $next = $this->periods->nextPeriod($supplierId, (string) $period['ends_on']);
            // Jmenujeme jen kroky, které se OPRAVDU musí vzít zpět. Hláška dřív vždycky
            // vyjmenovala oba, takže posílala uživatele revertovat i krok, který nikdy
            // nespustil — a ten pak v průvodci marně hledal tlačítko.
            $blocking = [];
            // Převzatý otevírací zápis (krok open_next ho jen převzal) na uzávěrce období
            // nestojí — reopen neblokuje, a proto se z kontroly vynechává.
            if ($next !== null && $this->closingRepo->hasOpeningEntries(
                $supplierId,
                (int) $next['id'],
                $this->closingRepo->takenOverOpeningEntryIds($supplierId, $id),
            )) {
                $blocking[] = 'Otevření nového roku';
            }
            if ($this->closingRepo->hasClosingEntries($supplierId, $id)) {
                $blocking[] = 'Uzavření knih';
            }
            if ($blocking !== []) {
                return Json::error($response, 'closing_entries_exist',
                    'Období má zaúčtované závěrkové zápisy — nejprve v uzávěrkovém průvodci vezměte zpět krok '
                    . implode(' a ', $blocking) . '.', 422);
            }
        }

        // EP-4: CAS změna stavu období a její auditní událost běží v JEDNÉ transakci —
        // ActivityLogger sdílí tutéž Connection/PDO (PHP-DI singleton), takže INSERT do
        // activity_log je součástí téže tx jako UPDATE stavu. Selhání auditu tak rollbackne
        // i změnu stavu (§17/7, §35 ZoÚ — žádný neauditovaný přechod stavu závěrky). CAS je
        // atomický, takže případný retry celé transakce nevytvoří duplicitní událost.
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // EP-14: zákonné schválení (approved) je NEVRATNÉ (§17/7) — nesmí projít, dokud
            // není uložená zmražená historická kategorie ÚJ (§1e). Zmražení uzávěrka dělá při
            // uzavření knih, ale pokud tehdy selhalo (viz ClosingService::closeBooks warning),
            // dozmrazíme ho tady; když ani teď nejde, schválení tvrdě zablokujeme (ne tiše).
            if ($isApproval) {
                try {
                    $this->categories->ensureFrozen($supplierId, $id);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) není chybějící kategorie.
                    if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                        throw $e;
                    }
                    if ($ownTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return Json::error($response, 'category_not_frozen',
                        'Účetní závěrku nelze zákonně schválit: historická kategorie účetní jednotky '
                        . '(§1e ZoÚ) není uložená a její zmražení selhalo (' . $e->getMessage() . '). '
                        . 'Doplň podklady kategorizace (rozvahové mapování / počet zaměstnanců) a schválení zopakuj.', 422);
                }
            }
            if (!$this->periods->setStatusCas($id, $supplierId, $status, $rowVersion, $this->userId($request), $approval)) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return Json::error($response, 'version_conflict',
                    'Období mezitím změnil jiný uživatel — načtěte aktuální stav.', 409);
            }

            $action = match ($transition) {
                'closed→approved', 'reviewed→approved' => 'accounting.period_approved',
                'closed→open'     => 'accounting.period_reopened',
                'closed→reviewed' => 'accounting.period_reviewed',
                'reviewed→closed' => 'accounting.period_review_cancelled',
            };
            $payload = ['from' => $from, 'to' => $status, 'fiscal_year' => $period['fiscal_year']];
            if ($reason !== '') {
                $payload['reason'] = $reason;
            }
            // Zákonné schválení — do auditní stopy zapiš i doložení schválení (orgán,
            // rozhodnutí, hash), pokud byla zadaná; approval pole se nikdy nemažou.
            if ($isApproval) {
                foreach (['body' => 'approval_body', 'decision_ref' => 'approval_decision_ref', 'document_hash' => 'approval_document_hash'] as $k => $logKey) {
                    if (($approval[$k] ?? null) !== null) {
                        $payload[$logKey] = $approval[$k];
                    }
                }
            }
            $this->log($request, $action, $id, $payload);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Json::ok($response, $this->periods->findById($supplierId, $id));
    }

    /**
     * Změna začátku PRVNÍHO účetního období (§ 3 ZoÚ: první období začíná dnem vzniku
     * účetní jednotky). Automat zakládá období podle kalendáře firmy, takže firma
     * vzniklá v průběhu roku dostala 1. 1. – 31. 12. (#227).
     *
     * Jen nejstarší otevřené období, nový začátek ve stejném účetním roce a před koncem
     * období, a žádný zápis deníku s datem před novým začátkem — jinak by zápis ležel
     * mimo své období (výkazy, PS, otevírací zápis s entry_date = starts_on).
     * Konec období ani štítek roku se nemění. Kalendář firmy (kalendářní vs. hospodářský
     * rok) musí po změně zůstat stejný, jinak by automat zakládal další roky jinak.
     */
    public function updateStart(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $startsOn = trim((string) ($body['starts_on'] ?? ''));
        if (!$this->isDate($startsOn)) {
            return Json::error($response, 'validation_failed', 'starts_on musí být datum (YYYY-MM-DD).', 422);
        }
        $rowVersion = $body['row_version'] ?? null;
        if (!is_numeric($rowVersion) || (int) $rowVersion < 1) {
            return Json::error($response, 'validation_failed', 'row_version je povinný (celé číslo ≥ 1).', 422);
        }
        $rowVersion = (int) $rowVersion;

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $fail = function (string $code, string $message, int $status, array $extra = []) use ($pdo, $ownTx, $response): Response {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error($response, $code, $message, $status, $extra);
        };
        try {
            // Zámek všech období firmy ve stejném pořadí jako účtování (DimensionPeriodGuard),
            // takže souběžně nevznikne zápis do dnů, které se z období vyjímají.
            $before = $this->periods->fiscalCalendarSnapshot($supplierId, true);
            $period = null;
            foreach ($before['periods'] as $candidate) {
                if ((int) $candidate['id'] === $id) {
                    $period = $candidate;
                }
            }
            if ($period === null) {
                return $fail('not_found', 'Účetní období nenalezeno.', 404);
            }
            $oldStart = (string) $period['starts_on'];
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];
            if ($startsOn === $oldStart) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return Json::ok($response, $this->periods->findById($supplierId, $id));
            }
            if ((string) $period['status'] !== 'open') {
                return $fail('period_not_open', 'Začátek jde změnit jen u otevřeného období.', 422);
            }
            foreach ($before['periods'] as $other) {
                if ((int) $other['id'] !== $id && (string) $other['starts_on'] < $oldStart) {
                    return $fail('not_first_period',
                        'Začátek jde změnit jen u prvního účetního období firmy. Navazující období začíná dnem po konci předchozího.', 422);
                }
            }
            if ($startsOn >= $endsOn) {
                return $fail('validation_failed', 'Začátek období musí být před jeho koncem.', 422);
            }
            if ($before['calendar']->fiscalYearOfDate($startsOn) !== $fiscalYear) {
                return $fail('validation_failed',
                    'Nový začátek musí patřit do účetního roku ' . $fiscalYear . '. Období se jen zkracuje nebo prodlužuje v rámci téhož roku.', 422);
            }
            $overlap = $this->periods->overlapping($supplierId, $startsOn, $endsOn, $id);
            if ($overlap !== null) {
                return $fail('period_overlap',
                    'Období by se překrývalo s obdobím ' . $overlap['fiscal_year'] . '.', 422);
            }
            $activation = $pdo->prepare('SELECT accounting_activation_status FROM supplier WHERE id = ?');
            $activation->execute([$supplierId]);
            if ((string) $activation->fetchColumn() === 'running') {
                return $fail('activation_running',
                    'Právě běží doúčtování dokladů při aktivaci účetnictví. Začátek období změňte, až doběhne.', 409);
            }
            $entries = $this->periods->journalEntriesBefore($supplierId, $id, $startsOn);
            if ($entries['count'] > 0) {
                return $fail('entries_before_start',
                    'V období je ' . $entries['count'] . ' zápisů v deníku s datem před novým začátkem (nejstarší '
                    . $entries['first_date'] . '). Nejprve je stornujte nebo přeúčtujte do pozdějšího data.', 409,
                    ['entries_count' => $entries['count'], 'first_entry_date' => $entries['first_date']]);
            }
            $proposed = array_map(
                static fn (array $p): array => (int) $p['id'] === $id ? array_replace($p, ['starts_on' => $startsOn]) : $p,
                $before['periods'],
            );
            if ($this->periods->fiscalCalendarFor($supplierId, $proposed, true)->startMonth() !== $before['calendar']->startMonth()) {
                return $fail('fiscal_calendar_changed',
                    'Změna by firmě změnila začátek účetního roku. Nejprve v Nastavení firmy vyplňte, kterým měsícem účetní rok začíná.', 422);
            }
            if (!$this->periods->updateStartsOnCas($id, $supplierId, $startsOn, $rowVersion)) {
                return $fail('version_conflict', 'Období mezitím změnil jiný uživatel — načtěte aktuální stav.', 409);
            }
            // Doklady starší než začátek účetnictví se neúčtují; aktivace účetnictví tak
            // nebude doúčtovávat dny, které do prvního období nepatří.
            $pdo->prepare(
                'UPDATE supplier SET accounting_starts_on = ?
                  WHERE id = ? AND accounting_starts_on IS NOT NULL AND accounting_starts_on < ?'
            )->execute([$startsOn, $supplierId, $startsOn]);
            $this->log($request, 'accounting.period_start_changed', $id, [
                'fiscal_year' => $fiscalYear,
                'from' => $oldStart,
                'to' => $startsOn,
            ]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Json::ok($response, $this->periods->findById($supplierId, $id));
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            $this->userId($request),
            'accounting_period',
            $entityId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
