<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionQueueRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionQueueService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fronta odchozích mzdových podání — zobrazení a odeslání z jednoho místa.
 *
 * Odesílá se přes {@see PayrollSubmissionQueueService}, který jen deleguje na
 * existující kanálové služby. Původní odesílací tlačítka (karta pracovního
 * vztahu, „Stav odeslání", karta nemocenského případu, zdravotní panel)
 * fungují dál — fronta je druhá cesta k témuž.
 */
final class PayrollSubmissionQueueAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionQueueService $queue,
        private readonly PayrollRegistrationChangeDetectionService $changes,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $environment = self::environmentIn($query['environment'] ?? null);
        if ($environment === null) {
            return $this->invalid(
                $response,
                'Prostředí podání musí být test nebo production.',
            );
        }
        $limit = max(1, min(
            PayrollSubmissionQueueRepository::LIST_MAX_LIMIT,
            (int) ($query['limit']
                ?? PayrollSubmissionQueueRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $agendaCode = $query['agenda_code'] ?? null;
        if (!is_string($agendaCode) || trim($agendaCode) === '') {
            $agendaCode = null;
        } elseif (preg_match('/^[A-Za-z0-9_-]{1,48}$/D', $agendaCode) !== 1) {
            return $this->invalid(
                $response,
                'Kód agendy smí obsahovat jen písmena, číslice, podtržítko'
                    . ' a pomlčku.',
            );
        }
        $sort = $query['sort'] ?? 'due';
        if (!is_string($sort)
            || !in_array($sort, PayrollSubmissionQueueRepository::SORTS, true)
        ) {
            return $this->invalid(
                $response,
                'Řadit lze podle lhůty (due) nebo podle agendy (agenda).',
            );
        }

        $result = $this->queue->queue(
            $this->currentSupplierId($request),
            $environment,
            $limit,
            $offset,
            $agendaCode,
            $sort,
        );

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            ...$result,
        ]));
    }

    /**
     * Zkontroluje hlásitelné změny u VŠECH aktivních vztahů firmy.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * Proč to musí jít spustit odsud
     * ═══════════════════════════════════════════════════════════════════════
     * Detekce změn dosud běžela JEN při otevření karty jednoho zaměstnance
     * ({@see PayrollRegistrationAction::changeDetection()}). Přitom právě
     * detekce zakládá povinnost s běžící osmidenní lhůtou — takže dokud kartu
     * nikdo neotevřel, změna se nezjistila a lhůta uplynula, aniž by se o ní
     * kdokoli dozvěděl. U jednoho jednatele to nevadí; u stovky zaměstnanců
     * s denními změnami je to tichá ztráta zákonné lhůty.
     *
     * Fronta, která tvrdí „není co odeslat", protože se nikdo nepodíval, je
     * horší než žádná. Proto se sweep spouští odtud, ze stejné obrazovky.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * Proč se návrhy nezdvojí
     * ═══════════════════════════════════════════════════════════════════════
     * `PayrollRegistrationChangeDetectionService::sweep()` prochází jen
     * vztahy, u kterých se od posledního porovnání pohnul VODOZNAK zdroje
     * (`payroll_registration_change_scans.source_watermark`). Opakovaný běh
     * nad nezměněnými daty tedy neudělá nic a s detekcí z karty zaměstnance
     * se nepere — obě volají tutéž službu v téže transakci nad zamčeným
     * vztahem. Sweep je z téhož důvodu bezpečný i jako cron.
     */
    public function detectChanges(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $environment = self::environmentIn(
            is_array($body) ? ($body['environment'] ?? null) : null,
        );
        if ($environment === null) {
            return $this->invalid(
                $response,
                'Prostředí podání musí být test nebo production.',
            );
        }

        try {
            $result = $this->changes->sweep(
                $this->currentSupplierId($request),
                $environment,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            ...$result,
            // Strop je porce, ne limit: když se prošlo přesně tolik vztahů,
            // kolik se smí projít najednou, další čekají a uživatel to musí
            // vědět — jinak by si myslel, že je hotovo.
            'has_more' => $result['scanned']
                >= PayrollRegistrationChangeDetectionService::SWEEP_LIMIT,
        ]));
    }

    /**
     * Hromadné odeslání jedné PORCE dávky.
     *
     * Odpověď je vždy 200 s výsledkem KAŽDÉ položky — i když všechny selhaly.
     * HTTP kód tu nemá co říct: „37 odesláno, 3 selhala" není ani úspěch, ani
     * chyba požadavku, a shodit celou porci kvůli jedné položce je přesně to,
     * co při stovce zaměstnanců nesmí nastat.
     */
    public function dispatchBatch(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->invalid($response, 'Tělo požadavku musí být objekt.');
        }
        $environment = self::environmentIn($body['environment'] ?? null);
        if ($environment === null) {
            return $this->invalid(
                $response,
                'Prostředí podání musí být test nebo production.',
            );
        }
        $rawItems = $body['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === []) {
            return $this->invalid(
                $response,
                'Seznam podání k odeslání je prázdný.',
            );
        }
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                return $this->invalid(
                    $response,
                    'Každá položka dávky musí být objekt s podáním a klíčem.',
                );
            }
            $submissionId = $item['submission_id'] ?? null;
            $key = $item['idempotency_key'] ?? null;
            if (!is_int($submissionId) && !(is_string($submissionId)
                && preg_match('/^[1-9][0-9]*$/D', $submissionId) === 1)
            ) {
                return $this->invalid(
                    $response,
                    'Identifikátor podání musí být kladné celé číslo.',
                );
            }
            // Klíč nese KLIENT a je vázaný na jedno kliknutí. Kdyby ho
            // dogeneroval server, opakované odeslání téže dávky (F5, výpadek
            // sítě) by u úřadu založilo duplicitní podání.
            if (!is_string($key) || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) !== 1) {
                return $this->invalid(
                    $response,
                    'Každá položka dávky musí nést vlastní idempotenční klíč.',
                );
            }
            $items[] = [
                'submission_id' => (int) $submissionId,
                'idempotency_key' => $key,
            ];
        }

        try {
            $supplierId = $this->currentSupplierId($request);
            $this->productionGate->assertEnvironmentActive(
                $supplierId,
                $environment,
            );
            $result = $this->queue->dispatchMany(
                $supplierId,
                $environment,
                $items,
                $this->userId($request),
            );
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            ...$result,
        ]));
    }

    /** @param array{submissionId:string} $args */
    public function dispatch(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        // Bez idempotenčního klíče by druhé kliknutí založilo druhé podání za
        // totéž období; úřad ho odmítne jako duplicitu a vzít zpět se nedá.
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '') {
            return $this->invalid(
                $response,
                'Hlavička Idempotency-Key je povinná.',
            );
        }
        $body = $request->getParsedBody();
        $environment = self::environmentIn(
            is_array($body) ? ($body['environment'] ?? null) : null,
        ) ?? self::environmentIn(
            $request->getQueryParams()['environment'] ?? null,
        );
        if ($environment === null) {
            return $this->invalid(
                $response,
                'Prostředí podání musí být test nebo production.',
            );
        }
        $submissionId = $args['submissionId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $submissionId) !== 1) {
            return $this->invalid(
                $response,
                'Identifikátor podání musí být kladné celé číslo.',
            );
        }

        try {
            $supplierId = $this->currentSupplierId($request);
            // Ostré prostředí se otevírá až po výslovném uvolnění modulu;
            // v testu brána nic nedělá.
            $this->productionGate->assertEnvironmentActive(
                $supplierId,
                $environment,
            );
            $result = $this->queue->dispatch(
                $supplierId,
                $environment,
                (int) $submissionId,
                $idempotencyKey,
                $this->userId($request),
            );
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (JmhzTransportException $exception) {
            // Chyba na straně úřadu není chyba klienta ani naše: 502 říká, že
            // cesta ven selhala, aniž by to vypadalo jako neplatný požadavek.
            $status = match (true) {
                str_starts_with($exception->errorCode, 'jmhz_vrep_') => 502,
                default => 422,
            };

            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $status,
            ));
        } catch (SubmissionChannelException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            ));
        } catch (JmhzXmlException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            ));
        }

        return $this->noStore(Json::ok($response, $result));
    }

    private static function environmentIn(mixed $value): ?string
    {
        return is_string($value)
            && in_array($value, ['test', 'production'], true)
                ? $value
                : null;
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level = AccessLevel::WRITE,
    ): ?Response {
        // Odeslání úředního podání jménem firmy se nikdy nespouští přes token:
        // token se dá odcizit a na rozdíl od relace u něj není druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Fronta odchozích podání je dostupná jen z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(
            Json::error($response, 'validation_failed', $message, 422),
        );
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
