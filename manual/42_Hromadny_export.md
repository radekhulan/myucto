# 42. Hromadný export (ZIP)

### 42.1.1 Cesta: `Daně → Hromadný export`

Stáhne **jeden ZIP** za zvolené období se vším, co účetní pro dané období potřebuje,
roztříděné do pojmenovaných složek. Období může být **jeden měsíc**, nebo **celé
čtvrtletí** (`Q1`–`Q4`) — přepínač je nahoře vedle výběru období. Zaškrtnutím
vyberete, co se zabalí:

- **Vystavené faktury** — PDF a/nebo ISDOC
- **Přijaté faktury** — PDF a/nebo ISDOC (u PDF má přednost originál od dodavatele;
  pokud chybí, vloží se naše rekonstrukce s příponou `-rekonstrukce`)
- **Výpisy z účtu** — PDF a/nebo GPC (originální soubory)
- **Kniha DPH** — měsíční PDF žurnál (u čtvrtletí se přiloží **tři** PDF, jeden za
  každý měsíc kvartálu)

U každé části se hned ukáže počet dostupných dokladů; prázdné části nejdou zaškrtnout.
Při otevření stránky je předvyplněný předchozí kalendářní měsíc, protože balíček se
obvykle připravuje za dokončené období. Výběr **Vše** a **Nic** mění pouze části,
které se mají vložit do následujícího ZIP.

**Zařazení do období je daňově korektní a shodné s výkazy DPH** (přiznání, kontrolní
hlášení, kniha DPH): vystavené dle DUZP, přijaté tuzemské dle pozdějšího z dat
DUZP / vystavení, přijaté zahraniční reverse charge dle DUZP,
výpisy dle data výpisu.

#### Běh na pozadí

Protože u většího počtu faktur může příprava PDF chvíli trvat, export běží jako
**úloha na pozadí** — po spuštění vidíte průběh (stav, postup, krok) a po dokončení
tlačítko **Stáhnout ZIP**. Hotové exporty zůstávají v seznamu **Poslední exporty** a
jdou stáhnout opakovaně; soubor se stažením nemaže. Úklid proběhne automaticky po
7 dnech (nebo ručně tlačítkem koš). Souběžně běží vždy jen jeden export.

Běžící nebo čekající úlohu lze tlačítkem **Zrušit** požádat o ukončení. Po dobu
aktivní úlohy nelze měnit období ani spustit další export. Neúspěšná úloha zůstane
v historii se stavem a textem chyby; po odstranění příčiny spusť nový export.
Zrušené, dokončené a neúspěšné řádky lze z historie smazat. Stažení i vytvoření
balíčku vyžaduje oprávnění k exportu výkazů.

> [!TIP]
> Pro jednoúčelové formáty (jen ISDOC vydaných, jen Pohoda XML) použij
> [Exporty](20_Exporty.md) v sekci Prodej. Hromadný export je komplexní balíček
> „všechno za období pro účetní".
