<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai\Assistant;

use MyInvoice\Service\Ai\Assistant\ManualSearchService;
use PHPUnit\Framework\TestCase;

final class ManualSearchServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/assistant-manual-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/cache', 0775, true);
        file_put_contents($this->dir . '/10_Faktury.md', <<<'MD'
# 10. Faktury

## 10.1 Krok za krokem: vystavit fakturu

Fakturu vystavíte v `Faktury → Nová faktura`. Vyplňte klienta a položky.

### 10.1.1 Splatnost faktury

Splatnost nastavíte u každé faktury zvlášť. Viz [upomínky](22_Upominky.md#221-poslat-upominku) a [výše](#101-krok-za-krokem-vystavit-fakturu).

![Obrázek](img/faktura.webp)

```
## Tohle není nadpis
```

## 10.2 Když něco nejde

Faktura nejde odeslat, když chybí e-mail klienta.
MD);
        file_put_contents($this->dir . '/22_Upominky.md', <<<'MD'
# 22. Upomínky

## 22.1 Poslat upomínku

Upomínku k faktuře po splatnosti pošlete z detailu faktury. Upomínky po splatnosti chodí i automaticky.

## 22.2 Saldo účtu 311

Saldo pohledávek na účtu 311 musí sedět se seznamem neuhrazených faktur.
MD);
        file_put_contents($this->dir . '/INDEX.md', "# Obsah\n\n## Nic\n\nsaldo saldo saldo\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/cache/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir . '/cache');
        foreach (glob($this->dir . '/*.md') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    private function service(): ManualSearchService
    {
        return new ManualSearchService($this->dir, $this->dir . '/cache');
    }

    public function testFindsSectionAcrossCzechInflectionsAndRanksTitleFirst(): void
    {
        $hits = $this->service()->search('upomínka faktury po splatnosti');
        self::assertNotEmpty($hits);
        self::assertSame('22_Upominky#221-poslat-upominku', $hits[0]['section_id']);
        self::assertSame('/manual?ch=22_Upominky#221-poslat-upominku', $hits[0]['url']);
        self::assertSame('22. Upomínky', $hits[0]['chapter']);

        $saldo = $this->service()->search('proč nesedí saldo 311');
        self::assertSame('22_Upominky#222-saldo-uctu-311', $saldo[0]['section_id'], 'INDEX.md se neindexuje');
        self::assertSame([], $this->service()->search('a v na'), 'samá krátká a spojovací slova');
    }

    public function testSectionTextIncludesSubsectionsAndRewritesLinks(): void
    {
        $section = $this->service()->section('10_Faktury#101-krok-za-krokem-vystavit-fakturu');
        self::assertNotNull($section);
        self::assertStringContainsString('10.1.1 Splatnost faktury', $section['text'], 'pododdíl patří k oddílu');
        self::assertStringNotContainsString('10.2 Když něco nejde', $section['text'], 'sourozenecký oddíl už ne');
        self::assertStringContainsString('## Tohle není nadpis', $section['text'], 'nadpis v bloku kódu oddíl neukončí');
        self::assertStringContainsString('](/manual?ch=22_Upominky#221-poslat-upominku)', $section['text']);
        self::assertStringContainsString('](/manual?ch=10_Faktury#101-krok-za-krokem-vystavit-fakturu)', $section['text']);
        self::assertStringNotContainsString('img/faktura.webp', $section['text']);
        self::assertNull($this->service()->section('10_Faktury#neexistuje'));
    }

    public function testIndexIsCachedAndRebuiltAfterManualChange(): void
    {
        $this->service()->search('faktura');
        self::assertCount(1, glob($this->dir . '/cache/manual-index-*.ser') ?: []);
        file_put_contents($this->dir . '/22_Upominky.md', "# 22. Upomínky\n\n## 22.9 Nový oddíl ZZMARKER\n\nText.\n");
        touch($this->dir . '/22_Upominky.md', time() + 5);
        $hits = $this->service()->search('zzmarker');
        self::assertSame('22_Upominky#229-novy-oddil-zzmarker', $hits[0]['section_id'] ?? null);
        self::assertCount(1, glob($this->dir . '/cache/manual-index-*.ser') ?: [], 'starý index se smaže');
    }

    public function testSlugMatchesManualAnchorsInRealManual(): void
    {
        $manual = new ManualSearchService(dirname(__DIR__, 6) . '/manual', $this->dir . '/cache');
        // Kotvy převzaté z odkazů v manuálu; generateManualHtml.php je tvoří stejně.
        foreach (['52_Ucetni_denik#524-krok-za-krokem-rucni-zapis', '52_Ucetni_denik#5212-krok-za-krokem-ostatni-pohledavky-a-zavazky',
            '65_Sablony#658-krok-za-krokem-asistent-nastaveni-uctovani'] as $id) {
            self::assertNotNull($manual->section($id), $id);
        }
        self::assertSame('524-krok-za-krokem-rucni-zapis', ManualSearchService::slug('52.4 Krok za krokem: ruční zápis'));
    }
}
