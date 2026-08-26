<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsRecipientCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionPrerequisites;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Podání JMHZ datovou schránkou — druhý rovnocenný kanál vedle VREP/APEP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co tenhle endpoint dělá a co NE
 * ═══════════════════════════════════════════════════════════════════════════
 * Zařadí zmrazené podání do obecné fronty podání a vrátí hotovou datovou zprávu:
 * příjemce, věc, spisovou značku a přílohu. NEODESÍLÁ.
 *
 * Odeslat ho totiž dnes automaticky nejde a předstírat opak by bylo horší než
 * to přiznat: `IsdsTransport` je nabindovaný na `UnavailableIsdsTransport`,
 * protože není rozhodnuté, jak se do datové schránky přihlašujeme. Odpověď
 * proto nese `transport.automatic = false` s pojmenovaným důvodem
 * `isds_transport_unavailable`, aby UI mohlo říct pravdu místo aby čekalo na
 * odeslání, které nepřijde.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Odsud dál se pokračuje EXISTUJÍCÍ ruční cestou
 * ═══════════════════════════════════════════════════════════════════════════
 * Mzdy si vlastní ruční režim nedělají — používají tentýž, kterým jdou daňová
 * podání i přehledy pojišťovnám:
 *
 *   `POST /api/submissions/outbox/{id}/mark-sent`   … odeslal jsem ze své schránky
 *   `POST /api/submissions/outbox/{id}/receipt`     … tady je doručenka (ZFO)
 *
 * Rozhodný den doručení včetně fikce z toho spočítá `DeliveryFictionCalculator`.
 * Druhá evidence doručenek by znamenala druhou pravdu o tom, kdy bylo podáno.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Lhůta a povinnost se kanálem NEMĚNÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Zařazení nezakládá druhé podání ani druhý termín — pracuje se zmrazeným
 * artefaktem téhož podání, které už v evidenci existuje. Volba mezi VREP a
 * datovou schránkou je rozhodnutí o dopravě, ne o tom, co a dokdy se podává.
 */
final class PayrollJmhzIsdsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzIsdsSubmissionService $isds,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /**
     * Doložení adresáti ČSSZ pro obě prostředí.
     *
     * Je to čtecí endpoint schválně oddělený od zařazení: uživatel má mít
     * možnost se PŘED odesláním podívat, do které schránky to půjde a odkud je
     * to doložené. Sedm znaků bez kontrolní číslice se jinak nedá zkontrolovat.
     */
    public function recipients(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        $describe = static fn (string $environment): array => [
            'environment' => $environment,
            'box_id' => JmhzIsdsRecipientCatalog::forEnvironment($environment)->boxId,
            'name' => JmhzIsdsRecipientCatalog::forEnvironment($environment)->boxName,
            'note' => JmhzIsdsRecipientCatalog::forEnvironment($environment)->note,
        ];

        return $this->noStore(Json::ok($response, [
            'recipients' => [
                $describe('production'),
                $describe('test'),
            ],
            'general_fallback' => [
                'box_id' => JmhzIsdsRecipientCatalog::generalFallback()->boxId,
                'name' => JmhzIsdsRecipientCatalog::generalFallback()->boxName,
                'note' => JmhzIsdsRecipientCatalog::generalFallback()->note,
            ],
            'source_url' => 'https://www.cssz.gov.cz/komunikacni-kanaly-e-podani',
            // Registraci u ČSSZ nesplní software, ale člověk — a typicky týdny
            // předem. Bez vyslovení by na ni uživatel narazil až odmítnutím
            // ostrého podání, tedy když už běží lhůta.
            'prerequisites' => [
                'isds' => JmhzSubmissionPrerequisites::forChannel('isds'),
                'vrep_apep' => JmhzSubmissionPrerequisites::forChannel('vrep_apep'),
            ],
        ]));
    }

    /** @param array{submissionId:string} $args */
    public function enqueue(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        try {
            $supplierId = $this->currentSupplierId($request);
            $this->productionGate->assertEnvironmentActive(
                $supplierId,
                $environment,
            );
            $result = $this->isds->enqueue(
                $supplierId,
                $environment,
                $this->id($args, 'submissionId'),
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
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            ));
        } catch (JmhzXmlException $exception) {
            // Chybějící zmrazená datová věta není chyba požadavku ani serveru —
            // je to nedokončený předchozí krok, který musí udělat uživatel.
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            ));
        } catch (SubmissionChannelException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(
                Json::error($response, 'conflict', $exception->getMessage(), 409),
            );
        }

        return $this->noStore(Json::ok($response, [
            'outbox_id' => $result['outbox_id'],
            'created' => $result['created'],
            'environment' => $environment,
            'recipient' => $result['recipient'],
            'subject' => $result['subject'],
            'sender_ident' => $result['sender_ident'],
            'attachment' => $result['attachment'],
            'transport' => $result['transport'],
            // Odpověď si v ručním režimu musí uživatel ve schránce najít sám —
            // ISDS podle věci filtrovat neumí. Návod jde odsud, ne z frontendu.
            'response_hint' => $this->isds->responseHint(),
        ]));
    }

    /**
     * Ověření, že nalezená zpráva ve schránce patří k odeslanému podání.
     *
     * Samostatný endpoint proto, že v ručním režimu tenhle krok dělá člověk:
     * najde ve schránce zprávu, která vypadá jako odpověď, a potřebuje vědět,
     * jestli patří k TOMUHLE podání, než ji nahraje. Odpověď je záměrně jen
     * „ano/ne + rozebrané prvky“ — nic se tím neuzavírá a žádný stav se nemění.
     */
    public function matchResponse(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $subject = $query['subject'] ?? null;
        $sentMessageId = $query['sent_message_id'] ?? null;
        if (!is_string($subject) || trim($subject) === '') {
            return $this->invalid($response, 'Věc zprávy je povinná.');
        }
        if (!is_string($sentMessageId) || preg_match('/^[0-9]{1,20}$/D', $sentMessageId) !== 1) {
            return $this->invalid($response, 'ID odeslané zprávy musí být číslo.');
        }

        return $this->noStore(Json::ok(
            $response,
            $this->isds->matchResponse($subject, $sentMessageId),
        ));
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'test';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    /** @param array<string,string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level = AccessLevel::WRITE,
    ): ?Response {
        // Stejná brána jako u VREP: úřední podání jménem firmy se nespouští
        // přes token, který se dá odcizit a nemá druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
