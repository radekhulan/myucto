<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\MigrationEnumHistory;
use PHPUnit\Framework\TestCase;

/**
 * Pozdější migrace nesmí tiše zúžit ENUM (nebo SET) sloupec.
 *
 * PROČ TO EXISTUJE
 * ---------------------------------------------------------------------------
 * Rozšířit ENUM jde v MariaDB jen znovu vyjmenováním VŠECH hodnot přes
 * `MODIFY COLUMN … ENUM(…)`. Dvě souběžně psané migrace, které obě rozšiřují
 * tentýž sloupec, se proto navzájem přepíšou: `1805` přidala do
 * `import_jobs.source` hodnotu `scan_attach`, paralelně vzniklá `1807` enum
 * vyjmenovala podle staršího stavu bez ní, takže po `migrate.php` hodnota z DB
 * zmizela. Job pak padal na „Data truncated for column 'source'". Každá migrace
 * sama o sobě byla v pořádku, chyba je až v jejich pořadí, a tu žádný test nad
 * jedním souborem ani nad hotovou DB nezachytí.
 *
 * Brána proto staticky přehraje DDL všech migrací v pořadí `migrate.php`
 * a u každé nové definice sloupce ověří, že obsahuje všechny hodnoty, které
 * sloupec v tu chvíli měl.
 *
 * Záměrné odebrání hodnoty (nejdřív UPDATE přemapuje data, pak MODIFY hodnotu
 * zahodí) patří do ALLOWED_NARROWINGS: výjimka pro konkrétní soubor a sloupec,
 * s přesným výčtem odebraných hodnot a zdůvodněním.
 */
final class MigrationEnumNarrowingGuardTest extends TestCase
{
    /**
     * `soubor|tabulka.sloupec` => odebrané hodnoty, jestli jim v témže souboru
     * předchází UPDATE tabulky (přemapování dat), a proč je to v pořádku.
     *
     * @var array<string, array{values: list<string>, remappedInFile: bool, reason: string}>
     */
    private const ALLOWED_NARROWINGS = [
        '1195_payroll_employment_lifecycle.sql|payroll_employments.status' => [
            'values' => ['draft', 'cancelled'],
            'remappedInFile' => true,
            'reason' => 'Přejmenování stavů: v témže souboru se ENUM nejdřív rozšíří o planned/no_show, '
                . "UPDATE přemapuje draft → planned a cancelled → no_show a teprve pak se staré hodnoty zahodí.",
        ],
        '1263_payroll_posting_corrections_only.sql|payroll_posting_batches.status' => [
            'values' => ['reversed'],
            'remappedInFile' => false,
            'reason' => 'Stav reversed nikdy nešel zapsat: aplikace vkládá dávky jen jako prepared a trigger '
                . 'z 1262 povolí UPDATE jen na posted/no_change. Oprava zaúčtované dávky je nově jen novou '
                . 'revizí, takže mrtvá hodnota odchází bez přemapování.',
        ],
        '1590a_payroll_document_delivery_event_names.sql|payroll_document_delivery_events.event_type' => [
            'values' => ['viewed', 'email_notification'],
            'remappedInFile' => true,
            'reason' => 'Přejmenování událostí: v témže souboru se ENUM rozšíří o nové názvy, UPDATE přemapuje '
                . 'viewed → downloaded a email_notification → external_notification a pak se staré zahodí.',
        ],
    ];

    private static ?MigrationEnumHistory $corpus = null;

    private static function corpus(): MigrationEnumHistory
    {
        return self::$corpus ??= MigrationEnumHistory::fromDirectory(dirname(__DIR__, 3) . '/db/migrations');
    }

    public function testNoMigrationSilentlyNarrowsAnEnum(): void
    {
        $offenders = [];
        foreach (self::corpus()->narrowings() as $narrowing) {
            $key = $narrowing['file'] . '|' . $narrowing['table'] . '.' . $narrowing['column'];
            $allowed = self::ALLOWED_NARROWINGS[$key] ?? null;
            if ($allowed !== null
                && $allowed['values'] === $narrowing['missing']
                && $allowed['remappedInFile'] === $narrowing['remappedInFile']
            ) {
                continue;
            }
            $offenders[] = sprintf(
                '%s: %s.%s ztrácí %s (sloupec naposledy definovala %s)',
                $narrowing['file'],
                $narrowing['table'],
                $narrowing['column'],
                "'" . implode("', '", $narrowing['missing']) . "'",
                $narrowing['previousFile'],
            );
        }

        self::assertSame([], $offenders, "Migrace zužuje ENUM: hodnoty zmizí z DB a zápis pak padá na „Data truncated\".\n"
            . "Nejčastěji jde o souběžně psanou migraci, která vyjmenovala ENUM podle starého stavu:\n"
            . "doplň chybějící hodnoty do výčtu. Je-li odebrání záměr (UPDATE přemapuje data a pak\n"
            . "MODIFY hodnotu zahodí), zapiš výjimku do ALLOWED_NARROWINGS se zdůvodněním.\n"
            . implode("\n", $offenders));
    }

    public function testEveryAllowlistedNarrowingStillHappens(): void
    {
        $found = [];
        foreach (self::corpus()->narrowings() as $narrowing) {
            $found[$narrowing['file'] . '|' . $narrowing['table'] . '.' . $narrowing['column']] = $narrowing;
        }

        $stale = [];
        foreach (self::ALLOWED_NARROWINGS as $key => $allowed) {
            self::assertGreaterThan(40, mb_strlen($allowed['reason']), 'Výjimka bez konkrétního zdůvodnění: ' . $key);
            $narrowing = $found[$key] ?? null;
            if ($narrowing === null
                || $narrowing['missing'] !== $allowed['values']
                || $narrowing['remappedInFile'] !== $allowed['remappedInFile']
            ) {
                $stale[] = $key;
            }
        }

        self::assertSame([], $stale, "Výjimka v ALLOWED_NARROWINGS už neodpovídá migracím, uprav ji nebo smaž:\n"
            . implode("\n", $stale));
    }

    /** Pojistka proti tiše prázdné bráně: parser musí nad skutečnými migracemi opravdu něco najít. */
    public function testReplayReconstructsKnownEnums(): void
    {
        self::assertGreaterThan(300, self::corpus()->enumColumnCount());
        $source = self::corpus()->values('import_jobs', 'source');
        self::assertIsArray($source);
        self::assertContains('scan_attach', $source);
        self::assertContains('money_s3_import', $source);
    }

    public function testParallelMigrationDroppingValueIsCaught(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001_jobs.sql', "CREATE TABLE IF NOT EXISTS jobs (\n"
            . "  id INT PRIMARY KEY,\n"
            . "  source ENUM('alpha','beta') NOT NULL\n"
            . ') ENGINE=InnoDB;');
        $history->apply('0002_scan.sql', "ALTER TABLE jobs\n"
            . "    MODIFY COLUMN source ENUM(\n"
            . "        'alpha', 'beta',\n"
            . "        'scan_attach'\n"
            . "    ) NOT NULL;");
        $history->apply('0003_import.sql', "ALTER TABLE jobs\n"
            . "    MODIFY COLUMN source ENUM(\n"
            . "        'alpha', 'beta', 'money_import'\n"
            . "    ) NOT NULL;");

        self::assertSame([[
            'file' => '0003_import.sql',
            'table' => 'jobs',
            'column' => 'source',
            'missing' => ['scan_attach'],
            'previousFile' => '0002_scan.sql',
            'remappedInFile' => false,
        ]], $history->narrowings());
    }

    public function testRelistingEveryValueIsNotANarrowing(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001.sql', "CREATE TABLE jobs (source ENUM('alpha','beta') NOT NULL) ENGINE=InnoDB;");
        $history->apply('0002.sql', "ALTER TABLE jobs MODIFY source ENUM('ALPHA','beta ','gamma') NOT NULL;");

        self::assertSame([], $history->narrowings());
        self::assertSame(['ALPHA', 'beta ', 'gamma'], $history->values('jobs', 'source'));
    }

    public function testCommentsQuotesBackticksAndMultiClauseAlter(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001.sql', "-- MODIFY COLUMN kind ENUM('ignored')\n"
            . "/* ALTER TABLE `docs` MODIFY kind ENUM('ignored'); */\n"
            . "CREATE TABLE IF NOT EXISTS `docs` (\n"
            . "  `id` INT PRIMARY KEY, -- kind ENUM('fake')\n"
            . "  `kind` ENUM('it''s', 'semi;colon', \"dq\") NOT NULL COMMENT 'ENUM(''x'')',\n"
            . "  state ENUM('a','b','c') NOT NULL,\n"
            . "  KEY idx_kind (kind, state),\n"
            . "  CONSTRAINT chk_state CHECK (state IN ('a','b','c'))\n"
            . ') ENGINE=InnoDB;');
        self::assertSame(["it's", 'semi;colon', 'dq'], $history->values('docs', 'kind'));

        $history->apply('0002.sql', "ALTER TABLE `docs`\n"
            . "  ADD COLUMN IF NOT EXISTS note VARCHAR(10) NULL,\n"
            . "  MODIFY COLUMN `kind` ENUM('it''s','semi;colon','dq','new') NOT NULL, /* ok */\n"
            . "  MODIFY state ENUM('a',\n"
            . "    'c') NOT NULL,\n"
            . '  ADD INDEX idx_note (note);');

        self::assertCount(1, $history->narrowings());
        self::assertSame('state', $history->narrowings()[0]['column']);
        self::assertSame(['b'], $history->narrowings()[0]['missing']);
    }

    public function testDelimiterBlocksAndTriggerVariables(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001.sql', "CREATE TABLE t (s ENUM('a','b') NOT NULL) ENGINE=InnoDB;\n"
            . "DELIMITER //\n"
            . "CREATE TRIGGER trg BEFORE UPDATE ON t FOR EACH ROW\n"
            . "BEGIN\n"
            . "  DECLARE v ENUM('a') COLLATE utf8mb4_unicode_ci DEFAULT 'a';\n"
            . "  IF NEW.s <> v THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALTER TABLE t MODIFY s ENUM(''a'')'; END IF;\n"
            . "END//\n"
            . "CREATE PROCEDURE p()\n"
            . "BEGIN\n"
            . "  ALTER TABLE t MODIFY s ENUM('a') NOT NULL;\n"
            . "END//\n"
            . "DELIMITER ;\n"
            . "CALL p();\n");

        self::assertSame(['b'], $history->narrowings()[0]['missing'] ?? null);
        self::assertCount(1, $history->narrowings());
    }

    public function testDroppedOrRecreatedColumnStartsFreshHistory(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001.sql', "CREATE TABLE t (a ENUM('x','y'), b ENUM('x','y'), c ENUM('x','y')) ENGINE=InnoDB;");
        $history->apply('0002.sql', "ALTER TABLE t DROP COLUMN a, ADD COLUMN a ENUM('x');\n"
            . "ALTER TABLE t MODIFY b VARCHAR(10) NULL;\n"
            . "ALTER TABLE t MODIFY b ENUM('z') NULL;\n"
            . "ALTER TABLE t ADD COLUMN IF NOT EXISTS c ENUM('x');\n"
            . "CREATE TABLE IF NOT EXISTS t (c ENUM('q')) ENGINE=InnoDB;\n");
        self::assertSame([], $history->narrowings());
        self::assertSame(['x', 'y'], $history->values('t', 'c'));

        $history->apply('0003.sql', "DROP TABLE IF EXISTS t;\n"
            . "CREATE TABLE t (c ENUM('q')) ENGINE=InnoDB;");
        self::assertSame([], $history->narrowings());
    }

    public function testRenamedColumnAndTableKeepTheirHistory(): void
    {
        $history = new MigrationEnumHistory();
        $history->apply('0001.sql', "CREATE TABLE t (a ENUM('x','y','z')) ENGINE=InnoDB;");
        $history->apply('0002.sql', "RENAME TABLE t TO u;\n"
            . "ALTER TABLE u RENAME COLUMN a TO b;\n"
            . "ALTER TABLE u CHANGE COLUMN b c ENUM('x','y');");
        $history->apply('0003.sql', "ALTER TABLE u RENAME TO w;\n"
            . "UPDATE w SET c = 'x' WHERE c = 'y';\n"
            . "ALTER TABLE w MODIFY c ENUM('x');");

        self::assertSame([
            ['file' => '0002.sql', 'table' => 'u', 'column' => 'c', 'missing' => ['z'], 'previousFile' => '0001.sql', 'remappedInFile' => false],
            ['file' => '0003.sql', 'table' => 'w', 'column' => 'c', 'missing' => ['y'], 'previousFile' => '0002.sql', 'remappedInFile' => true],
        ], $history->narrowings());
    }
}
