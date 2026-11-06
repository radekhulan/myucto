<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;
use Symfony\Component\Process\Process;

/**
 * Anonymizovaná kopie databáze instance pro testovací prostředí.
 *
 * Postup:
 *  1. kontrola, že {@see AnonymizationPolicy} pokrývá živou strukturu (jinak konec),
 *  2. kopie tabulek a dat do NOVÉ databáze (originál se jen čte),
 *  3. vyprázdnění relací, tokenů a front, pseudonymizace ve třech průchodech
 *     (strukturované hodnoty → odvozené klíče a symboly → texty a JSON),
 *  4. vypnutí odchozích integrací, nové zapečetění auditní stopy, smazání
 *     historie systémově verzovaných tabulek,
 *  5. teprve pak pohledy, triggery a rutiny, značka `anonymized_clone` v `app_meta`,
 *  6. volitelně zrcadlo úložiště se zástupnými soubory a dump.
 *
 * Částky, data, vazby a čísla dokladů se nemění, výkazy kopie proto dávají
 * stejná čísla jako originál.
 */
final class AnonymizationService
{
    public const MARKER_KEY = 'anonymized_clone';

    private const PAGE = 1000;

    /** @var array<string, array<string,string>> */
    private array $cache = [];

    private int $cacheSize = 0;

    private Pseudonymizer $pseudonymizer;

    private ?TextScrubber $scrubber = null;

    private ?PayrollSensitiveData $sensitive = null;

    private ?string $passwordHash = null;

    public function __construct(private readonly Config $config) {}

    /**
     * @param callable(string):void $log
     * @return array{
     *   tables:int, truncated:int, changed_rows:array<string,int>, dictionary:int,
     *   leaks:array<string,int>, users:list<array{id:int,email:string,name:string}>,
     *   files:?array{files:int,bytes:int}, dump:?string, seconds:float
     * }
     */
    public function run(AnonymizationOptions $options, callable $log): array
    {
        $started = microtime(true);
        $this->assertNames($options);
        $pdo = $this->serverPdo();
        $this->prepareTarget($pdo, $options, $log);

        $log('Kontrola pokrytí struktury politikou anonymizace…');
        $problems = $this->coverageProblems($pdo, $options->source);
        if ($problems !== []) {
            throw new \RuntimeException("Politika anonymizace nepokrývá strukturu zdrojové databáze:\n  " . implode("\n  ", $problems));
        }

        $cloner = new DatabaseCloner($pdo);
        $plan = $cloner->plan($options->source);
        $log(sprintf('Kopíruji %d tabulek do %s…', count($plan['tables']), $options->target));

        try {
            $cloner->copyTables($plan, $options->target, $log);
            $transformed = $this->transform($pdo, $options, $plan['system_versioned'], $log);
            $report = $transformed['report'];
            $log('Zakládám pohledy, triggery a rutiny…');
            $cloner->finalize($plan, $options->target);
            $this->writeMarker($pdo, $transformed['archives'], $transformed['transport']);
        } catch (\Throwable $e) {
            $pdo->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($options->target));
            throw $e;
        }

        $report['leaks'] = $this->leakCheck($pdo, $options);
        $report['users'] = $this->users($pdo);
        $report['tables'] = count($plan['tables']);

        $report['files'] = null;
        if ($options->filesOut !== null) {
            $log('Zrcadlím úložiště se zástupnými soubory…');
            $report['files'] = PlaceholderFiles::mirror($options->filesFrom ?? RuntimePaths::storage(), $options->filesOut, $this->pseudonymizer, $log);
        }
        $report['dump'] = null;
        if ($options->dumpPath !== null) {
            $log('Vytvářím dump…');
            $this->dump($options);
            $report['dump'] = $options->dumpPath;
        }
        $report['seconds'] = round(microtime(true) - $started, 1);

        return $report;
    }

    /**
     * Anonymizuje na místě kopii, kterou už {@see DatabaseCloner::copyTables()} založil
     * a která ještě nemá triggery: zachycení, pseudonymizace, přepisy grafů, nové
     * zapečetění auditní stopy a smazání historie proběhnou přesně jako v {@see run()}.
     * Nekopíruje, nezakládá triggery a nesrovnává s originálem (kontrola úniků).
     *
     * Slouží testům přepisů jednotlivých grafů, kterým stačí kopie bez zakládání
     * dalších databází. Cílem nesmí být databáze, se kterou instance pracuje.
     *
     * @param callable(string):void $log
     * @return array{truncated:int, changed_rows:array<string,int>, dictionary:int, archives:int, submissions:int}
     */
    public function anonymizeCopy(AnonymizationOptions $options, callable $log): array
    {
        $target = $options->target;
        $live = (string) $this->config->get('db.name', '');
        if (!AnonymizationOptions::isSafeDatabaseName($target)
            || ($live !== '' && strcasecmp($live, $target) === 0)
            || in_array(strtolower($target), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new \InvalidArgumentException("Databázi {$target} nelze anonymizovat na místě.");
        }
        $pdo = $this->serverPdo();
        $cloner = new DatabaseCloner($pdo);
        $plan = $cloner->plan($target);
        if ($plan['triggers'] !== [] || $plan['views'] !== []) {
            throw new \RuntimeException("Databáze {$target} už má triggery nebo pohledy, není to kopie před dokončením.");
        }
        $problems = $this->coverageProblems($pdo, $target);
        if ($problems !== []) {
            throw new \RuntimeException("Politika anonymizace nepokrývá strukturu databáze:\n  " . implode("\n  ", $problems));
        }
        // Stejný stav spojení, v jakém transformaci zanechá DatabaseCloner::copyTables().
        $pdo->exec('USE ' . DatabaseCloner::quote($target));
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        $transformed = $this->transform($pdo, $options, $plan['system_versioned'], $log);
        $this->writeMarker($pdo, $transformed['archives'], $transformed['transport']);

        return $transformed['report'] + ['archives' => $transformed['archives'], 'submissions' => $transformed['transport']['count']];
    }

    /**
     * Vše mezi kopií tabulek a založením triggerů. Spojení musí stát v cílové databázi.
     *
     * @param list<string> $systemVersioned
     * @param callable(string):void $log
     * @return array{report:array{truncated:int, changed_rows:array<string,int>, dictionary:int}, archives:int, transport:array{count:int, root:?string}}
     */
    private function transform(PDO $pdo, AnonymizationOptions $options, array $systemVersioned, callable $log): array
    {
        $protectedRows = CloneProtectedRows::create($pdo, $options->target);
        $captureConnection = Connection::withoutSharedTestConnection(fn () => new Connection($this->targetConfig($options->target)));
        try {
            $payrollInputs = PayrollCostCenterCloneScrubber::capture($captureConnection);
            $settlementInputs = SettlementCloneScrubber::captureInputs($captureConnection);
            $securitiesDebtInputs = SecuritiesDebt504CloneScrubber::captureInputs($captureConnection);
            $frameworkConversionInputs = FrameworkConversionCloneScrubber::captureInputs($captureConnection);
            $capitalInputs = CapitalCloneScrubber::capture($captureConnection);
            $membershipInputs = MembershipCloneScrubber::capture($captureConnection);
            // Stránkované posouzení J6 nemá typový přepis grafu; kopie se zastaví dřív,
            // než by jeho otisky zůstaly k pseudonymizovaným podkladům neplatné.
            foreach (['nonprofit_tax_drafts', 'nonprofit_tax_snapshot_manifests', 'nonprofit_tax_calculation_manifests', 'nonprofit_tax_source_evidence_manifests'] as $pagedTable) {
                if ($captureConnection->pdo()->query('SELECT 1 FROM ' . $pagedTable . ' LIMIT 1')->fetchColumn() !== false) {
                    throw new \RuntimeException('Anonymizace stránkovaného daňového posouzení neziskové organizace není podporována. Zdrojová databáze zůstala zachovaná.');
                }
            }
            $log('Zachytávám finanční archivy DPPO 504…');
            $archiveCount = NonprofitDppoArchiveCloneCapture::capture($captureConnection, $protectedRows);
            NonprofitTaxSavingsCloneScrubber::capture($captureConnection);
            $dppoInputs = NonprofitDppoCloneScrubber::capture($captureConnection);
            $nonprofitInputs = NonprofitFundingCloneScrubber::capture($captureConnection);
        }
        finally { $captureConnection->close(); }
        $report = $this->anonymize($pdo, $options, $log, $protectedRows);
        $log('Vypínám odchozí integrace…');
        foreach (AnonymizationPolicy::POST_SQL as $sql) {
            $pdo->exec($sql);
        }
        LegacyCostCenterCloneScrubber::run($pdo, $this->scrubber);
        $payrollConnection = Connection::withoutSharedTestConnection(fn () => new Connection($this->targetConfig($options->target)));
        try { PayrollCostCenterCloneScrubber::run($payrollConnection, $this->scrubber, $payrollInputs); } finally { $payrollConnection->close(); }
        (new JointAllocationCloneScrubber($pdo, $this->scrubber))->run();
        $registryMap = (new PropertyCloneScrubber($pdo, $this->scrubber))->run();
        $versionMap = (new ChargesCloneScrubber($pdo, $this->scrubber))->run($registryMap);
        $targetConnection = Connection::withoutSharedTestConnection(fn () => new Connection($this->targetConfig($options->target)));
        try {
            $issuanceMap = (new IssuanceCloneScrubber($targetConnection, $this->scrubber))->run($registryMap, $versionMap);
            $paymentMap = (new PaymentsCloneScrubber($targetConnection, $this->scrubber))->run($issuanceMap,$registryMap,$settlementInputs);
            (new ReceivablesCloneScrubber($targetConnection, $this->scrubber))->run($registryMap, $issuanceMap, $paymentMap);
            SecuritiesDebt504CloneScrubber::run($targetConnection, $this->scrubber, $securitiesDebtInputs);
            AccountDefinitionCloneScrubber::run($targetConnection, $this->scrubber);
            FrameworkConversionCloneScrubber::run($targetConnection, $this->scrubber, $frameworkConversionInputs);
            CapitalCloneScrubber::run($targetConnection, $this->scrubber, $capitalInputs);
            $legalHashes = NonprofitDppoCloneScrubber::runProfiles($targetConnection, $this->scrubber, $dppoInputs);
            MembershipCloneScrubber::run($targetConnection, $this->scrubber, $membershipInputs, $legalHashes);
            NonprofitFundingCloneScrubber::run($targetConnection, $this->scrubber, $nonprofitInputs, $legalHashes);
            $log('Přepisuji finanční archivy DPPO 504…');
            $archivedSubmissions = (new NonprofitDppoArchiveCloneAdapter($targetConnection, $this->scrubber, $protectedRows))->run();
            NonprofitTaxSavingsCloneScrubber::run($targetConnection, $this->scrubber);
            $transport = (new TaxSubmissionTransportCloneScrubber($targetConnection))->run($archivedSubmissions);
        } finally {
            $targetConnection->close();
        }
        $protectedRows->drop();
        $this->resealAuditChain($options->target, $log);
        foreach ($systemVersioned as $table) {
            $pdo->exec('DELETE HISTORY FROM ' . DatabaseCloner::quote($table));
        }

        return ['report' => $report, 'archives' => $archiveCount, 'transport' => $transport];
    }

    /** @param array{count:int, root:?string} $transport */
    private function writeMarker(PDO $pdo, int $archives, array $transport): void
    {
        $pdo->prepare('REPLACE INTO app_meta (k, v) VALUES (?, ?)')->execute([
            self::MARKER_KEY,
            (string) json_encode([
                'created_at' => gmdate('c'),
                'tool' => 'anonymize-clone',
                'financial_archive' => [
                    'archives' => $archives,
                    'submissions' => $transport['count'],
                    'transport_root' => $transport['root'],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param callable(string):void $log
     * @return array{truncated:int, changed_rows:array<string,int>, dictionary:int}
     */
    private function anonymize(PDO $pdo, AnonymizationOptions $options, callable $log, ?CloneProtectedRows $protectedRows = null): array
    {
        $secret = $options->seed !== null && $options->seed !== ''
            ? hash('sha256', 'myucto-anonymize:' . $options->seed, true)
            : random_bytes(32);
        $this->pseudonymizer = new Pseudonymizer($secret);
        $scrubber = $this->scrubber = new TextScrubber($this->pseudonymizer);
        $this->cache = [];
        $this->cacheSize = 0;
        $targetConfig = $this->targetConfig($options->target);
        $this->sensitive = new PayrollSensitiveData(new SecretEncryption($targetConfig), $targetConfig);
        $this->passwordHash = $options->password !== null && $options->password !== ''
            ? (new PasswordHasher($this->config))->hash($options->password)
            : password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $this->preserveAccounts($pdo);

        $log('Vyprazdňuji relace, tokeny a fronty…');
        foreach (array_keys(AnonymizationPolicy::TRUNCATE) as $table) {
            $pdo->exec('DELETE FROM ' . DatabaseCloner::quote($table));
        }

        $meta = $this->columnMeta($pdo, $options->target);
        $keys = $this->primaryKeys($pdo, $options->target);
        $changed = [];
        $phases = [
            'strukturované hodnoty' => static fn (string $s): bool => in_array($s, AnonymizationPolicy::PHASE_STRUCTURED, true) || AnonymizationPolicy::isSealed($s),
            'odvozené klíče a symboly' => static fn (string $s): bool => in_array($s, AnonymizationPolicy::PHASE_DERIVED, true),
            'texty a JSON' => static fn (string $s): bool => in_array($s, AnonymizationPolicy::PHASE_TEXT, true),
        ];
        $tables = AnonymizationPolicy::COLUMNS;
        ksort($tables);
        foreach ($phases as $label => $inPhase) {
            $log("Pseudonymizace — {$label}…");
            foreach ($tables as $table => $columns) {
                $selected = array_filter($columns, $inPhase);
                if ($selected === []) {
                    continue;
                }
                $count = $this->processTable($pdo, $scrubber, $table, $selected, $meta[$table] ?? [], $keys[$table] ?? [], $protectedRows);
                if ($count > 0) {
                    $changed[$table] = ($changed[$table] ?? 0) + $count;
                }
            }
        }

        return [
            'truncated' => count(AnonymizationPolicy::TRUNCATE),
            'changed_rows' => $changed,
            'dictionary' => $this->pseudonymizer->dictionary()->count(),
        ];
    }

    /**
     * @param array<string,string> $columns
     * @param array<string, array{nullable:bool, type:string, max:?int, json:bool}> $meta
     * @param list<array{name:string, integer:bool}> $primaryKey
     */
    private function processTable(
        PDO $pdo,
        TextScrubber $scrubber,
        string $table,
        array $columns,
        array $meta,
        array $primaryKey,
        ?CloneProtectedRows $protectedRows = null,
    ): int {
        if ($primaryKey === []) {
            throw new \RuntimeException("Tabulka {$table} nemá primární klíč — nelze ji pseudonymizovat po řádcích.");
        }
        $needed = array_keys($columns);
        foreach ($columns as $strategy) {
            if (AnonymizationPolicy::isSealed($strategy)) {
                $spec = AnonymizationPolicy::parseSealed($strategy);
                $needed[] = 'supplier_id';
                $needed[] = $spec['entity'];
                if ($spec['type_column'] !== null) {
                    $needed[] = $spec['type_column'];
                }
            }
        }
        $pkNames = array_column($primaryKey, 'name');
        $select = array_values(array_unique([...$pkNames, ...$needed]));
        $selectSql = implode(', ', array_map(DatabaseCloner::quote(...), $select));
        $order = implode(', ', array_map(DatabaseCloner::quote(...), $pkNames));
        $keyset = count($primaryKey) === 1 && $primaryKey[0]['integer'];
        $where = implode(' AND ', array_map(static fn (string $c): string => DatabaseCloner::quote($c) . ' = ?', $pkNames));
        // Sloupce `ON UPDATE CURRENT_TIMESTAMP` se přiřadí samy sobě — pseudonymizace
        // není změna záznamu a časy poslední úpravy mají v kopii zůstat původní.
        $keepTimestamps = '';
        foreach ($meta as $column => $info) {
            if (($info['on_update'] ?? false) && !isset($columns[$column])) {
                $keepTimestamps .= ', ' . DatabaseCloner::quote($column) . ' = ' . DatabaseCloner::quote($column);
            }
        }

        $changedRows = 0;
        $last = null;
        $statements = [];
        // Složený nebo textový primární klíč se pseudonymizací může měnit (mapy převodů
        // klíčované kódem z cizího programu) — stránkování přes ORDER BY … OFFSET by pak
        // řádky přeskakovalo nebo bralo dvakrát. Takové tabulky se načtou naráz.
        $pending = $keyset ? null : array_chunk(
            $pdo->query("SELECT {$selectSql} FROM " . DatabaseCloner::quote($table) . " ORDER BY {$order}")->fetchAll(PDO::FETCH_ASSOC),
            self::PAGE,
        );
        // Zachycené archivy DPPO 504 přepisuje typově NonprofitDppoArchiveCloneAdapter; obecný
        // průchod je přeskočí po stránkách, aby se jejich zapečetěný obsah nezměnil napůl.
        $protectedTable = $keyset && $protectedRows !== null && $pkNames === ['id'] ? FinancialArchiveProtectedTable::tryFrom($table) : null;
        while (true) {
            $pageEnd = null;
            if ($protectedTable !== null) {
                $pageEnd = $protectedRows->lastIdOfPage($protectedTable, $last === null ? null : (int) $last, self::PAGE);
                if ($pageEnd === null) {
                    break;
                }
                $rows = $protectedRows->selectUnprotectedRange($protectedTable, $select, $last === null ? null : (int) $last, $pageEnd);
            } elseif ($keyset) {
                $sql = "SELECT {$selectSql} FROM " . DatabaseCloner::quote($table)
                    . ($last === null ? '' : ' WHERE ' . $order . ' > ' . (int) $last)
                    . " ORDER BY {$order} LIMIT " . self::PAGE;
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = array_shift($pending) ?? [];
            }
            if ($rows === [] && $pageEnd !== null) {
                $last = $pageEnd;
                continue;
            }
            if ($rows === []) {
                break;
            }
            $pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $updates = $this->rowUpdates($scrubber, $table, $columns, $row, $meta);
                    if ($updates === []) {
                        continue;
                    }
                    $signature = implode(',', array_keys($updates));
                    $statements[$signature] ??= $pdo->prepare(
                        'UPDATE ' . DatabaseCloner::quote($table) . ' SET '
                        . implode(', ', array_map(static fn (string $c): string => DatabaseCloner::quote($c) . ' = ?', array_keys($updates)))
                        . $keepTimestamps
                        . ' WHERE ' . $where,
                    );
                    $pkValues = array_map(static fn (string $pk): mixed => $row[$pk], $pkNames);
                    for ($attempt = 1; ; $attempt++) {
                        try {
                            $statements[$signature]->execute([...array_values($updates), ...$pkValues]);
                            break;
                        } catch (\PDOException $e) {
                            // Jména z pseudonymů nejsou prostá (dva lidé mohou dostat stejné
                            // příjmení). Narazí-li to na jedinečný index, odliší se číslem.
                            if (($e->errorInfo[1] ?? null) !== 1062 || $attempt > 20) {
                                throw $e;
                            }
                            $updates = $this->disambiguate($updates, $columns, $attempt, $meta);
                        }
                    }
                    $changedRows++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("Pseudonymizace tabulky {$table} selhala: " . $e->getMessage(), 0, $e);
            }
            if ($pageEnd !== null) {
                $last = $pageEnd;
                continue;
            }
            $last = $keyset ? end($rows)[$pkNames[0]] : null;
            if ($keyset ? count($rows) < self::PAGE : $pending === []) {
                break;
            }
        }

        return $changedRows;
    }

    /**
     * @param array<string,string> $columns
     * @param array<string,mixed> $row
     * @param array<string, array{nullable:bool, type:string, max:?int, json:bool}> $meta
     * @return array<string, string|null>
     */
    private function rowUpdates(TextScrubber $scrubber, string $table, array $columns, array $row, array $meta): array
    {
        $updates = [];
        foreach ($columns as $column => $strategy) {
            $old = $row[$column] ?? null;
            if ($old === null) {
                continue;
            }
            $old = (string) $old;
            if (AnonymizationPolicy::isSealed($strategy)) {
                if ($old === '') {
                    continue;
                }
                foreach ($this->reseal($column, $strategy, $old, $row) as $col => $value) {
                    $updates[$col] = $value;
                }
                continue;
            }
            $new = match ($strategy) {
                'wipe' => ($meta[$column]['nullable'] ?? true) ? null : (($meta[$column]['json'] ?? false) ? '{}' : ''),
                'token' => self::randomToken($old),
                'password' => $this->passwordHash,
                'blob_pdf' => $old === '' ? $old : PlaceholderFiles::pdf(),
                'blob_text' => $old === '' ? $old : PlaceholderFiles::content('txt'),
                'json' => $old === '' ? $old : ($table === 'accounting_closing_steps' && $column === 'payload'
                    ? AssetReserveSourceCloneScrubber::payload(Fx504CloneScrubber::payload($old), $scrubber) : $this->cached($scrubber, $strategy, $table === 'activity_log' && $column === 'payload' ? Fx504CloneScrubber::payload($old) : $old)),
                default => $old === '' ? $old : $this->cached($scrubber, $strategy, $old),
            };
            if ($new !== null) {
                $new = self::fit($new, $meta[$column] ?? null);
            }
            if ($new !== $old) {
                $updates[$column] = $new;
            }
        }

        return $updates;
    }

    /**
     * @param array<string, string|null> $updates
     * @param array<string,string> $columns
     * @param array<string, array{nullable:bool, type:string, max:?int, json:bool, on_update?:bool}> $meta
     * @return array<string, string|null>
     */
    private function disambiguate(array $updates, array $columns, int $attempt, array $meta): array
    {
        foreach ($updates as $column => $value) {
            $strategy = $columns[$column] ?? '';
            if ($value === null || !in_array($strategy, ['party_name', 'person_name', 'first_name', 'last_name', 'text', 'street', 'city', 'address', 'file_name'], true)) {
                continue;
            }
            $base = preg_replace('/ #\d+$/', '', $value) ?? $value;
            $suffix = ' #' . ($attempt + 1);
            $max = $meta[$column]['max'] ?? null;
            if ($max !== null && mb_strlen($base . $suffix, 'UTF-8') > $max) {
                $base = mb_substr($base, 0, max(0, $max - mb_strlen($suffix, 'UTF-8')), 'UTF-8');
            }
            $updates[$column] = $base . $suffix;
        }

        return $updates;
    }

    private function cached(TextScrubber $scrubber, string $strategy, string $value): string
    {
        if (isset($this->cache[$strategy][$value])) {
            return $this->cache[$strategy][$value];
        }
        if ($this->cacheSize > 200_000) {
            $this->cache = [];
            $this->cacheSize = 0;
        }
        $this->cacheSize++;

        return $this->cache[$strategy][$value] = $scrubber->byStrategy($strategy, $value);
    }

    /**
     * Šifrovaná mzdová hodnota: dešifrovat klíčem instance, nahradit pseudonymem
     * a zapečetit znovu (šifrový text, vyhledávací otisk i maska). Když se
     * originál dešifrovat nedá (jiný klíč), dostane řádek vymyšlenou hodnotu —
     * starý šifrový text v kopii nezůstane nikdy.
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function reseal(string $column, string $strategy, string $ciphertext, array $row): array
    {
        $spec = AnonymizationPolicy::parseSealed($strategy);
        $supplierId = (int) ($row['supplier_id'] ?? 0);
        $entityId = (int) ($row[$spec['entity']] ?? 0);
        $type = $spec['type_column'] !== null ? (string) ($row[$spec['type_column']] ?? '') : $spec['field'];
        [$field, $kind] = match ($type) {
            'birth_number', 'personal_identifier' => [PayrollSensitiveField::PERSONAL_IDENTIFIER, 'birth_number'],
            'tax_family_identifier' => [PayrollSensitiveField::TAX_FAMILY_IDENTIFIER, 'birth_number'],
            'ecp', 'vcp', 'health_insurance_number' => [PayrollSensitiveField::PERSONAL_IDENTIFIER, 'shape'],
            'foreign_tax_identifier' => [PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER, 'shape'],
            'email', 'contact_email' => [PayrollSensitiveField::CONTACT_EMAIL, 'email'],
            'phone', 'contact_phone' => [PayrollSensitiveField::CONTACT_PHONE, 'phone'],
            'bank_account' => [PayrollSensitiveField::BANK_ACCOUNT, 'bank_account'],
            'person_external_identifier' => [PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER, 'shape'],
            'employment_external_identifier' => [PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER, 'shape'],
            'registration_a1_profile' => [PayrollSensitiveField::REGISTRATION_A1_PROFILE, 'json'],
            'external_jmhz_payload' => [PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, 'json'],
            default => throw new \RuntimeException("Neznámý typ šifrované hodnoty: {$type}"),
        };
        $sensitive = $this->sensitive ?? throw new \LogicException('Šifrování není připravené.');
        try {
            $plain = $sensitive->reveal($ciphertext, $field, $supplierId, $entityId, PayrollRevealPurpose::ANONYMIZATION);
        } catch (\Throwable) {
            $plain = null;
        }
        $scrubber = $this->scrubber ?? throw new \LogicException('Pseudonymizace není připravená.');
        if ($plain === null || trim($plain) === '') {
            $seed = substr(hash('sha256', $ciphertext), 0, 12);
            $plain = match ($kind) {
                'birth_number' => '800101' . sprintf('%04d', hexdec(substr($seed, 0, 4)) % 10000),
                'bank_account' => (string) (100000 + hexdec(substr($seed, 0, 5)) % 900000) . '/0100',
                'email' => 'x' . $seed . '@example.cz',
                'phone' => '+420 6' . sprintf('%08d', hexdec(substr($seed, 0, 6)) % 100_000_000),
                'json' => '{}',
                default => 'X' . strtoupper($seed),
            };
        }
        $pseudo = $kind === 'json' ? $scrubber->scrubJson($plain) : $scrubber->byStrategy($kind, $plain);
        $sealed = $sensitive->seal($pseudo, $field, $supplierId, $entityId);
        $out = [$column => $sealed->ciphertext];
        $out[$spec['hash']] = $sealed->lookupHash;
        if ($spec['masked'] !== null) {
            $out[$spec['masked']] = $sealed->masked;
        }

        return $out;
    }

    private function preserveAccounts(PDO $pdo): void
    {
        foreach (AnonymizationPolicy::PRESERVED_ACCOUNT_SQL as $sql) {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $account) {
                $this->pseudonymizer->preserveAccount((string) $account);
            }
        }
        $sensitive = $this->sensitive;
        if ($sensitive === null) {
            return;
        }
        $rows = $pdo->query('SELECT id, supplier_id, bank_account_ciphertext FROM payroll_institution_accounts')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try {
                $this->pseudonymizer->preserveAccount($sensitive->reveal(
                    (string) $row['bank_account_ciphertext'],
                    PayrollSensitiveField::BANK_ACCOUNT,
                    (int) $row['supplier_id'],
                    (int) $row['id'],
                    PayrollRevealPurpose::ANONYMIZATION,
                ));
            } catch (\Throwable) {
                // Nedešifrovatelný účet instituce — v kopii se prostě nepozná.
            }
        }
    }

    /**
     * Auditní stopa se po pseudonymizaci payloadů zapečetí znovu, jinak by
     * ověření řetězu v kopii hlásilo manipulaci u každého záznamu. Řetěz kopie
     * dokazuje jen integritu kopie, nic o originálu.
     *
     * @param callable(string):void $log
     */
    private function resealAuditChain(string $target, callable $log): void
    {
        $connection = new Connection($this->targetConfig($target));
        $chain = new ActivityLogHashChain($connection);
        if (!$chain->isAvailable()) {
            return;
        }
        $pdo = $connection->pdo();
        if ($connection->hasColumn('activity_log_chain_head', 'deferred_from_id')) {
            // Chráněná část se vrátí mezi čekající a zapečetí znovu od první zapečetěné
            // řádky; nechráněná historie pod ní zůstane bez hashe jako v originálu.
            $from = $pdo->query('SELECT MIN(id) FROM activity_log WHERE hash IS NOT NULL')->fetchColumn();
            if ($from === false || $from === null) {
                return;
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE hash IS NOT NULL')->fetchColumn();
            $log(sprintf('Znovu pečetím auditní stopu (%d záznamů)…', $count));
            $pdo->exec('UPDATE activity_log SET prev_hash = NULL, hash = NULL, seal_seq = NULL WHERE hash IS NOT NULL');
            $pdo->prepare('UPDATE activity_log_chain_head SET last_hash = NULL, last_id = NULL, last_seq = 0, deferred_from_id = ? WHERE id = 1')
                ->execute([(int) $from]);
            $chain->sealPending();
            return;
        }
        if ($connection->hasTable('activity_log_chain_head')) {
            $pdo->exec('UPDATE activity_log_chain_head SET last_hash = NULL, last_id = NULL WHERE id = 1');
        }
        $ids = $pdo->query('SELECT id FROM activity_log WHERE hash IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $log(sprintf('Znovu pečetím auditní stopu (%d záznamů)…', count($ids)));
        foreach (array_chunk($ids, self::PAGE) as $chunk) {
            $pdo->beginTransaction();
            foreach ($chunk as $id) {
                $chain->seal((int) $id);
            }
            $pdo->commit();
        }
    }

    /**
     * Kontrola po běhu: u strukturovaných sloupců spočítá řádky, kde hodnota
     * v kopii zůstala shodná s originálem. Nenulové číslo je buď zachovaný veřejný
     * účet instituce, nebo hodnota, kterou generátor neumí (a je třeba ji prověřit).
     *
     * @return array<string,int>
     */
    private function leakCheck(PDO $pdo, AnonymizationOptions $options): array
    {
        $keys = $this->primaryKeys($pdo, $options->target);
        $skip = ['keep', 'text', 'json', 'wipe', 'token', 'password', 'blob_pdf', 'blob_text', 'file_path', 'symbol', 'sealed_companion', 'city', 'zip'];
        $leaks = [];
        foreach (AnonymizationPolicy::COLUMNS as $table => $columns) {
            $pk = array_column($keys[$table] ?? [], 'name');
            if ($pk === []) {
                continue;
            }
            $join = implode(' AND ', array_map(static fn (string $c): string => 'c.' . DatabaseCloner::quote($c) . ' = o.' . DatabaseCloner::quote($c), $pk));
            foreach ($columns as $column => $strategy) {
                if (in_array($strategy, $skip, true)) {
                    continue;
                }
                $col = DatabaseCloner::quote($column);
                $count = (int) $pdo->query(
                    'SELECT COUNT(*) FROM ' . DatabaseCloner::quote($options->target) . '.' . DatabaseCloner::quote($table) . ' c'
                    . ' JOIN ' . DatabaseCloner::quote($options->source) . '.' . DatabaseCloner::quote($table) . " o ON {$join}"
                    . " WHERE c.{$col} = o.{$col} AND c.{$col} IS NOT NULL AND c.{$col} <> ''",
                )->fetchColumn();
                if ($count > 0) {
                    $leaks["{$table}.{$column}"] = $count;
                }
            }
        }

        return $leaks;
    }

    /** @return list<array{id:int,email:string,name:string}> */
    private function users(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, email, name FROM users ORDER BY id LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'name' => (string) $r['name']], $rows);
    }

    /** @param callable(string):void $log */
    private function prepareTarget(PDO $pdo, AnonymizationOptions $options, callable $log): void
    {
        $exists = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$options->source]);
        if ($exists->fetchColumn() === false) {
            throw new \RuntimeException("Zdrojová databáze {$options->source} neexistuje.");
        }
        $exists->execute([$options->target]);
        if ($exists->fetchColumn() === false) {
            return;
        }
        if (!$options->replace) {
            throw new \RuntimeException("Cílová databáze {$options->target} už existuje. Přepsat ji lze jen volbou --replace, a to jen pokud je to anonymizovaná kopie.");
        }
        $marker = null;
        try {
            $stmt = $pdo->prepare('SELECT v FROM ' . DatabaseCloner::quote($options->target) . '.app_meta WHERE k = ?');
            $stmt->execute([self::MARKER_KEY]);
            $marker = $stmt->fetchColumn();
        } catch (\Throwable) {
            $marker = false;
        }
        if ($marker === false || $marker === null) {
            throw new \RuntimeException("Cílová databáze {$options->target} není anonymizovaná kopie (chybí značka " . self::MARKER_KEY . ') — nepřepíšu ji.');
        }
        $log("Odstraňuji předchozí anonymizovanou kopii {$options->target}…");
        $pdo->exec('DROP DATABASE ' . DatabaseCloner::quote($options->target));
    }

    private function assertNames(AnonymizationOptions $options): void
    {
        foreach (['zdroj' => $options->source, 'cíl' => $options->target] as $label => $name) {
            if (!AnonymizationOptions::isSafeDatabaseName($name)) {
                throw new \InvalidArgumentException("Neplatný název databáze ({$label}): povolena jsou písmena, číslice a podtržítko.");
            }
        }
        if (strcasecmp($options->source, $options->target) === 0) {
            throw new \InvalidArgumentException('Zdroj a cíl musí být různé databáze.');
        }
        $live = (string) $this->config->get('db.name', '');
        if ($live !== '' && strcasecmp($live, $options->target) === 0) {
            throw new \InvalidArgumentException("Cíl {$options->target} je databáze, se kterou pracuje tahle instance — do ní kopii nezapíšu.");
        }
        if (in_array(strtolower($options->target), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new \InvalidArgumentException('Cílem nesmí být systémová databáze.');
        }
    }

    /** @return list<string> */
    private function coverageProblems(PDO $pdo, string $source): array
    {
        $views = array_keys(array_filter((new DatabaseCloner($pdo))->tableTypes($source), static fn (string $type): bool => $type === 'VIEW'));
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, CONCAT(COLUMN_TYPE, ' ', EXTRA) AS definition
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?",
        );
        $stmt->execute([$source]);
        $tables = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((string) $row['TABLE_NAME'], $views, true)) {
                $tables[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = (string) $row['definition'];
            }
        }
        $fk = $pdo->prepare(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?',
        );
        $fk->execute([$source]);
        $foreignKeys = [];
        foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $foreignKeys[(string) $row['TABLE_NAME']][] = [
                'table' => (string) $row['TABLE_NAME'],
                'references' => (string) $row['REFERENCED_TABLE_NAME'],
                'on_delete' => (string) $row['DELETE_RULE'],
            ];
        }

        return AnonymizationPolicyAudit::problems($tables, $foreignKeys);
    }

    /** @return array<string, array<string, array{nullable:bool, type:string, max:?int, json:bool}>> */
    private function columnMeta(PDO $pdo, string $schema): array
    {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, EXTRA
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
        );
        $stmt->execute([$schema]);
        $meta = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = strtolower((string) $row['DATA_TYPE']);
            $binary = in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true);
            $max = $binary ? $row['CHARACTER_OCTET_LENGTH'] : $row['CHARACTER_MAXIMUM_LENGTH'];
            $meta[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = [
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'type' => $binary ? 'binary' : $type,
                'max' => $max === null ? null : (int) $max,
                'json' => false,
                'on_update' => str_contains(strtolower((string) $row['EXTRA']), 'on update'),
            ];
        }
        $checks = $pdo->prepare('SELECT TABLE_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?');
        $checks->execute([$schema]);
        foreach ($checks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (preg_match('/json_valid\(`([^`]+)`\)/i', (string) $row['CHECK_CLAUSE'], $m) === 1 && isset($meta[(string) $row['TABLE_NAME']][$m[1]])) {
                $meta[(string) $row['TABLE_NAME']][$m[1]]['json'] = true;
            }
        }

        return $meta;
    }

    /** @return array<string, list<array{name:string, integer:bool}>> */
    private function primaryKeys(PDO $pdo, string $schema): array
    {
        $types = [];
        $columns = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
        $columns->execute([$schema]);
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = strtolower((string) $row['DATA_TYPE']);
        }
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY TABLE_NAME, ORDINAL_POSITION",
        );
        $stmt->execute([$schema]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            $keys[$table][] = [
                'name' => $column,
                'integer' => in_array($types[$table][$column] ?? '', ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true),
            ];
        }

        return $keys;
    }

    private function serverPdo(): PDO
    {
        $host = (string) $this->config->get('db.host', '127.0.0.1');
        $port = (int) $this->config->get('db.port', 3306);
        $charset = (string) $this->config->get('db.charset', 'utf8mb4');
        $pdo = new PDO("mysql:host={$host};port={$port};charset={$charset}", (string) $this->config->get('db.user'), (string) $this->config->get('db.pass', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        return $pdo;
    }

    private function targetConfig(string $target): Config
    {
        $data = $this->config->all();
        $data['db'] = array_replace(is_array($data['db'] ?? null) ? $data['db'] : [], ['name' => $target]);

        return new Config($data, $this->config->dataDir());
    }

    private function dump(AnonymizationOptions $options): void
    {
        $binary = $options->dumpBinary ?? 'mariadb-dump';
        $process = new Process([
            $binary,
            '--host=' . (string) $this->config->get('db.host', '127.0.0.1'),
            '--port=' . (int) $this->config->get('db.port', 3306),
            '--user=' . (string) $this->config->get('db.user'),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file=' . $options->dumpPath,
            $options->target,
        ], null, ['MYSQL_PWD' => (string) $this->config->get('db.pass', '')]);
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Dump selhal (' . $binary . '): ' . trim($process->getErrorOutput()));
        }
    }

    /** @param array{nullable:bool, type:string, max:?int, json:bool}|null $meta */
    private static function fit(string $value, ?array $meta): string
    {
        $max = $meta['max'] ?? null;
        if ($max === null || $max <= 0) {
            return $value;
        }
        if (($meta['type'] ?? '') === 'binary') {
            return strlen($value) > $max ? substr($value, 0, $max) : $value;
        }

        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }

    private static function randomToken(string $old): string
    {
        $length = strlen($old);
        if ($length === 0) {
            return $old;
        }
        if (preg_match('/^[0-9a-f]+$/D', $old) === 1) {
            return substr(bin2hex(random_bytes(intdiv($length, 2) + 1)), 0, $length);
        }
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
