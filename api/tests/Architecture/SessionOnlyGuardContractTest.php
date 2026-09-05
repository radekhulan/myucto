<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Brána „jen z přihlášené relace" se čte přes JEDEN helper a hlásí JEDNÍM kódem.
 *
 * ⚠️ Tenhle test vznikl proto, že to pravidlo bylo 117× opsané ručně a nic ho
 * nedrželo pohromadě. Stejná situace se hlásila jako `session_required`,
 * `forbidden_via_token` i `authentication_required`, jednou 401 a jinde 403 —
 * klient si na to nemohl napsat jednu větev. A hlavně: šestinásobná podmínka
 * `$isSession` v `DocumentViewerResolver` chránila mzdovou evidenci bez jediného
 * komentáře a bez jediného testu, takže ji mohl kdokoli při refaktoru smazat
 * jako zbytečnou a nic by to nezachytilo.
 *
 * Co se hlídá:
 *   1. `ATTR_METHOD` se mimo vyjmenovanou infrastrukturu nečte napřímo,
 *   2. odpověď na chybějící relaci se skládá jen přes `Json::sessionRequired()`,
 *   3. mzdová evidence v Dokumentech je pořád vázaná na relaci.
 */
final class SessionOnlyGuardContractTest extends TestCase
{
    /**
     * Místa, která atribut číst SMÍ: samotný helper, middleware, který ho
     * nastavuje, a ten, který ho u zamčené relace odstraňuje.
     */
    private const ALLOWED_RAW_READS = [
        'src/Middleware/AuthMiddleware.php',
        'src/Middleware/SessionLockMiddleware.php',
        'src/Security/RequestAuthorization.php',
    ];

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($out);
        return $out;
    }

    public function testAuthMethodAttributeIsReadOnlyThroughTheHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach ($this->phpFiles('src') as $rel) {
            if (in_array($rel, self::ALLOWED_RAW_READS, true)) {
                continue;
            }
            $src = (string) file_get_contents($root . '/' . $rel);
            if (str_contains($src, 'ATTR_METHOD')) {
                $offenders[] = $rel;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Tyhle soubory čtou AuthMiddleware::ATTR_METHOD napřímo. Použij\n"
            . "RequestAuthorization::isSessionAuth() pro bránu, nebo isBearerAuth()\n"
            . "tam, kde kanál jen mění chování:\n  " . implode("\n  ", $offenders),
        );
    }

    public function testSessionGuardsAnswerWithASingleErrorCode(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach ($this->phpFiles('src') as $rel) {
            if ($rel === 'src/Http/Json.php') {
                continue;
            }
            $src = (string) file_get_contents($root . '/' . $rel);
            // Kód smí padnout jen z helperu; ručně sestavená odpověď se stejným
            // kódem by obešla jednotný status i text.
            if (preg_match("/Json::error\([^;]*'session_required'/s", $src) === 1) {
                $offenders[] = $rel;
            }
            if (str_contains($src, "'forbidden_via_token'")) {
                $offenders[] = $rel . ' (zrušený kód forbidden_via_token)';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Odpověď na chybějící relaci se skládá jen přes Json::sessionRequired():\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * ⚠️ Mzdová evidence v Dokumentech je session-only. Bez téhle pojistky by
     * ji stačilo odemknout smazáním jedné podmínky — a zdravotní údaje,
     * exekuce i insolvence by tiše vytekly do veřejného API.
     */
    public function testPayrollEvidenceInDocumentsStaysSessionOnly(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/Document/DocumentViewerResolver.php',
        );

        self::assertStringContainsString(
            '$isSession = RequestAuthorization::isSessionAuth($request);',
            $src,
            'Zdroj pravdy o relaci zmizel — příznaky níž pak nemají na čem stát.',
        );

        // Každý příznak musí stát buď přímo na `$isSession`, nebo na jiném
        // příznaku, který na něm stojí (insolvence dědí z exekucí).
        foreach ([
            'canViewPayrollEnforcementEvidence',
            'canViewPayrollInsolvencyEvidence',
            'canViewPayrollSubmissionEvidence',
            'canViewPayrollForeignPermitEvidence',
            'canViewPayrollHealthEvidence',
            'canViewPayrollDocuments',
        ] as $flag) {
            self::assertSame(
                1,
                preg_match('/\$' . $flag . '\s*=\s*(.+?);/s', $src, $m),
                "Příznak {$flag} v resolveru chybí.",
            );
            $assignment = $m[1];
            self::assertMatchesRegularExpression(
                '/\$isSession\b|\$canViewPayroll\w+/',
                $assignment,
                "Příznak {$flag} se odvázal od přihlášené relace — mzdová evidence "
                . 'by tím vytekla do API tokenů.',
            );
        }
    }

    /**
     * ⚠️ Token smí odemknout JEN evidenci podání a jen výslovně.
     *
     * Doručenky a protokoly potřebuje archivační integrace, takže pro ně
     * existuje zaškrtávatelná schopnost tokenu. Zdravotní údaje, exekuce,
     * insolvence, cizinecká povolení ani výplatní pásky se tou schopností
     * odemknout NESMÍ — jsou to zvláštní kategorie osobních údajů. Kdyby někdo
     * `tokenAllowsPayrollSubmissionDocs()` přidal i k nim, spadne to tady.
     */
    public function testOnlySubmissionEvidenceCanBeUnlockedForTokens(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/Document/DocumentViewerResolver.php',
        );

        $occurrences = preg_match_all('/tokenAllowsPayrollSubmissionDocs\(/', $src);
        self::assertSame(
            1,
            $occurrences,
            'Schopnost tokenu smí odemykat jedinou věc — canViewPayrollSubmissionEvidence.',
        );

        self::assertMatchesRegularExpression(
            '/\$canViewPayrollSubmissionEvidence\s*=\s*\([^;]*tokenAllowsPayrollSubmissionDocs\(/s',
            $src,
            'Schopnost tokenu patří výhradně k evidenci mzdových podání.',
        );
    }
}
