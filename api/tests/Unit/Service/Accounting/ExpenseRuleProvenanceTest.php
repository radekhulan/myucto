<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Provenience klasifikace nákladu (migrace 1751) — KTERÉ pravidlo o účtu rozhodlo.
 *
 * Do teď se z klasifikátoru vracel jen `source: 'rule'` a jméno pravidla vlepené do
 * textu důvodu. Detail dokladu z toho nedokázal postavit ani odkaz na pravidlo, ani
 * jeho opravu, a `expense_classification_rules.hit_count` zůstával navždy nulový,
 * protože nebylo co započítat. Bez `ruleId` propadlého až do `toArray()` je celá
 * sekce „podle čeho se účtovalo" nepostavitelná.
 */
final class ExpenseRuleProvenanceTest extends TestCase
{
    private const LIMIT = 80000.0;   // §26/2 ZDP

    private ExpenseKindClassifier $c;

    protected function setUp(): void
    {
        $this->c = new ExpenseKindClassifier();
    }

    /** @return list<array<string,mixed>> */
    private static function rule(string $kind, ?string $account = null): array
    {
        return [[
            'id'                   => 42,
            'name'                 => 'Kancelářské potřeby',
            'is_active'            => 1,
            'expense_kind'         => $kind,
            'vendor_name_contains' => 'Papirnictvi',
            'target_account_code'  => $account,
            'application_mode'     => 'auto',
        ]];
    }

    public function testRuleMatchCarriesRuleId(): void
    {
        $s = $this->c->classify(
            'Kancelářský papír A4',
            'Papírnictví Beta s.r.o.',
            null,
            250.0,
            self::LIMIT,
            self::rule('material', '501.100'),
        );

        self::assertNotNull($s);
        self::assertSame('rule', $s->source);
        self::assertSame(42, $s->ruleId, 'Bez id pravidla nejde říct, PODLE ČEHO se účtovalo.');
        self::assertSame(42, $s->toArray()['expense_rule_id']);
    }

    /**
     * Práh §26/2 ZDP jen překlopí DRUH (drobný majetek → dlouhodobý). Rozhodnutí ale
     * pořád vzešlo z pravidla, takže se jeho id nesmí po cestě ztratit — jinak by
     * detail dokladu u majetku nad limit tvrdil „bez pravidla".
     */
    public function testThresholdOverrideKeepsRuleId(): void
    {
        $s = $this->c->classify(
            'Notebook Dell Latitude',
            'Papírnictví Beta s.r.o.',
            null,
            120000.0,
            self::LIMIT,
            self::rule('small_asset'),
        );

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::FixedAsset, $s->kind);
        self::assertSame('threshold', $s->source);
        self::assertSame(42, $s->ruleId);
    }

    /**
     * Pojistka na analytiku PHM návrh zeslabí a účet zahodí. Pravidlo za ním ale
     * pořád stojí a účetní se k němu musí dostat — právě proto, že návrh je slabý.
     */
    public function testFuelGuardKeepsRuleId(): void
    {
        $s = $this->c->classify(
            'Mytí vozu',
            'Papírnictví Beta s.r.o.',
            null,
            300.0,
            self::LIMIT,
            self::rule('material', '501.100'),
            ['fuel' => '501.100'],
        );

        self::assertNotNull($s);
        self::assertNull($s->accountCode, 'Účet se u nepalivového řádku nenabízí.');
        self::assertSame(42, $s->ruleId);
    }

    /**
     * Klíčová slova pravidlo NEMAJÍ a UI nesmí žádné předstírat. Tohle je ten stav,
     * kvůli kterému má detail dokladu říkat „bez pravidla", ne mlčet.
     */
    public function testKeywordMatchHasNoRuleId(): void
    {
        $s = $this->c->classify('Notebook Dell Latitude', 'Alza.cz a.s.', null, 25000.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertNotSame('rule', $s->source);
        self::assertNull($s->ruleId);
        self::assertNull($s->toArray()['expense_rule_id']);
    }
}
