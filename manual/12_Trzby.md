# 12. Tržby (detailní statistiky vydaných faktur)

**Cesta: `Grafy → Tržby`** (nebo klik na KPI kartu Tržby v [Zisku](11_Zisk.md))

Stránka **Tržby** rozpadá příjmovou stranu firmy do KPI dlaždic, grafů a tabulek —
hloubkový pohled jen na **vydané faktury**. Nahoře je štítek **plátce / neplátce
DPH**, který určuje, jestli se obraty počítají **bez DPH** (plátce) nebo **s DPH**
(neplátce).

![Tržby — KPI dlaždice, měsíční obrat, top klienti, aging, predikce](img/23_trzby.webp)

## 12.1 KPI dlaždice

- **Plovoucí 12měsíční obrat** (rolling) per měna — meziroční srovnání (▲/▼ vs. předchozích 12 měsíců). Informativní obchodní ukazatel, nezávislý na kalendářním roce.
- **Obrat pro registraci DPH** (jen u neplátce) — kolik z limitu **2 000 000 Kč** za kalendářní rok už máš naplněno (progress bar). Varování při překročení 2 000 000 Kč → plátce od 1. 1. dalšího roku, resp. 2 536 500 Kč → plátce ze zákona ihned.
- **Paušální daň** (pokud je relevantní) — naplnění stropu zvoleného pásma paušální daně (§ 7a ZDP).
- **Obrat tento / minulý rok** per měna — s meziroční změnou, počty faktur, klientů a zakázek.
- **Predikce roku** per měna — medián tří odhadů (run-rate, krátkodobý růst, dlouhodobý trend) + rozsah low–high.
- **Vystaveno YTD** — počet vydaných faktur tento rok.
- **Aktivních klientů**, **Ø doba úhrady**, **obrat posledních 30 dní**, **aktivní pravidelné fakturace**.

## 12.2 Grafy a tabulky

- **Měsíční obrat** (bar) za posledních 12 měsíců + linka loňského roku, per měna
- **Kumulativní obrat YTD** vs. loni (do stejného dne v roce)
- **Tržby podle kategorie** (koláč, rolling 12 m, přepočet na CZK) — kategorii tržby vybíráš na faktuře (viz [Tržby podle kategorií](11_Zisk.md#1119-trzby-podle-kategorii) na stránce Zisk)
- **Top klienti** a **Top zakázky** — koláče za letošek a loni, plus tabulky za rolling 12 měsíců
- **Stav faktur** a **stav zakázek** (donut)
- **Závislost na klientech** (concentration risk) — podíl obratu TOP 3 / TOP 5 klientů za rolling 12 měsíců + barevný indikátor rizika
- **Doba úhrady — distribuce** (histogram), **Rozpad obratu podle sazby DPH** (jen plátce)
- **Cash-flow YTD** — kumulativní křivka skutečně inkasovaných plateb (paid_at)
- **Aging** — stáří neuhrazených pohledávek (aktuální / 1–30 / 31–60 / 61–90 / 90+ dní)
- **Distribuce velikosti faktur** (12 m, přepočet na CZK)

> [!TIP]
> Pro **souhrnný** pohled na tržby i náklady vedle sebe (zisk, marže, zdraví firmy)
> použij [Zisk](11_Zisk.md). Tato kapitola je čistě o příjmové straně.
