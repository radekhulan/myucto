<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Volba typu podání u přiznání k DPH a kontrolního hlášení.
 *
 * Nálezy auditu 20. 9. 2026: tatáž trojice otázek — sedí typ na lhůtu, existuje podání,
 * které opravuje, a sedí perioda na registraci? — byla zodpovězená jen na jedné větvi.
 * KH hlídalo lhůtu i periodu, přiznání ani jedno; přiznání hlídalo základnu dodatečného,
 * KH jen základnu následného. Každý test níž bez opravy padá.
 *
 * Rozšiřuje {@see VatAmendedReturnTest}, protože potřebuje tutéž skládačku (firma, doklady,
 * archivace podání) a ta je tam už postavená a odladěná.
 */
#[Group('integration')]
final class SubmissionVariantGuardTest extends VatAmendedReturnTest
{
    /**
     * Nález 1: přiznání k DPH vůbec nehlídalo volbu typu proti lhůtě — KH ano.
     *
     * Testovací období leží v budoucnu, takže lhůta pro řádné teprve poběží: dodatečné
     * přiznání do ní nepatří a musí varovat. Opačný směr (opravné PO lhůtě) hlídá jednotkový
     * test brány, kde jde podstrčit dnešek; tady jde o to, že builder bránu vůbec volá.
     */
    public function testAmendedVatReturnBeforeDeadlineWarns(): void
    {
        $cust = $this->client('Odběratel', 'CZ90020011');
        $this->sale('VG-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        $baseline = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $baseline['xml'], $baseline['summary'], $this->userId, true, 'B',
        );
        $this->sale('VG-1B', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-15');

        self::assertNotSame([], $result['warnings'], 'Volba typu proti lhůtě musí být vidět.');
        self::assertStringContainsString(
            'OPRAVNÉ přiznání',
            implode(' | ', $result['warnings']),
            'Dokud lhůta běží, opravuje se opravným přiznáním.',
        );
    }

    /** Nález 2: opravné tvrzení bez podané základny nemá co nahradit — u obou tvrzení. */
    public function testCorrectiveWithoutPriorSubmissionIsRejected(): void
    {
        $cust = $this->client('Odběratel', 'CZ90020029');
        $this->sale('VG-2', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        try {
            $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'opravne');
            self::fail('Opravné přiznání bez podaného řádného musí skončit chybou.');
        } catch (PostingException $e) {
            self::assertSame('vat_no_prior_submission', $e->errorCode);
        }

        try {
            $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'opravne');
            self::fail('Opravné kontrolní hlášení bez podaného řádného musí skončit chybou.');
        } catch (PostingException $e) {
            self::assertSame('kh_no_prior_submission', $e->errorCode);
        }
    }

    /** Nález 3: kvartální plátce sestavující měsíční přiznání musí dostat varování. */
    public function testMonthlyReturnForQuarterlyPayerWarns(): void
    {
        $pdo = $this->db->pdo();
        $original = (string) $pdo->query("SELECT vat_period FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
        $pdo->prepare('UPDATE supplier SET vat_period = ? WHERE id = ?')->execute(['quarterly', $this->supplierId]);
        $restore = fn (): bool => $pdo->prepare('UPDATE supplier SET vat_period = ? WHERE id = ?')
            ->execute([$original, $this->supplierId]);

        $cust = $this->client('Odběratel', 'CZ90020037');
        $this->sale('VG-3', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');

        $restore();

        self::assertStringContainsString(
            'čtvrtletní',
            implode(' | ', $result['warnings']),
            'Rozpor mezi registrovanou a sestavovanou periodou musí být vidět.',
        );
    }

    /** Nález 5: druhé řádné tvrzení za už podané období musí varovat — u obou tvrzení. */
    public function testSecondRegularSubmissionForFiledPeriodWarns(): void
    {
        $cust = $this->client('Odběratel', 'CZ90020045');
        $this->sale('VG-5', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $vatBaseline = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $vatBaseline['xml'], $vatBaseline['summary'], $this->userId, true, 'B',
        );
        $khBaseline = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphkh1', self::YEAR, 5, null,
            $khBaseline['xml'], $khBaseline['summary'], $this->userId, true, 'B',
        );

        $vat = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $kh = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');

        self::assertStringContainsString('už je evidované podané tvrzení', implode(' | ', $vat['warnings']));
        self::assertStringContainsString('už je evidované podané tvrzení', implode(' | ', $kh['warnings']));
    }

    /**
     * Issue #379: karta „Termín podání" hlásila „N dní po termínu!" i u podaného období.
     * Souhrn výkazu proto nese rozhodný čas podání v řádné lhůtě z téhož dotazu jako
     * varování o už podaném tvrzení; dodejka (`accepted`) podmínkou není. Opravy po lhůtě
     * (dodatečné, následné) mají vlastní lhůtu a dál odpočítávají.
     */
    public function testSummaryCarriesFiledAtOfRegularSubmission(): void
    {
        $sh = Bootstrap::buildContainer()->get(SouhrnneHlaseniBuilder::class);
        $cust = $this->client('Odběratel', 'CZ90020053');
        $this->sale('VG-6', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $vatBaseline = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $khBaseline = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $shBaseline = $sh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        self::assertNull($vatBaseline['summary']['filed_at']);
        self::assertNull($khBaseline['summary']['filed_at']);
        self::assertNull($shBaseline['summary']['filed_at']);

        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $vatBaseline['xml'], $vatBaseline['summary'], $this->userId, true, 'B',
        );
        $this->archiveAndSubmit(
            $this->supplierId, 'dphkh1', self::YEAR, 5, null,
            $khBaseline['xml'], $khBaseline['summary'], $this->userId, true, 'B',
        );
        $this->archiveAndSubmit(
            $this->supplierId, 'dphshv', self::YEAR, 5, null,
            $shBaseline['xml'], $shBaseline['summary'], $this->userId, true, 'R',
        );

        $vat = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $kh = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $shFiled = $sh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        self::assertNotNull($vat['summary']['filed_at'], 'Podané přiznání musí karta termínu znát.');
        self::assertNotNull($kh['summary']['filed_at'], 'Podané kontrolní hlášení musí karta termínu znát.');
        self::assertNotNull($shFiled['summary']['filed_at'], 'Podané souhrnné hlášení musí karta termínu znát.');

        $this->sale('VG-6B', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);
        $amended = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-15');
        self::assertNull($amended['summary']['filed_at'], 'Dodatečné přiznání má vlastní lhůtu.');
    }
}
