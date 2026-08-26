<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\CsszCertificateSerialNumber;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kterým certifikátem se podepisují mzdová podání na ČSSZ — per firma a prostředí.
 *
 * Certifikát se tu **neukládá**. Trezor je v aplikaci jeden
 * (`epo_signing_credentials`) a plní se přes Systém → Elektronické podpisy;
 * druhé úložiště by znamenalo týž klíč na dvou místech, tedy dvě platnosti
 * a dvě hesla, která se rozejdou v nejhorší možnou chvíli. Tahle akce drží jen
 * VOLBU: který z už uložených certifikátů patří ke které firmě a prostředí.
 *
 * Prostředí je součástí klíče proto, že testovací certifikát bývá jiný než
 * produkční. Záměna se pozná až z protokolu ČSSZ, tedy typicky po termínu.
 *
 * ## Co tady musí spadnout, aby to nespadlo u ČSSZ
 *
 * Podání podepsané nesprávným certifikátem **vypadá jako úspěch** — chyba se
 * projeví až v protokolu, klidně za několik dní. Proto se kontroluje tady:
 *
 *  1. `credential_id` z těla requestu se nikdy nebere na slovo. Musí být
 *     v trezoru volajícího uživatele A povolený pro tuhle firmu — přesně to,
 *     co si při podpisu vyžádá `EpoSigningCredentialService::unlockForSigning()`.
 *     Kdyby se to lišilo, uložila by se volba, kterou podpis odmítne.
 *  2. `cssz_registered_serial` je sériové číslo z formuláře „Oznámení
 *     o pověření". Když nesedí se sériovým číslem zvoleného certifikátu,
 *     ČSSZ podání odmítne — a je nesrovnatelně levnější to zjistit teď než
 *     v den termínu. Srovnává se kanonicky (bez oddělovačů, bez vedoucích nul,
 *     case-insensitive a v obou zápisech), protože papír od ČSSZ tiskne sériové
 *     číslo decimálně, kdežto X.509 hexadecimálně.
 *  3. Expirovaný certifikát uložit lze — někdo si volbu chystá dopředu nebo
 *     má obnovu rozjetou — ale odpověď to musí říct nahlas. Certifikát přestane
 *     fungovat v den vypršení a uživatel to má vidět před termínem, ne po něm.
 *
 * ## Bezpečnostní režim
 *
 * Přebírá se beze změny z `CertificateVaultAction`: jen z přihlášené webové
 * relace (nikdy přes API token — ten se dá odcizit a nemá druhý faktor)
 * a se step-up ověřením u každé změny. Volba certifikátu sice sama o sobě
 * soukromý klíč nevydá, ale rozhoduje o tom, čím se podepíše úřední podání
 * jménem firmy; to je stejná třída rozhodnutí jako správa klíče samotného.
 */
final class PayrollJmhzSigningProfileAction
{
    use PayrollActionSupport;

    /** Prostředí je součástí primárního klíče a odpovídá ENUM v migraci 1373. */
    private const ENVIRONMENTS = ['production', 'test'];

    /** Operace, pod kterou se loguje a ověřuje step-up. */
    private const STEP_UP_OPERATION = 'payroll_signing_profile';

    /** Sloupec `cssz_registered_serial` je VARCHAR(64) s CHECK na hex zápis. */
    private const SERIAL_MAX_LENGTH = 64;

    private const SERIAL_MATCH = 'match';
    private const SERIAL_MISMATCH = 'mismatch';
    private const SERIAL_CERTIFICATE_UNKNOWN = 'certificate_unknown';
    private const SERIAL_INPUT_UNREADABLE = 'input_unreadable';

    public function __construct(
        private readonly PayrollSigningProfileRepository $profiles,
        private readonly EpoSigningCredentialService $credentials,
        private readonly EpoStepUpService $stepUp,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Volba pro jedno prostředí + seznam certifikátů, ze kterých jde vybírat.
     *
     * Obojí v jedné odpovědi schválně: obrazovka bez seznamu neumí nabídnout
     * změnu a seznam bez volby neumí ukázat, co je nastavené teď.
     */
    public function show(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $requested = $request->getQueryParams()['environment'] ?? null;
        // Bez parametru se ptáme na ostré prostředí — to je ten, na kterém záleží.
        $environment = ($requested === null || $requested === '')
            ? 'production'
            : $this->normalizeEnvironment($requested);
        if ($environment === null) {
            return $this->invalidEnvironment($response);
        }

        $userId = (int) $this->userId($request);
        $supplierId = $this->currentSupplierId($request);
        $now = time();

        $certificates = [];
        foreach ($this->credentials->listOwnedForSupplier($userId, $supplierId) as $credential) {
            $certificates[] = $this->presentCertificate($credential, $now);
        }

        $storageAvailable = $this->profiles->isAvailable();
        $profileRow = $storageAvailable ? $this->profiles->find($supplierId, $environment) : null;

        $warnings = [];
        $profile = null;
        if ($profileRow !== null) {
            $credentialId = (int) ($profileRow['credential_id'] ?? 0);
            $certificate = null;
            foreach ($certificates as $candidate) {
                if ($candidate['id'] === $credentialId) {
                    $certificate = $candidate;
                    break;
                }
            }
            $profile = $this->presentProfile($profileRow, $certificate);
            $warnings = $this->profileWarnings($profile, $certificate);
        }
        if (!$storageAvailable) {
            $warnings[] = [
                'code' => 'signing_profile_storage_unavailable',
                'message' => 'Úložiště volby certifikátu zatím není k dispozici — '
                    . 'neproběhla migrace 1373.',
            ];
        }

        return Json::ok($response, [
            'environment' => $environment,
            'environments' => self::ENVIRONMENTS,
            'storage_available' => $storageAvailable,
            'profile' => $profile,
            'certificates' => $certificates,
            'warnings' => $warnings,
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Nastaví nebo změní volbu certifikátu.
     *
     * Tělo: `credential_id`, `environment`, volitelně `cssz_registered_serial`
     * a `row_version`. `row_version` se posílá jen při ZMĚNĚ existující volby —
     * repozitář ho u prvního uložení odmítne, protože verze, vůči které by se
     * zamykalo, ještě neexistuje.
     */
    public function save(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        $environment = $this->normalizeEnvironment($body['environment'] ?? null);
        if ($environment === null) {
            return $this->invalidEnvironment($response);
        }
        $credentialId = $this->positiveInt($body['credential_id'] ?? null);
        if ($credentialId === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Vyberte certifikát z trezoru (credential_id).',
                422,
            );
        }
        $expectedVersion = null;
        if ($this->sendsRowVersion($body)) {
            $expectedVersion = $this->positiveInt($body['row_version'] ?? null);
            if ($expectedVersion === null) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'row_version musí být kladné celé číslo z předchozího načtení volby.',
                    422,
                );
            }
        }
        $claimedSerialRaw = trim((string) ($body['cssz_registered_serial'] ?? ''));
        if (!$this->profiles->isAvailable()) {
            return $this->storageUnavailable($response);
        }

        $userId = (int) $this->userId($request);
        $supplierId = $this->currentSupplierId($request);
        try {
            $this->stepUp->verify($request, $userId, $body, self::STEP_UP_OPERATION);
        } catch (EpoSubmissionException $exception) {
            return $this->epoError($response, $exception);
        }

        // Certifikát se hledá v trezoru volajícího, ne podle toho, co přišlo v těle.
        $certificate = null;
        foreach ($this->credentials->listOwnedForSupplier($userId, $supplierId) as $credential) {
            if ((int) ($credential['id'] ?? 0) === $credentialId) {
                $certificate = $this->presentCertificate($credential, time());
                break;
            }
        }
        if ($certificate === null) {
            return Json::error(
                $response,
                'credential_not_found',
                'Vybraný certifikát není ve vašem trezoru. Nahrajte ho v Systém → '
                    . 'Elektronické podpisy a zkuste to znovu.',
                404,
            );
        }
        if ($certificate['enabled_for_supplier'] !== true) {
            // Přesně tuhle podmínku si při podpisu vyžádá unlockForSigning();
            // uložit volbu, kterou podpis odmítne, by bylo horší než ji odmítnout teď.
            return Json::error(
                $response,
                'credential_not_enabled_for_supplier',
                'Vybraný certifikát není pro tuhle firmu povolený. Povolte ho '
                    . 'v trezoru certifikátů u této firmy.',
                422,
            );
        }

        $storedSerial = null;
        if ($claimedSerialRaw !== '') {
            $certificateSerial = is_string($certificate['serial_hex'])
                ? $certificate['serial_hex']
                : null;
            $verdict = $this->compareSerial($certificateSerial, $claimedSerialRaw);
            if ($verdict === self::SERIAL_INPUT_UNREADABLE) {
                return Json::error(
                    $response,
                    'cssz_serial_unreadable',
                    'Sériové číslo z oznámení ČSSZ se nepodařilo přečíst. Zadejte ho '
                        . 'tak, jak je na formuláři — číslice, případně hexadecimálně '
                        . '(oddělovače dvojtečkou nebo mezerou nevadí).',
                    422,
                );
            }
            if ($verdict === self::SERIAL_CERTIFICATE_UNKNOWN) {
                // Mlčky přijmout by znamenalo tvářit se, že kontrola proběhla.
                return Json::error(
                    $response,
                    'certificate_serial_unknown',
                    'U vybraného certifikátu neznáme sériové číslo, takže shodu '
                        . 's registrací u ČSSZ nelze ověřit. Nahrajte certifikát do '
                        . 'trezoru znovu, nebo volbu uložte bez sériového čísla.',
                    422,
                );
            }
            if ($verdict === self::SERIAL_MISMATCH) {
                return Json::error(
                    $response,
                    'cssz_serial_mismatch',
                    'Sériové číslo registrované u ČSSZ neodpovídá vybranému '
                        . 'certifikátu. Podání podepsané tímhle certifikátem by ČSSZ '
                        . 'odmítla — zkontrolujte oznámení o pověření, nebo vyberte '
                        . 'jiný certifikát.',
                    422,
                    [
                        'certificate_serial_hex' => $certificate['serial_hex'],
                        'certificate_serial_decimal' => $certificate['serial_decimal'],
                    ],
                );
            }
            // Ukládá se ověřený údaj tak, jak ho uživatel opsal z oznámení ČSSZ.
            // Oddělovače a velikost písmen nejsou významné, vedoucí nula ale
            // zůstává viditelná pro kontrolu proti formuláři a auditní stopu.
            $storedSerial = CsszCertificateSerialNumber::normalizeRegisteredInput(
                $claimedSerialRaw,
            );
            if ($storedSerial !== null && strlen($storedSerial) > self::SERIAL_MAX_LENGTH) {
                return Json::error(
                    $response,
                    'cssz_serial_too_long',
                    'Sériové číslo certifikátu je delší, než kolik jde uložit '
                        . '(64 hexadecimálních znaků). Uložte volbu bez sériového čísla.',
                    422,
                );
            }
        }

        try {
            $saved = $this->profiles->save(
                $supplierId,
                $environment,
                $credentialId,
                $userId,
                $storedSerial,
                $expectedVersion,
                $userId,
            );
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        $profile = $this->presentProfile($saved, $certificate);
        $this->logger->log(
            'payroll_signing_profile_saved',
            $userId,
            'payroll_signing_profile',
            $credentialId,
            [
                'environment' => $environment,
                'credential_id' => $credentialId,
                'serial_verified' => $storedSerial !== null,
            ],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, [
            'environment' => $environment,
            'profile' => $profile,
            'warnings' => $this->profileWarnings($profile, $certificate),
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Zruší volbu pro jedno prostředí.
     *
     * Firma tím zůstane bez podpisového certifikátu pro mzdová podání — to je
     * legitimní stav (například po odvolání pověření), ale je to změna se
     * stejným dopadem jako změna volby, takže se ověřuje stejně.
     */
    public function delete(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        // Prostředí se u DELETE bere z těla i z query stringu: ne každý HTTP klient
        // umí u téhle metody poslat tělo, a step-up credentials tam stejně patří.
        $raw = $body['environment'] ?? ($request->getQueryParams()['environment'] ?? null);
        $environment = $this->normalizeEnvironment($raw);
        if ($environment === null) {
            return $this->invalidEnvironment($response);
        }
        if (!$this->profiles->isAvailable()) {
            return $this->storageUnavailable($response);
        }

        $userId = (int) $this->userId($request);
        $supplierId = $this->currentSupplierId($request);
        try {
            $this->stepUp->verify($request, $userId, $body, self::STEP_UP_OPERATION);
        } catch (EpoSubmissionException $exception) {
            return $this->epoError($response, $exception);
        }

        $existing = $this->profiles->find($supplierId, $environment);
        if ($existing === null) {
            return Json::error(
                $response,
                'signing_profile_not_found',
                'Pro tohle prostředí není žádný certifikát zvolený.',
                404,
            );
        }
        $this->profiles->delete($supplierId, $environment);
        $this->logger->log(
            'payroll_signing_profile_deleted',
            $userId,
            'payroll_signing_profile',
            (int) ($existing['credential_id'] ?? 0),
            ['environment' => $environment],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, [
            'environment' => $environment,
            'deleted' => true,
            'profile' => null,
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        // Volba podpisového certifikátu se nespravuje přes API token: token se dá
        // odcizit a na rozdíl od relace u něj není druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Podpisový certifikát mzdových podání lze spravovat jen z webového rozhraní.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if ($this->userId($request) === null) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function invalidEnvironment(Response $response): Response
    {
        return Json::error(
            $response,
            'validation_failed',
            'Prostředí musí být „test" nebo „production".',
            422,
        );
    }

    private function storageUnavailable(Response $response): Response
    {
        return Json::error(
            $response,
            'signing_profile_storage_unavailable',
            'Úložiště volby certifikátu není k dispozici — neproběhla migrace 1373.',
            503,
        );
    }

    private function epoError(Response $response, EpoSubmissionException $exception): Response
    {
        return Json::error(
            $response,
            $exception->errorCode,
            $exception->getMessage(),
            $exception->httpStatus,
        );
    }

    private function normalizeEnvironment(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::ENVIRONMENTS, true) ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private function sendsRowVersion(array $body): bool
    {
        if (!array_key_exists('row_version', $body)) {
            return false;
        }

        return $body['row_version'] !== null && $body['row_version'] !== '';
    }

    /**
     * @param array<string,mixed> $credential
     * @return array<string,mixed>
     */
    private function presentCertificate(array $credential, int $now): array
    {
        $validFrom = $this->timestamp($credential['valid_from'] ?? null);
        $validTo = $this->timestamp($credential['valid_to'] ?? null);
        $expired = $validTo !== null && $validTo < $now;
        $notYetValid = $validFrom !== null && $validFrom > $now;
        $serialHex = CsszCertificateSerialNumber::canonicalCertificateHex(
            is_scalar($credential['serial_hex'] ?? null) ? (string) $credential['serial_hex'] : '',
        ) ?? '';

        return [
            'id' => (int) ($credential['id'] ?? 0),
            'label' => (string) ($credential['label'] ?? ''),
            'subject' => (string) ($credential['subject_dn'] ?? ''),
            'issuer' => (string) ($credential['issuer_dn'] ?? ''),
            'serial_hex' => $serialHex === '' ? null : $serialHex,
            'serial_decimal' => $serialHex === '' ? null : $this->hexToDecimal($serialHex),
            'valid_from' => $validFrom === null ? null : date('Y-m-d H:i:s', $validFrom),
            'valid_to' => $validTo === null ? null : date('Y-m-d H:i:s', $validTo),
            'expired' => $expired,
            'not_yet_valid' => $notYetValid,
            'usable_now' => !$expired && !$notYetValid,
            'expires_in_days' => $validTo === null ? null : (int) floor(($validTo - $now) / 86400),
            'enabled_for_supplier' => (bool) ($credential['enabled_for_supplier'] ?? false),
            'ik_mpsv_present' => (bool) ($credential['ik_mpsv_present'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $certificate
     * @return array<string,mixed>
     */
    private function presentProfile(array $row, ?array $certificate): array
    {
        $registeredSerial = $row['cssz_registered_serial'] ?? null;

        return [
            'environment' => (string) ($row['environment'] ?? ''),
            'credential_id' => (int) ($row['credential_id'] ?? 0),
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'cssz_registered_serial' => is_scalar($registeredSerial)
                ? CsszCertificateSerialNumber::formatRegisteredForDisplay(
                    (string) $registeredSerial,
                )
                : null,
            'row_version' => (int) ($row['row_version'] ?? 0),
            'created_at' => isset($row['created_at']) && is_scalar($row['created_at'])
                ? (string) $row['created_at']
                : null,
            'updated_at' => isset($row['updated_at']) && is_scalar($row['updated_at'])
                ? (string) $row['updated_at']
                : null,
            // Volbu mohl uložit jiný uživatel svým certifikátem — pak ho tenhle
            // uživatel v trezoru nevidí a nesmí se tvářit, že o něm něco ví.
            'certificate_accessible' => $certificate !== null,
            'certificate' => $certificate,
            'expired' => $certificate !== null && $certificate['expired'] === true,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed>|null $certificate
     * @return list<array{code:string,message:string}>
     */
    private function profileWarnings(array $profile, ?array $certificate): array
    {
        $warnings = [];
        if ($certificate === null) {
            $warnings[] = [
                'code' => 'certificate_not_accessible',
                'message' => 'Zvolený certifikát není ve vašem trezoru — uložil ho jiný '
                    . 'uživatel. Podepsat podání s ním můžete jen vy, pokud ho vlastníte.',
            ];

            return $warnings;
        }
        if ($certificate['expired'] === true) {
            $warnings[] = [
                'code' => 'certificate_expired',
                'message' => 'Zvolený certifikát už vypršel ('
                    . (string) $certificate['valid_to']
                    . '). Mzdová podání se s ním podepsat nedají — obnovte ho dřív, '
                    . 'než přijde termín.',
            ];
        }
        if ($certificate['not_yet_valid'] === true) {
            $warnings[] = [
                'code' => 'certificate_not_yet_valid',
                'message' => 'Zvolený certifikát začne platit až '
                    . (string) $certificate['valid_from']
                    . '. Do té doby s ním podepsat nelze.',
            ];
        }
        if ($certificate['enabled_for_supplier'] !== true) {
            $warnings[] = [
                'code' => 'certificate_not_enabled_for_supplier',
                'message' => 'Zvolený certifikát už pro tuhle firmu není povolený. '
                    . 'Podpis se odmítne, dokud ho v trezoru znovu nepovolíte.',
            ];
        }
        if ($profile['cssz_registered_serial'] === null) {
            $warnings[] = [
                'code' => 'cssz_serial_missing',
                'message' => 'Není vyplněné sériové číslo z oznámení o pověření, takže '
                    . 'nelze ověřit, že ČSSZ zná právě tenhle certifikát.',
            ];
        }

        return $warnings;
    }

    /**
     * Papír od ČSSZ tiskne sériové číslo decimálně, X.509 hexadecimálně a obojí
     * někdy s oddělovači. Srovnává se proto kanonicky a v obou zápisech naráz —
     * jinak by uživatel dostal „nesedí" u čísla, které ve skutečnosti sedí.
     */
    private function compareSerial(?string $certificateSerialHex, string $claimed): string
    {
        if (CsszCertificateSerialNumber::normalizeRegisteredInput(
            (string) $certificateSerialHex,
        ) === null) {
            return self::SERIAL_CERTIFICATE_UNKNOWN;
        }
        if (CsszCertificateSerialNumber::normalizeRegisteredInput($claimed) === null) {
            return self::SERIAL_INPUT_UNREADABLE;
        }

        return CsszCertificateSerialNumber::matches(
            (string) $certificateSerialHex,
            $claimed,
        ) ? self::SERIAL_MATCH : self::SERIAL_MISMATCH;
    }

    /**
     * Sériová čísla certifikátů běžně přetečou 64bitový int, takže se převádí
     * řetězcovou aritmetikou — bez bcmath/gmp, na které se na cizím hostingu
     * nelze spolehnout.
     */
    private function hexToDecimal(string $hex): string
    {
        return CsszCertificateSerialNumber::hexToDecimal($hex);
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }
}
