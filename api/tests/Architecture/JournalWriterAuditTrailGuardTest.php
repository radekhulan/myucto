<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\JournalEntryRepository;
use PHPUnit\Framework\TestCase;

/**
 * § 33a ZoÚ, invariant I28: každý zaúčtovaný zápis má v `activity_log` stopu
 * s `entity_type = 'journal_entry'`. Typované zapisovače v {@see JournalEntryRepository}
 * nejdou přes PostingService, takže stopu musí zapsat samy. Bez ní chybí zápisu
 * historie a I28 ho hlásí až nad daty.
 *
 * Brána: veřejná metoda, která (i přes privátní pomocníky) volá `persistJournal`
 * nebo `insertReversalLocked`, musí (i přes privátní pomocníky) volat
 * `auditTypedPosting` nebo `$this->activity->log` s akcí zaúčtování. Skenují se tokeny, ne text.
 * Výjimku dostává METODA s důvodem.
 */
final class JournalWriterAuditTrailGuardTest extends TestCase
{
    private const WRITERS = ['persistJournal', 'insertReversalLocked'];

    /** Metoda => důvod, proč stopu zapisuje volající. */
    private const ALLOWED = [
        'insert' => 'obecný zápis; stopu zapisuje PostingService (accounting.posted)',
        'insertReversal' => 'obecné storno; stopu zapisuje PostingService::reverse (accounting.reversed)',
    ];

    public function testEveryTypedJournalWriterLogsItsEntry(): void
    {
        $class = new \ReflectionClass(JournalEntryRepository::class);
        $lines = file((string) $class->getFileName());
        $calls = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) continue;
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $calls[$method->getName()] = self::thisCalls($body);
        }
        $writes = fn (string $name): bool => $this->reaches($name, $calls, fn (array $c): bool => array_intersect(self::WRITERS, $c) !== []);
        $logs = fn (string $name): bool => $this->reaches($name, $calls, fn (array $c): bool => in_array('activity->log', $c, true) || in_array('auditTypedPosting', $c, true));

        $missing = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!isset($calls[$name]) || isset(self::ALLOWED[$name]) || !$writes($name)) continue;
            if (!$logs($name)) $missing[] = $name;
        }
        self::assertSame([], $missing, 'Zapisovač deníku bez auditní stopy zápisu (§ 33a, I28).');
        foreach (array_keys(self::ALLOWED) as $name) self::assertTrue($writes($name), "Výjimka {$name} už nic nezapisuje, odeberte ji.");
    }

    /** @param array<string,list<string>> $calls */
    private function reaches(string $name, array $calls, callable $hit, array $seen = []): bool
    {
        if (isset($seen[$name]) || !isset($calls[$name])) return false;
        $seen[$name] = true;
        if ($hit($calls[$name])) return true;
        foreach ($calls[$name] as $callee) {
            if ($this->reaches($callee, $calls, $hit, $seen)) return true;
        }
        return false;
    }

    /** @return list<string> jména volaná přes `$this->x(` a `$this->activity->log(` */
    private static function thisCalls(string $body): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize('<?php ' . $body), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t->text !== '$this' || ($tokens[$i + 1]->text ?? '') !== '->') continue;
            $name = $tokens[$i + 2]->text ?? '';
            if (($tokens[$i + 3]->text ?? '') === '(') $out[] = $name;
            elseif ($name === 'activity' && ($tokens[$i + 3]->text ?? '') === '->' && ($tokens[$i + 4]->text ?? '') === 'log'
                && self::isPostingAction(array_slice($tokens, $i + 6))) $out[] = 'activity->log';
        }
        return $out;
    }

    /**
     * První argument logu musí být jen akce zaúčtování; jiný log (např. `pairing_released`) stopu zápisu nenahrazuje.
     *
     * @param list<\PhpToken> $tokens tokeny za `log(`
     */
    private static function isPostingAction(array $tokens): bool
    {
        $strings = [];
        $depth = 0;
        foreach ($tokens as $t) {
            if ($depth === 0 && ($t->text === ',' || $t->text === ')')) break;
            if (in_array($t->text, ['(', '['], true)) $depth++;
            if (in_array($t->text, [')', ']'], true)) $depth--;
            if ($t->is(T_CONSTANT_ENCAPSED_STRING)) $strings[] = trim($t->text, '\'"');
        }
        return $strings !== [] && array_diff($strings, ['accounting.posted', 'accounting.reposted', 'accounting.reversed']) === [];
    }
}
