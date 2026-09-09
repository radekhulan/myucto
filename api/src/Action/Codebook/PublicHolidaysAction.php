<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PublicHolidayRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\CzechWorkingDays;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa číselníku státních a ostatních svátků (z. č. 245/2000 Sb., migrace 1781):
 *   GET    /api/codebooks/public-holidays        — čtení včetně náhledu na rok
 *   POST   /api/codebooks/public-holidays        — nový svátek
 *   PUT    /api/codebooks/public-holidays/{id}   — oprava / omezení platnosti
 *   DELETE /api/codebooks/public-holidays/{id}   — smazání řádku
 *
 * ── Kdo smí zapisovat ──────────────────────────────────────────────────────────
 * Tabulka je GLOBÁLNÍ a jeden řádek v ní posouvá přes § 33 odst. 4 daňového řádu
 * VŠECHNY lhůty podání všech firem v instanci — přiznání k DPH, kontrolní i
 * souhrnné hlášení, přehledy OSVČ, odvody ze mzdy. Zápis proto vyžaduje
 * superadmina a jen ze session, ne přes API token (stejná úvaha jako u
 * {@see OssMemberStateRatesAction}). Čtení stačí běžné oprávnění k číselníkům,
 * ať účetní vidí, podle čeho se jí termíny počítají.
 */
final class PublicHolidaysAction
{
    private const RULE_TYPES = ['fixed', 'easter'];

    public function __construct(
        private readonly PublicHolidayRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $year = (int) ($request->getQueryParams()['year'] ?? date('Y'));
        if ($year < 1900 || $year > 2200) {
            $year = (int) date('Y');
        }

        $preview = [];
        foreach (CzechWorkingDays::holidaysForYear($year) as $date => $holiday) {
            $preview[] = ['date' => $date, 'code' => $holiday['code'], 'name' => $holiday['name']];
        }

        return Json::ok($response, [
            'rules'      => $this->repo->listAll(),
            'rule_types' => self::RULE_TYPES,
            'can_write'  => $this->canWrite($request),
            'year'       => $year,
            'preview'    => $preview,
            // Prázdný číselník není „žádné svátky" — je to instalace bez seedu, která
            // počítá lhůty z pojistky zapečené v kódu. UI to musí umět říct nahlas,
            // jinak by se to poznalo až podle propásnutého termínu.
            'fallback'   => CzechWorkingDays::usingFallback(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $error = $this->validate($body);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 422);
        }

        try {
            $id = $this->repo->create($body);
        } catch (\PDOException $e) {
            return Json::error($response, 'conflict', 'Svátek s tímto kódem a začátkem platnosti už existuje.', 409);
        }

        $this->audit($request, 'public_holiday.created', $id, $body);

        return Json::ok($response, ['id' => $id, 'rules' => $this->repo->listAll()], 201);
    }

    /** @param array<string,mixed> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id) === null) {
            return Json::error($response, 'not_found', 'Svátek neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $error = $this->validate($body);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 422);
        }

        try {
            $this->repo->update($id, $body);
        } catch (\PDOException $e) {
            return Json::error($response, 'conflict', 'Svátek s tímto kódem a začátkem platnosti už existuje.', 409);
        }

        $this->audit($request, 'public_holiday.updated', $id, $body);

        return Json::ok($response, ['id' => $id, 'rules' => $this->repo->listAll()]);
    }

    /** @param array<string,mixed> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $id = (int) ($args['id'] ?? 0);
        if (!$this->repo->delete($id)) {
            return Json::error($response, 'not_found', 'Svátek neexistuje.', 404);
        }

        $this->audit($request, 'public_holiday.deleted', $id, []);

        return Json::ok($response, ['ok' => true, 'rules' => $this->repo->listAll()]);
    }

    /**
     * Validace tvaru. Záměrně nepovoluje „skoro dobrý" vstup: špatné `MM-DD`
     * projde tiše jako den, který v roce nenastane, a svátek pak prostě zmizí —
     * a nikdo si toho nevšimne až do chvíle, kdy je lhůta o den vedle.
     *
     * @param array<string,mixed> $body
     */
    private function validate(array $body): ?string
    {
        $code = trim((string) ($body['code'] ?? ''));
        if (preg_match('/^[a-z0-9_]{2,40}$/D', $code) !== 1) {
            return 'Kód smí obsahovat jen malá písmena, číslice a podtržítko (2–40 znaků).';
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            return 'Název svátku je povinný.';
        }
        $ruleType = (string) ($body['rule_type'] ?? '');
        if (!in_array($ruleType, self::RULE_TYPES, true)) {
            return 'Typ pravidla musí být `fixed` nebo `easter`.';
        }
        if ($ruleType === 'fixed') {
            if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D', (string) ($body['month_day'] ?? '')) !== 1) {
                return 'Datum pevného svátku zadej ve tvaru MM-DD.';
            }
        } else {
            $offset = $body['easter_offset'] ?? null;
            if (!is_numeric($offset) || (int) $offset < -60 || (int) $offset > 60) {
                return 'Posun od Velikonoční neděle musí být celé číslo v rozsahu −60 až 60 dnů.';
            }
        }
        $from = (string) ($body['valid_from'] ?? '');
        if (!$this->isDate($from)) {
            return 'Začátek platnosti zadej ve tvaru RRRR-MM-DD.';
        }
        $to = $body['valid_to'] ?? null;
        if ($to !== null && (string) $to !== '') {
            if (!$this->isDate((string) $to)) {
                return 'Konec platnosti zadej ve tvaru RRRR-MM-DD.';
            }
            if ((string) $to < $from) {
                return 'Konec platnosti nesmí předcházet začátku.';
            }
        }

        return null;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function canWrite(Request $request): bool
    {
        return RequestAuthorization::isSuperadmin($request)
            && !RequestAuthorization::isBearerAuth($request);
    }

    private function forbidden(Response $response): Response
    {
        return Json::error(
            $response,
            'forbidden',
            'Číselník svátků je globální — jeden řádek posouvá lhůty všem firmám v instanci, '
                . 'takže ho smí měnit jen správce instance z webového rozhraní.',
            403,
        );
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function audit(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'public_holiday',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
    }
}
