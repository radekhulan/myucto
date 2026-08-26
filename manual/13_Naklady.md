# 13. Náklady (detailní statistiky přijatých faktur)

**Cesta: `Grafy → Náklady`** (nebo klik na KPI kartu Náklady v [Zisku](11_Zisk.md))

Zrcadlový protějšek [Tržeb](12_Trzby.md) pro **nákladovou stranu** — hloubkový
pohled jen na **přijaté faktury**. U **plátce DPH** se náklady počítají **bez DPH**
(na vstupu se odečte), u neplátce **s DPH**.

## 13.1 KPI dlaždice

- **Plovoucí 12měsíční náklady** (rolling) per měna + meziroční srovnání
- **Náklady tento / minulý rok** per měna — počty přijatých faktur a dodavatelů
- **Odhad nákladů roku** per měna — sezonalita loňska × meziroční změna
- **Přijato YTD** — počet přijatých faktur tento rok
- **Aktivních dodavatelů**, **Ø doba úhrady dodavatelům**, **náklady posledních 30 dní**
- **Nezaplacené závazky** — kolik čeká na úhradu dodavatelům (z toho kolik po splatnosti)

## 13.2 Grafy a tabulky

- **Měsíční náklady** (bar) za 12 měsíců + loňská linka, **kumulativní platby dodavatelům YTD**
- **Náklady po rocích** a **po měsících** (tabulky)
- **Top dodavatelé** — posledních 12 měsíců
- **Náklady podle kategorií** (12 m) — vyžaduje přiřazené [kategorie nákladů](11_Zisk.md#1118-naklady-podle-kategorii) na přijatých fakturách
- **Nárok na odpočet DPH podle sazby** (jen plátce)
- **Závislost na dodavatelích** (concentration risk) — podíl nákladů TOP 1 / TOP 3 dodavatelů + indikátor rizika
- **Doba úhrady dodavatelům — distribuce** (histogram)
- **Odhad budoucích plateb** — kolik a kdy podle splatností čeká na úhradu
- **Aging závazků** — stáří neuhrazených přijatých faktur
- **Distribuce velikosti přijatých faktur** (12 m)

> [!TIP]
> Kategorie nákladů přiřazuješ v editoru přijaté faktury. Bez nich se rozpad
> „Náklady podle kategorií" smrskne na jediný řádek „Bez kategorie". Souhrnný
> pohled tržby vs. náklady nabízí [Zisk](11_Zisk.md).
