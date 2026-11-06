<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zápis identifikátoru osoby jako orákulum na cizí rodná čísla.
 *
 * Rodné číslo (a další identifikátory) je ve firmě jedinečné, takže uložení
 * hodnoty, kterou už má jiná osoba, skončí chybou. Ta odpověď je porovnání
 * proti údaji, který volající bez `payroll.person.read_sensitive` vidí jen
 * maskovaný: z data narození, pohlaví a dvou viditelných číslic zbývá
 * řádově dvacet kandidátů a `dry_run` je dovolil vyzkoušet bez zápisu i bez
 * stopy v auditu.
 *
 * Kontrolu jedinečnosti vypnout nejde, účetní se musí dozvědět, že osobu už
 * eviduje. Hláška proto nejmenuje, komu hodnota patří, a pro volajícího bez
 * práva na odhalení platí totéž co pro odhalení samotné:
 *
 * - zkouška nanečisto (`dry_run`), která nese hodnotu identifikátoru, čerpá
 *   z vlastního přísného limitu (výchozí 20 za 5 minut na uživatele, stejně
 *   jako `rl:reveal`), takže tiché hádání kandidátů nejde provést ve velkém;
 *   ostré uložení netipnuté hodnoty ji zapíše na kartu a zanechá běžnou
 *   auditní stopu uložení, takže tiché není,
 * - každá odmítnutá shoda se zapíše do auditní stopy, i když se zápis
 *   (třeba `dry_run`) vrátil zpět, a čerpá ze stejného limitu.
 *
 * Volající s `payroll.person.read_sensitive` smí hodnotu odhalit přímo,
 * takže se ho omezení netýká.
 */
final class PayrollIdentifierProbeGuard
{
    public const AUDIT_ACTION = 'payroll.person_identifier.collision_rejected';
    private const WINDOW_SECONDS = 300;
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly RateLimitMiddleware $rateLimit,
        private readonly ActivityLogger $activityLogger,
        private readonly Config $config,
    ) {}

    public static function mayRevealSensitive(Request $request): bool
    {
        return RequestAuthorization::allows(
            $request,
            'payroll.person.read_sensitive',
            AccessLevel::READ,
        );
    }

    /**
     * Obsahuje vstup karty hodnotu některého identifikátoru?
     *
     * @param mixed $identifiers pole `identifiers` z těla požadavku
     */
    public static function submitsIdentifierValue(mixed $identifiers): bool
    {
        if (!is_array($identifiers)) {
            return false;
        }
        foreach ($identifiers as $row) {
            if (!is_array($row) || ($row['delete'] ?? false) === true) {
                continue;
            }
            $value = $row['value'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Odečte pokus z limitu, je-li požadavek zkouškou identifikátoru (zkouška
     * nanečisto s hodnotou) a volající hodnotu nesmí odhalit.
     *
     * @return int|null null = pokračovat, jinak Retry-After v sekundách
     */
    public function throttle(Request $request, bool $identifierProbe): ?int
    {
        if (!$identifierProbe || self::mayRevealSensitive($request)) {
            return null;
        }

        return $this->consume($request);
    }

    private function consume(Request $request): ?int
    {
        $limits = (array) $this->config->get('rate_limits', []);
        $limit = (int) ($limits['payroll_identifier_writes_per_5min_per_user'] ?? self::DEFAULT_LIMIT);

        return $this->rateLimit->consume(
            'rl:payroll-identifier:' . self::subject($request),
            max(1, $limit),
            self::WINDOW_SECONDS,
        );
    }

    /**
     * Auditní záznam odmítnuté shody. Volá se AŽ po návratu transakce, jinak by
     * ho `dry_run` nebo selhání zápisu vrátily spolu s ní. Nenese hodnotu ani
     * osobu, které hodnota patří, jen kdo, u koho a jakou cestou to zkoušel.
     */
    public function recordCollision(
        Request $request,
        int $supplierId,
        ?int $employeeId,
        string $identifierType,
        string $channel,
        bool $dryRun,
        ?int $userId,
        ?string $ip,
    ): void {
        $mayReveal = self::mayRevealSensitive($request);
        if (!$mayReveal) {
            // Shoda je zásah orákula; další pokusy už limit neprojdou.
            $this->consume($request);
        }
        $this->activityLogger->log(
            self::AUDIT_ACTION,
            $userId,
            'payroll_employee',
            $employeeId,
            [
                'identifier_type' => $identifierType,
                'channel' => $channel,
                'dry_run' => $dryRun,
                'may_reveal_sensitive' => $mayReveal,
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private static function subject(Request $request): string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            return 'user:' . $userId;
        }
        $token = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        $tokenId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;

        return 'tok:' . $tokenId;
    }
}
