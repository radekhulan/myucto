<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Append-only ledger pokusů o odeslání mzdového podání (migrace 1372).
 *
 * Řádek je důkaz o jednom pokusu, ne stavová proměnná. Identita pokusu — podání,
 * kanál, pořadí, idempotenční klíč a otisk požadavku — je v databázi neměnná,
 * `correlation_reference` i `sent_at` jsou jednorázové přiřazení, stavy
 * `completed`/`expired` jsou terminální a mazat nelze nic. Triggery to vynucují
 * bez ohledu na to, co udělá aplikace; tenhle repozitář s nimi proto nebojuje,
 * jen je respektuje a chyby, které jdou poznat dopředu, hlásí jako doménové
 * (`DomainException`) s čitelnou zprávou místo neprůhledného pádu MariaDB.
 */
final class PayrollSubmissionTransportAttemptRepository
{
    private const TABLE = 'payroll_submission_transport_attempts';

    /**
     * Tvrdý strop stránky historie přenosů. Ledger je append-only, takže roste
     * s každým pokusem o odeslání a nikdy se nezmenší.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /**
     * Projekce záměrně vynechává `idempotency_key_hash`.
     *
     * Je to BINARY(32), takže by se volajícímu vracel binární balast, který se
     * nedá serializovat do JSON, a hlavně: hash je jediná stopa po klíči, který
     * se v čitelné podobě nikam neukládá. Kdo potřebuje řádek podle klíče, má
     * findByIdempotencyKey(); ven se hash nedostane.
     */
    private const COLUMNS = 'id, supplier_id, environment, submission_id, channel,
                    attempt_no, status, correlation_reference, request_sha256,
                    response_http_status, error_code, error_message,
                    next_retry_at, poll_count, last_polled_at, last_poll_error,
                    sent_at, completed_at, closed_at, close_attempts,
                    close_error, row_version,
                    created_by, created_at, updated_at';

    /** VARCHAR(500) v utf8mb4 — limit je ve ZNACÍCH, ne v bajtech. */
    public const ERROR_MESSAGE_MAX_LENGTH = 500;

    /** @var list<string> */
    private const ENVIRONMENTS = ['production', 'test'];

    /** @var list<string> */
    private const CHANNELS = [
        'manual_upload',
        'isds',
        'vrep_apep',
        'pikr',
        'health_portal',
        'other',
    ];

    /** MariaDB kód duplicitního unikátního klíče. */
    private const DUPLICATE_KEY = 1062;

    public function __construct(private readonly Connection $db)
    {
    }

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $attemptId,
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->findOne(
            'WHERE supplier_id = ? AND environment = ? AND id = ?',
            [$supplierId, $environment, $attemptId],
        );
    }

    /**
     * Vyhledání podle idempotenčního klíče.
     *
     * Klíč se nikdy neukládá v čitelné podobě — v tabulce je jen jeho SHA-256
     * v BINARY(32). Unikát je globální (napříč firmami i prostředími), protože
     * klíč sám tenanta i prostředí obsahuje; scope řádku proto musí ověřit
     * volající, ne tenhle dotaz. Uvnitř open() se to děje.
     *
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->findOne(
            'WHERE idempotency_key_hash = ?',
            [self::idempotencyHash($idempotencyKey)],
        );
    }

    /** @return array<string,mixed>|null */
    public function findByCorrelation(
        int $supplierId,
        string $environment,
        string $channel,
        string $correlationReference,
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->findOne(
            'WHERE supplier_id = ? AND environment = ? AND channel = ?
                AND correlation_reference = ?
              ORDER BY attempt_no DESC
              LIMIT 1',
            [$supplierId, $environment, $channel, $correlationReference],
        );
    }

    /**
     * Poslední pokusy napříč podáními, od nejnovějšího.
     *
     * Uživatel se ptá „co jsem odeslal a v jakém je to stavu", ne „jak dopadlo
     * podání číslo 14". Bez tohohle pohledu by odpověď existovala jen v
     * databázi a nedala by se v aplikaci najít.
     *
     * Období hlášení nese rovnou tenhle seznam. Je to první údaj, podle kterého
     * uživatel řádek pozná („co jsem poslal za červenec"), a doptávat se na něj
     * u každého podání zvlášť by znamenalo jeden HTTP požadavek navíc na řádek.
     * JOIN je LEVÝ ZÁMĚRNĚ: ledger je přírůstkový důkaz, takže pokus, jehož
     * podání už v evidenci není, musí zůstat vidět — bez období, ale vidět.
     *
     * @return list<array<string,mixed>>
     */
    public function listRecent(
        int $supplierId,
        string $environment,
        int $limit = 50,
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }
        self::assertEnvironment($environment);
        // Limit se vkládá do SQL jako celé číslo, ne parametrem: MariaDB
        // v LIMIT vázané parametry nepřijímá. Rozsah je proto omezený tady.
        $limit = max(1, min($limit, 200));
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::attemptColumns() . ',
                    obligation.period_start, obligation.period_end,
                    submission.submission_kind,
                    submission.status AS submission_status,
                    submission.corrects_submission_id
               FROM ' . self::TABLE . ' attempt
               LEFT JOIN payroll_submissions submission
                      ON submission.supplier_id = attempt.supplier_id
                     AND submission.id = attempt.submission_id
               LEFT JOIN payroll_obligations obligation
                      ON obligation.supplier_id = submission.supplier_id
                     AND obligation.environment = submission.environment
                     AND obligation.id = submission.obligation_id
              WHERE attempt.supplier_id = ? AND attempt.environment = ?
              ORDER BY attempt.id DESC
              LIMIT ' . $limit,
        );
        $statement->execute([$supplierId, $environment]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::normalize($row);
            }
        }

        return $rows;
    }

    /**
     * Jedna stránka historie přenosů i s celkovým počtem.
     *
     * `listRecent()` sám o sobě jen mlčky usekne na 200 pokusů. Ledger je
     * přírůstkový a u firmy, která podává každý měsíc za víc pracovišť, se
     * přes dvě stě pokusů dostane během pár let — a uživatel neměl jak poznat,
     * že starší pokusy vůbec existují, ani jak se k nim dostat.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listRecentPage(
        int $supplierId,
        string $environment,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        if (!$this->isAvailable()) {
            return ['items' => [], 'total' => 0];
        }
        self::assertEnvironment($environment);
        // Strop se klampuje i tady, ne jen na HTTP hranici. Limit i offset se
        // vkládají do SQL jako celá čísla — MariaDB v LIMIT vázané parametry
        // nepřijímá — takže je rozsah omezený právě tady.
        $limit = max(1, min($limit, self::LIST_MAX_LIMIT));
        $offset = max(0, $offset);

        $countStatement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' attempt
              WHERE attempt.supplier_id = ? AND attempt.environment = ?',
        );
        $countStatement->execute([$supplierId, $environment]);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::attemptColumns() . ',
                    obligation.period_start, obligation.period_end,
                    submission.submission_kind,
                    submission.status AS submission_status,
                    submission.corrects_submission_id
               FROM ' . self::TABLE . ' attempt
               LEFT JOIN payroll_submissions submission
                      ON submission.supplier_id = attempt.supplier_id
                     AND submission.id = attempt.submission_id
               LEFT JOIN payroll_obligations obligation
                      ON obligation.supplier_id = submission.supplier_id
                     AND obligation.environment = submission.environment
                     AND obligation.id = submission.obligation_id
              WHERE attempt.supplier_id = ? AND attempt.environment = ?
              ORDER BY attempt.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        $statement->execute([$supplierId, $environment]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::normalize($row);
            }
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Celá historie pokusů jednoho podání v pořadí, v jakém vznikly.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSubmission(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ? AND submission_id = ?
              ORDER BY attempt_no',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::normalize($row);
            }
        }

        return $rows;
    }

    /**
     * Další volné pořadové číslo pokusu.
     *
     * Je to jen návrh, ne rezervace — unikát (firma, prostředí, podání, pořadí)
     * rozhodne až při zápisu, takže souběžný pokus se stejným číslem skončí
     * v open() doménovou chybou a volající to zkusí znovu.
     */
    public function nextAttemptNo(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): int {
        if (!$this->isAvailable()) {
            return 1;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ? AND submission_id = ?',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Založí pokus ve stavu `prepared`.
     *
     * Souběh na idempotenčním klíči NENÍ chyba — přesně kvůli němu klíč existuje:
     * když unikát spadne, vrátí se existující řádek. Vrátit ho ale smíme jen
     * tehdy, když je to opravdu TÝŽ požadavek. Proto se porovná otisk obsahu
     * (`request_sha256`) i scope (firma, prostředí, podání, kanál): stejný klíč
     * s jiným obsahem není opakování, ale chyba volajícího, a stejný klíč v jiné
     * firmě by byl únik dat mezi tenanty.
     *
     * @return array<string,mixed>
     */
    public function open(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $channel,
        int $attemptNo,
        string $idempotencyKey,
        string $requestSha256,
        ?int $createdBy,
    ): array {
        self::assertEnvironment($environment);
        self::assertChannel($channel);
        if ($attemptNo < 1) {
            throw new \DomainException(
                'Pořadové číslo pokusu o odeslání musí být kladné.',
            );
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $requestSha256) !== 1) {
            throw new \DomainException(
                'Otisk požadavku musí být SHA-256 v malých hexadecimálních znacích.',
            );
        }
        $hash = self::idempotencyHash($idempotencyKey);

        try {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (supplier_id, environment, submission_id, channel,
                     attempt_no, status, idempotency_key_hash, request_sha256,
                     created_by)
                 VALUES (?, ?, ?, ?, ?, "prepared", ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $environment,
                $submissionId,
                $channel,
                $attemptNo,
                $hash,
                $requestSha256,
                $createdBy,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== self::DUPLICATE_KEY) {
                throw $exception;
            }

            return $this->resolveDuplicate(
                $hash,
                $supplierId,
                $environment,
                $submissionId,
                $channel,
                $attemptNo,
                $requestSha256,
            );
        }

        return $this->requireById((int) $this->db->pdo()->lastInsertId());
    }

    /**
     * Zaznamená odeslání: pokus přechází do `awaiting_protocol` a dostává
     * correlation reference, bez které by se protokol nedal spárovat zpět.
     *
     * `sent_at` se plní časem databáze (UTC), ne časem PHP — ledger má jednu
     * osu času, a to tu, na které běží ostatní důkazní tabulky.
     *
     * @return array<string,mixed>
     */
    public function markSent(
        int $attemptId,
        string $correlationReference,
        int $httpStatus,
        int $expectedVersion,
        ?string $nextRetryAt = null,
    ): array {
        if (
            preg_match(
                '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D',
                $correlationReference,
            ) !== 1
        ) {
            throw new \DomainException(
                'Correlation reference pokusu o odeslání má nepovolený tvar.',
            );
        }
        self::assertHttpStatus($httpStatus);
        if ($nextRetryAt !== null) {
            $nextRetryAt = self::assertDateTime($nextRetryAt);
        }

        return $this->mutate(
            'SET status = "awaiting_protocol", correlation_reference = ?,
                 response_http_status = ?, sent_at = UTC_TIMESTAMP(),
                 next_retry_at = ?,
                 row_version = row_version + 1',
            [$correlationReference, $httpStatus, $nextRetryAt],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Zaznamená JEDEN dotaz na výsledek zpracování — ať dopadl jakkoli.
     *
     * Počitadlo roste vždy, i když odpověď nedávala smysl. Právě proto strop
     * pokusů funguje: kdyby se počítaly jen úspěšné dotazy, mlčící protistrana
     * by automatiku nechala běžet donekonečna.
     *
     * `$lastPollError` je věta pro člověka, ne kód: dotaz, který selhal, není
     * selhání PODÁNÍ (to je u ČSSZ a nic se mu nestalo), takže sem nepatří
     * `error_code` ani stav `failed`. Prázdná hodnota znamená, že poslední
     * dotaz odpověď dal.
     *
     * @return array<string,mixed>
     */
    public function recordPoll(
        int $attemptId,
        ?string $nextRetryAt,
        ?string $lastPollError,
        int $expectedVersion,
    ): array {
        if ($nextRetryAt !== null) {
            $nextRetryAt = self::assertDateTime($nextRetryAt);
        }
        $error = $lastPollError === null ? null : trim($lastPollError);
        if ($error === '') {
            $error = null;
        }
        if ($error !== null && mb_strlen($error) > self::ERROR_MESSAGE_MAX_LENGTH) {
            $error = mb_substr($error, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        }

        return $this->mutate(
            'SET poll_count = poll_count + 1, last_polled_at = UTC_TIMESTAMP(),
                 last_poll_error = ?, next_retry_at = ?,
                 row_version = row_version + 1',
            [$error, $nextRetryAt],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Automatika to vzdala. `expired` je terminální stav, takže se pokus už
     * nebude dotazovat sám — a protože nezná výsledek, MUSÍ nést důvod, podle
     * kterého se dá jednat ručně.
     *
     * @return array<string,mixed>
     */
    public function markExpired(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        int $expectedVersion,
    ): array {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $errorCode) !== 1) {
            throw new \DomainException(
                'Kód důvodu vzdání pokusu musí odpovídat ^[a-z][a-z0-9_]{0,63}$, dostali jsme "'
                . $errorCode . '".',
            );
        }
        $message = trim($errorMessage);
        if ($message === '') {
            throw new \DomainException(
                'Vzdaný pokus o odeslání musí nést i důvod, nejen kód.',
            );
        }
        if (mb_strlen($message) > self::ERROR_MESSAGE_MAX_LENGTH) {
            $message = mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        }

        return $this->mutate(
            'SET status = "expired", error_code = ?, error_message = ?,
                 next_retry_at = NULL,
                 row_version = row_version + 1',
            [$errorCode, $message],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Doklad o uzavření transakce u VREP. Podací protokol uzavření vyžaduje,
     * takže neuzavřená transakce je porušení pravidel provozu — a bez zápisu by
     * se nedalo poznat, které transakce ještě otevřené jsou.
     *
     * `closed_at` je jednorázové přiřazení (trigger 1379), takže druhé volání
     * nad už uzavřeným pokusem není no-op, ale chyba; volající to musí ošetřit
     * dřív — {@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService::close()}.
     *
     * @return array<string,mixed>
     */
    public function markClosed(int $attemptId, int $expectedVersion): array
    {
        $current = $this->requireById($attemptId);
        if ($current['status'] !== 'completed') {
            throw new \DomainException(
                'Uzavřít transakci lze jen u pokusu s dotaženým protokolem.',
            );
        }
        if ($current['closed_at'] !== null) {
            throw new \DomainException(
                'Transakce pokusu o odeslání #' . $attemptId . ' už je uzavřená.',
            );
        }

        return $this->mutate(
            'SET closed_at = UTC_TIMESTAMP(), close_attempts = close_attempts + 1,
                 close_error = NULL, next_retry_at = NULL,
                 row_version = row_version + 1',
            [],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Neúspěšné uzavření. Nesmí vypadat jako uzavřené — `closed_at` zůstává
     * prázdné a v ledgeru je vidět, kolikrát to nevyšlo a proč.
     *
     * @return array<string,mixed>
     */
    public function recordCloseFailure(
        int $attemptId,
        string $error,
        ?string $nextRetryAt,
        int $expectedVersion,
    ): array {
        $message = trim($error);
        if ($message === '') {
            throw new \DomainException(
                'Neúspěšné uzavření transakce musí nést důvod.',
            );
        }
        if (mb_strlen($message) > self::ERROR_MESSAGE_MAX_LENGTH) {
            $message = mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        }
        if ($nextRetryAt !== null) {
            $nextRetryAt = self::assertDateTime($nextRetryAt);
        }

        return $this->mutate(
            'SET close_attempts = close_attempts + 1, close_error = ?,
                 next_retry_at = ?,
                 row_version = row_version + 1',
            [$message, $nextRetryAt],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Pokusy, které čekají na protokol a mají se znovu zeptat.
     *
     * Dotaz je NAPŘÍČ FIRMAMI, protože běh na pozadí nemá přihlášeného uživatele
     * ani tenanta — scope si dohledá volající z vráceného řádku.
     *
     * Prázdné `next_retry_at` znamená „zeptej se hned", ne „nikdy": pokusy
     * založené před migrací 1379 termín nemají a vynechat je by znamenalo, že
     * na ně automatika nikdy nesáhne.
     *
     * @return list<array<string,mixed>>
     */
    public function listDuePolls(int $limit = 50): array
    {
        return $this->listDue(
            'status = "awaiting_protocol" AND correlation_reference IS NOT NULL',
            $limit,
        );
    }

    /**
     * Dotažené pokusy s neuzavřenou transakcí. Strop pokusů je v dotazu, aby
     * beznadějné uzavírání nezabíralo místo ve frontě.
     *
     * @return list<array<string,mixed>>
     */
    public function listDueCloses(int $limit, int $maxCloseAttempts): array
    {
        return $this->listDue(
            'status = "completed" AND closed_at IS NULL
               AND correlation_reference IS NOT NULL
               AND close_attempts < ' . max(1, $maxCloseAttempts),
            $limit,
        );
    }

    /** @return list<array<string,mixed>> */
    private function listDue(string $condition, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $limit = max(1, min($limit, 200));
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::TABLE . '
              WHERE ' . $condition . '
                AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
              ORDER BY next_retry_at IS NOT NULL, next_retry_at, id
              LIMIT ' . $limit,
        );
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::normalize($row);
            }
        }

        return $rows;
    }

    /**
     * Uzavře pokus jako doručený. `completed` je terminální stav, takže tenhle
     * krok je jednosměrný — a smí se udělat jen nad odeslaným pokusem, jinak by
     * v ledgeru vznikl „dokončený" pokus bez důkazu o odeslání.
     *
     * @return array<string,mixed>
     */
    public function markCompleted(int $attemptId, int $expectedVersion): array
    {
        $current = $this->requireById($attemptId);
        if (
            $current['sent_at'] === null
            || $current['correlation_reference'] === null
        ) {
            throw new \DomainException(
                'Dokončit lze jen pokus, který byl odeslán a má correlation reference.',
            );
        }
        // Terminální stav hlídá i trigger, ale ten vrátí SQLSTATE. Sem se ta
        // situace dostane běžně (souběh automatiky a tlačítka), takže si
        // zaslouží větu, ne pád MariaDB.
        if (in_array($current['status'], ['completed', 'expired'], true)) {
            throw new \DomainException(
                'Pokus o odeslání #' . $attemptId . ' je už uzavřený ('
                . (string) $current['status'] . ') a znovu otevřít se nedá.',
            );
        }

        return $this->mutate(
            'SET status = "completed", completed_at = UTC_TIMESTAMP(),
                 row_version = row_version + 1',
            [],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Zaznamená neúspěch. Kód chyby i zpráva jsou povinné — neúspěch bez kódu
     * by z ledgeru udělal nečitelný záznam a znemožnil rozhodnout, jestli se
     * pokus smí opakovat. Tvar kódu i délku zprávy kontrolujeme tady, aby
     * volající dostal větu, a ne SQLSTATE.
     *
     * @return array<string,mixed>
     */
    public function markFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus,
        ?string $nextRetryAt,
        int $expectedVersion,
    ): array {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $errorCode) !== 1) {
            throw new \DomainException(
                'Kód chyby pokusu o odeslání musí odpovídat ^[a-z][a-z0-9_]{0,63}$, dostali jsme "'
                . $errorCode . '".',
            );
        }
        $message = trim($errorMessage);
        if ($message === '') {
            throw new \DomainException(
                'Neúspěšný pokus o odeslání musí nést i text chyby, nejen kód.',
            );
        }
        // Ořezáváme radši, než abychom kvůli ukecané chybě protistrany přišli
        // o celý záznam o neúspěchu.
        if (mb_strlen($message) > self::ERROR_MESSAGE_MAX_LENGTH) {
            $message = mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        }
        if ($httpStatus !== null) {
            self::assertHttpStatus($httpStatus);
        }
        if ($nextRetryAt !== null) {
            $nextRetryAt = self::assertDateTime($nextRetryAt);
        }

        return $this->mutate(
            'SET status = "failed", error_code = ?, error_message = ?,
                 response_http_status = ?, next_retry_at = ?,
                 row_version = row_version + 1',
            [$errorCode, $message, $httpStatus, $nextRetryAt],
            $attemptId,
            $expectedVersion,
        );
    }

    /**
     * Jediná zapisovací cesta: každý UPDATE posouvá `row_version` právě o jedna
     * a zároveň si ho hlídá v podmínce. Když neprojde ani jeden řádek, není to
     * „nic se nezměnilo", ale prohraný souboj o zámek nebo neexistující pokus —
     * a to se musí ozvat, ne tiše projít.
     *
     * @param list<int|string|null> $parameters
     * @return array<string,mixed>
     */
    private function mutate(
        string $assignments,
        array $parameters,
        int $attemptId,
        int $expectedVersion,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' ' . $assignments . '
              WHERE id = ? AND row_version = ?',
        );
        $statement->execute([...$parameters, $attemptId, $expectedVersion]);
        if ($statement->rowCount() !== 1) {
            $current = $this->findOne('WHERE id = ?', [$attemptId]);
            if ($current === null) {
                throw new \DomainException(
                    'Pokus o odeslání #' . $attemptId . ' neexistuje.',
                );
            }
            throw new \DomainException(
                'Pokus o odeslání #' . $attemptId
                . ' byl mezitím změněn (očekávána verze ' . $expectedVersion
                . ', aktuální ' . (int) $current['row_version'] . ').',
            );
        }

        return $this->requireById($attemptId);
    }

    /**
     * Rozhodne, co znamená spadlý unikát v open().
     *
     * @return array<string,mixed>
     */
    private function resolveDuplicate(
        string $hash,
        int $supplierId,
        string $environment,
        int $submissionId,
        string $channel,
        int $attemptNo,
        string $requestSha256,
    ): array {
        $existing = $this->findOne(
            'WHERE idempotency_key_hash = ?',
            [$hash],
        );
        if ($existing === null) {
            // Klíč je volný, takže kolidovalo pořadí — někdo si číslo vzal dřív.
            throw new \DomainException(
                'Pokus č. ' . $attemptNo . ' u podání #' . $submissionId
                . ' už existuje; načtěte nové pořadové číslo a opakujte.',
            );
        }
        if (
            (int) $existing['supplier_id'] !== $supplierId
            || $existing['environment'] !== $environment
            || (int) $existing['submission_id'] !== $submissionId
            || $existing['channel'] !== $channel
        ) {
            throw new \DomainException(
                'Idempotenční klíč pokusu o odeslání už patří jinému podání.',
            );
        }
        if ($existing['request_sha256'] !== $requestSha256) {
            throw new \DomainException(
                'Stejný idempotenční klíč nesmí nést jiný obsah požadavku.',
            );
        }

        return $existing;
    }

    /** @return array<string,mixed> */
    private function requireById(int $attemptId): array
    {
        $row = $this->findOne('WHERE id = ?', [$attemptId]);
        if ($row === null) {
            throw new \DomainException(
                'Pokus o odeslání #' . $attemptId . ' neexistuje.',
            );
        }

        return $row;
    }

    /**
     * @param list<int|string> $parameters
     * @return array<string,mixed>|null
     */
    private function findOne(string $where, array $parameters): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' ' . $where,
        );
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalize($row);
    }

    /**
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        /** @var array<string,mixed> $normalized */
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        foreach (
            [
                'id', 'supplier_id', 'submission_id', 'attempt_no',
                'row_version', 'poll_count', 'close_attempts',
            ] as $field
        ) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = (int) $normalized[$field];
            }
        }
        foreach (['response_http_status', 'created_by', 'corrects_submission_id'] as $field) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = $normalized[$field] === null
                    ? null
                    : (int) $normalized[$field];
            }
        }
        // Období a identitu podání přidává jen přehled přes LEVÉ joiny, takže
        // u osiřelého pokusu chybí. Prázdno musí zůstat prázdnem, ne "".
        foreach (
            ['period_start', 'period_end', 'submission_kind', 'submission_status'] as $field
        ) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = $normalized[$field] === null
                    ? null
                    : (string) $normalized[$field];
            }
        }

        return $normalized;
    }

    /**
     * Táž projekce s prefixem tabulky, pro dotaz s JOINem.
     *
     * `id`, `status`, `channel` i `created_at` má každá ze tří spojovaných
     * tabulek, takže nekvalifikovaný seznam by byl dvojznačný. Odvozuje se
     * z COLUMNS, aby se oba seznamy nemohly rozejít.
     */
    private static function attemptColumns(): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => 'attempt.' . trim($column),
            explode(',', self::COLUMNS),
        ));
    }

    private static function idempotencyHash(string $idempotencyKey): string
    {
        if (trim($idempotencyKey) === '') {
            throw new \DomainException(
                'Idempotenční klíč pokusu o odeslání nesmí být prázdný.',
            );
        }

        return hash('sha256', $idempotencyKey, true);
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \DomainException(
                'Neznámé prostředí podání: "' . $environment . '".',
            );
        }
    }

    private static function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \DomainException(
                'Neznámý kanál podání: "' . $channel . '".',
            );
        }
    }

    private static function assertHttpStatus(int $httpStatus): void
    {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new \DomainException(
                'HTTP status odpovědi je mimo rozsah 100–599: ' . $httpStatus . '.',
            );
        }
    }

    private static function assertDateTime(string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new \DomainException(
                'Termín dalšího pokusu musí být ve tvaru Y-m-d H:i:s, dostali jsme "'
                . $value . '".',
            );
        }

        return $value;
    }
}
