<?php

declare(strict_types=1);

/**
 * Ověření transportu EET 2.0 proti neprodukčnímu prostředí (playground) Finanční správy.
 *
 *   php api/bin/eet-playground.php --p12=C:\cesta\CA_EET-Playground-CZ00000019.p12 \
 *       --password-env=EET_PG_PASSWORD --unit=11 --device=MYUCTO-DEV-01 \
 *       --report=C:\mimo-repo\protokoly\playground-2026-10-24
 *
 * Nástroj posílá VÝHRADNĚ na playground (pg.trzbyeet.gov.cz). Produkční adresa se
 * nedá zvolit a produkční certifikát nástroj odmítne. POK z playgroundu není platný
 * potvrzovací kód a nic se jím neeviduje.
 *
 * Testovací certifikáty a heslo k nim zveřejňuje Finanční správa na eet.gov.cz
 * („CAEET_Playground“). Heslo se předává jen přes proměnnou prostředí
 * (`--password-env=NAZEV`) nebo soubor (`--password-file=cesta`), nikdy na příkazové
 * řádce; do výstupu ani do protokolu se nezapisuje.
 *
 * Scénáře:
 *   a) běžná tržba 1 210,00 Kč — očekává potvrzení s POK končícím „-ff“ a ověřeným podpisem
 *   b) opakované zaslání téže tržby (stejná data, nové UUID, prvni_zaslani=false)
 *   c) záporná oprava -210,00 Kč s novým pořadovým číslem
 *   d) ověřovací mód (overeni=true) — očekává Chyba kod=0
 *   e) negativní test: zpráva pozměněná po podpisu — očekává odmítnutí (kód 4)
 *
 * Další volby:
 *   --report=<cesta bez přípony>  uloží <cesta>.json a <cesta>.txt (mimo repozitář)
 *   --connect-ms=2000 --total-ms=3000  síťové limity jednoho pokusu
 *   --only=a,b                     spustí jen vybrané scénáře
 *
 * Exit kódy: 0 všechny scénáře prošly · 1 některý scénář neprošel · 2 chybné argumenty
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Service\Eet\Transport\EetCredentialException;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetSendResult;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\EetValidationException;
use MyInvoice\Service\Eet\Transport\SoapEetTransport;

$options = getopt('', ['p12:', 'password-env:', 'password-file:', 'unit:', 'device:', 'report:', 'connect-ms:', 'total-ms:', 'only:', 'env:', 'endpoint:', 'help']);
$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(2);
};
if (isset($options['help']) || !isset($options['p12'])) {
    fwrite(STDOUT, "Použití: php api/bin/eet-playground.php --p12=<soubor> --password-env=<PROMENNA> [--unit=11] [--device=MYUCTO-DEV-01] [--report=<cesta>]\n");
    exit(isset($options['help']) ? 0 : 2);
}
if (isset($options['endpoint']) || (isset($options['env']) && $options['env'] !== EetEnvironment::Playground->value)) {
    $fail('Nástroj posílá jen na playground; jiné prostředí ani adresu nelze zvolit.');
}
$environment = EetEnvironment::Playground;

$password = null;
if (isset($options['password-env'])) {
    $value = getenv((string) $options['password-env']);
    $password = is_string($value) && $value !== '' ? $value : null;
} elseif (isset($options['password-file'])) {
    $value = @file_get_contents((string) $options['password-file']);
    $password = is_string($value) ? trim($value) : null;
}
if ($password === null || $password === '') {
    $fail('Heslo k certifikátu chybí: použijte --password-env=<PROMENNA> nebo --password-file=<soubor>.');
}
$p12 = @file_get_contents((string) $options['p12']);
if (!is_string($p12) || $p12 === '') {
    $fail('Soubor s certifikátem nelze přečíst.');
}
try {
    $credential = EetSigningCredential::fromPkcs12($p12, $password);
} catch (EetCredentialException $exception) {
    $fail('Certifikát nelze použít: ' . $exception->getMessage());
}
unset($password, $p12);
if ($credential->environmentHint() === EetEnvironment::Production) {
    $fail('Certifikát je produkční; na playground se posílá jen testovací certifikát.');
}
$eic = $credential->eic();
if ($eic === null) {
    $fail('CN certifikátu nemá tvar EIČ.');
}
$unitId = (int) ($options['unit'] ?? 11);
$device = (string) ($options['device'] ?? 'MYUCTO-DEV-01');
$timeouts = new EetTimeouts((int) ($options['connect-ms'] ?? 2000), (int) ($options['total-ms'] ?? 3000));
$only = isset($options['only']) ? array_map('trim', explode(',', (string) $options['only'])) : ['a', 'b', 'c', 'd', 'e'];

$reportBase = null;
if (isset($options['report'])) {
    $reportBase = preg_replace('/\.(json|txt)$/i', '', (string) $options['report']) ?? '';
    $reportDir = dirname($reportBase);
    if (!is_dir($reportDir) && !@mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
        $fail('Adresář pro protokol nelze vytvořit.');
    }
    $repoRoot = realpath(dirname(__DIR__, 2));
    $resolvedDir = realpath($reportDir);
    if ($repoRoot !== false && $resolvedDir !== false) {
        $root = rtrim(strtolower(str_replace('\\', '/', $repoRoot)), '/') . '/';
        $target = rtrim(strtolower(str_replace('\\', '/', $resolvedDir)), '/') . '/';
        if (str_starts_with($target, $root)) {
            $fail('Protokol se neukládá do repozitáře; zvolte cestu mimo něj.');
        }
    }
}

$transport = new SoapEetTransport();
$stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('ymdHis');
$saleAt = new DateTimeImmutable('now');
$sale = new EetSaleData($eic, $unitId, $device, 'MU-' . $stamp . '-1', $saleAt, 121000);
$correction = new EetSaleData($eic, $unitId, $device, 'MU-' . $stamp . '-2', $saleAt->modify('+1 second'), -21000);
$probe = new EetSaleData($eic, $unitId, $device, 'MU-' . $stamp . '-3', $saleAt->modify('+2 seconds'), 10000);

$scenarios = [
    'a' => ['Běžná tržba 1 210,00 Kč', 'Potvrzení s POK -ff a ověřeným podpisem', $sale, true, false, false],
    'b' => ['Opakované zaslání téže tržby', 'Potvrzení (stejná data, nové UUID, prvni_zaslani=false)', $sale, false, false, false],
    'c' => ['Záporná oprava -210,00 Kč', 'Potvrzení s POK -ff a ověřeným podpisem', $correction, true, false, false],
    'd' => ['Ověřovací mód (overeni=true)', 'Chyba kod=0, žádný POK', $probe, true, true, false],
    'e' => ['Negativní test: zpráva pozměněná po podpisu', 'Odmítnutí (kód 4 – neplatný podpis)', $probe, true, false, true],
];

$results = [];
$allPassed = true;
foreach ($scenarios as $key => [$title, $expected, $data, $firstAttempt, $verification, $tamper]) {
    if (!in_array($key, $only, true)) {
        continue;
    }
    $header = EetMessageHeader::create($firstAttempt, $verification);
    try {
        if ($tamper) {
            $signed = $transport->prepare($data, $header, $credential);
            $tampered = str_replace('celk_trzba="100.00"', 'celk_trzba="101.00"', $signed);
            if ($tampered === $signed) {
                throw new RuntimeException('Negativní test nenašel částku k pozměnění.');
            }
            $result = $transport->sendSigned($environment, $tampered, $header, $timeouts);
        } else {
            $result = $transport->send($environment, $data, $header, $credential, $timeouts);
        }
        $error = null;
    } catch (EetValidationException | EetCredentialException | RuntimeException $exception) {
        $result = null;
        $error = $exception->getMessage();
    }
    $passed = $result !== null && match ($key) {
        'a', 'b', 'c' => $result->isConfirmed() && $result->signatureVerified && str_ends_with((string) $result->pok, '-ff') && $result->testFlag === true,
        'd' => $result->outcome === EetOutcome::VerificationOk && $result->errorCode === 0,
        'e' => $result->outcome === EetOutcome::Rejected,
    };
    $allPassed = $allPassed && $passed;
    $results[] = scenarioRow($key, $title, $expected, $passed, $data, $header, $result, $error);
    printf(
        "%s) %-44s %s  outcome=%s kod=%s pok=%s podpis=%s %s ms\n",
        $key,
        $title,
        $passed ? 'OK  ' : 'CHYBA',
        $result?->outcome->value ?? 'výjimka',
        $result->errorCode ?? '-',
        $result->pok ?? '-',
        $result === null ? '-' : ($result->signatureVerified ? 'ověřen' : ($result->signatureFailure ?? 'nepodepsáno')),
        $result->durationMs ?? '-',
    );
    $detail = $error ?? ($passed ? null : $result?->errorMessage);
    if ($detail !== null) {
        printf("   %s\n", $detail);
    }
}

$report = [
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format(DATE_ATOM),
    'environment' => $environment->value,
    'endpoint' => $environment->endpoint(),
    'php_version' => PHP_VERSION,
    'openssl' => OPENSSL_VERSION_TEXT,
    'certificate' => [
        'eic' => $eic,
        'serial_number' => $credential->serialNumber(),
        'fingerprint_sha256' => $credential->fingerprintSha256(),
        'not_before' => $credential->notBefore()->format(DATE_ATOM),
        'not_after' => $credential->notAfter()->format(DATE_ATOM),
        'environment_hint' => $credential->environmentHint()?->value,
    ],
    'timeouts_ms' => ['connect' => $timeouts->connectMs, 'total' => $timeouts->totalMs],
    'all_passed' => $allPassed,
    'scenarios' => $results,
];
if ($reportBase !== null) {
    file_put_contents($reportBase . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    file_put_contents($reportBase . '.txt', textReport($report));
    printf("Protokol: %s.json, %s.txt\n", $reportBase, $reportBase);
}
echo $allPassed ? "Všechny scénáře prošly.\n" : "Některý scénář neprošel.\n";
exit($allPassed ? 0 : 1);

/**
 * @return array<string,mixed>
 */
function scenarioRow(string $key, string $title, string $expected, bool $passed, EetSaleData $data, EetMessageHeader $header, ?EetSendResult $result, ?string $error): array
{
    return [
        'scenario' => $key,
        'title' => $title,
        'expected' => $expected,
        'passed' => $passed,
        'uuid_zpravy' => $header->uuid,
        'dat_odesl' => \MyInvoice\Service\Eet\Transport\EetMessageBuilder::formatDateTime($header->sentAt),
        'prvni_zaslani' => $header->firstAttempt,
        'overeni' => $header->verification,
        'eic_popl' => $data->eic,
        'id_jednotky' => $data->unitId,
        'id_pokl' => $data->deviceCode,
        'porad_cis' => $data->sequenceNumber,
        'dat_trzby' => \MyInvoice\Service\Eet\Transport\EetMessageBuilder::formatDateTime($data->saleAt),
        'celk_trzba' => \MyInvoice\Service\Eet\Transport\EetMessageBuilder::formatAmount($data->totalCents),
        'outcome' => $result?->outcome->value,
        'http_status' => $result?->httpStatus,
        'error_code' => $result?->errorCode,
        'error_message' => $error ?? $result?->errorMessage,
        'pok' => $result?->pok,
        'test' => $result?->testFlag,
        'warnings' => $result->warnings ?? [],
        'signature_verified' => $result->signatureVerified ?? false,
        'signature_failure' => $result?->signatureFailure,
        'response_uuid' => $result?->responseUuid,
        'received_at' => $result?->receivedAt,
        'duration_ms' => $result?->durationMs,
        'x_global_transaction_id' => $result?->transactionId,
        'request_xml' => $result?->requestXml,
        'response_xml' => $result?->responseXml,
    ];
}

/**
 * @param array<string,mixed> $report
 */
function textReport(array $report): string
{
    $lines = [
        'Protokol ověření EET 2.0 proti playgroundu',
        '==========================================',
        'Vytvořeno:   ' . $report['generated_at'],
        'Prostředí:   ' . $report['environment'] . ' (' . $report['endpoint'] . ')',
        'PHP/OpenSSL: ' . $report['php_version'] . ' / ' . $report['openssl'],
        'Certifikát:  EIČ ' . $report['certificate']['eic'] . ', sériové číslo ' . $report['certificate']['serial_number']
            . ', platnost do ' . $report['certificate']['not_after'],
        'Limity:      spojení ' . $report['timeouts_ms']['connect'] . ' ms, celkem ' . $report['timeouts_ms']['total'] . ' ms',
        'Výsledek:    ' . ($report['all_passed'] ? 'všechny scénáře prošly' : 'některý scénář NEPROŠEL'),
        '',
    ];
    foreach ($report['scenarios'] as $row) {
        $lines[] = sprintf('%s) %s — %s', $row['scenario'], $row['title'], $row['passed'] ? 'PROŠEL' : 'NEPROŠEL');
        $lines[] = '   Očekáváno:      ' . $row['expected'];
        $lines[] = '   UUID zprávy:    ' . $row['uuid_zpravy'] . ' (prvni_zaslani=' . ($row['prvni_zaslani'] ? 'true' : 'false')
            . ($row['overeni'] ? ', overeni=true' : '') . ')';
        $lines[] = '   Odesláno:       ' . $row['dat_odesl'] . ', tržba ' . $row['dat_trzby'];
        $lines[] = '   Údaje:          EIČ ' . $row['eic_popl'] . ', jednotka ' . $row['id_jednotky'] . ', pokladna ' . $row['id_pokl']
            . ', č. ' . $row['porad_cis'] . ', částka ' . $row['celk_trzba'];
        $lines[] = '   Výsledek:       ' . ($row['outcome'] ?? 'výjimka') . ' (HTTP ' . ($row['http_status'] ?? '-') . ')';
        if ($row['pok'] !== null) {
            $lines[] = '   POK:            ' . $row['pok'] . ($row['test'] ? ' (test=true)' : '');
        }
        if ($row['error_code'] !== null || $row['error_message'] !== null) {
            $lines[] = '   Kód / text:     ' . ($row['error_code'] ?? '-') . ' / ' . ($row['error_message'] ?? '-');
        }
        foreach ($row['warnings'] as $warning) {
            $lines[] = '   Varování:       ' . $warning['code'] . ' ' . $warning['message'];
        }
        $lines[] = '   Podpis odpov.:  ' . ($row['signature_verified'] ? 'ověřen (I.CA, GFŘ)' : ($row['signature_failure'] ?? 'odpověď bez podpisu'));
        $lines[] = '   Přijato:        ' . ($row['received_at'] ?? '-') . ', UUID odpovědi ' . ($row['response_uuid'] ?? '-');
        $lines[] = '   Doba odezvy:    ' . ($row['duration_ms'] ?? '-') . ' ms, X-Global-Transaction-Id ' . ($row['x_global_transaction_id'] ?? '-');
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
