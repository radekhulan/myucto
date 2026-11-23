# 48. Hromadný export (ZIP)

> Návod, jak stáhnout jeden ZIP se vším, co účetní potřebuje za měsíc nebo
> čtvrtletí: faktury, výpisy, GoPay vyúčtování a knihu DPH, roztříděné do
> složek. Pro každého, kdo předává podklady účetní.

## 48.1 Kdy to potřebujete

- Po uzavření měsíce nebo čtvrtletí předáváte podklady externí účetní.
- Potřebujete archivovat všechny doklady za období na jednom místě.
- Chcete mít PDF, ISDOC a bankovní soubory za období pohromadě.

Jednoúčelové formáty (jen ISDOC vydaných, jen Pohoda XML) najdete v kapitole [Exporty](20_Exporty.md).

## 48.2 Než začnete

- Potřebujete oprávnění k exportu výkazů. Bez něj balíček nevytvoříte ani nestáhnete.
- Doklady za období musí být v aplikaci zadané a výpisy nahrané.

## 48.3 Krok za krokem: vytvoření ZIP

1. Otevřete `Daně → Hromadný export`.
2. Přepínačem nahoře zvolte **Měsíc** nebo **Čtvrtletí**. Pak vyberte měsíc (nebo Q1-Q4) a rok. Při otevření stránky je předvyplněný předchozí kalendářní měsíc.
3. V části **Co zahrnout do exportu** zaškrtněte, co se má zabalit. U každé části je vidět počet dostupných dokladů, prázdné části zaškrtnout nejde. Tlačítka **Vybrat vše** a **Zrušit výběr** mění jen výběr pro následující ZIP.
4. Klikněte na **Připravit export**.
5. Sledujte průběh (stav, postup, krok). Export běží na pozadí, stránku můžete nechat otevřenou.
6. Až se v seznamu **Poslední exporty** objeví stav **Hotovo**, klikněte na **Stáhnout ZIP**.

**Jak poznáte, že je hotovo:** Řádek exportu má stav **Hotovo**, počet souborů
a velikost a tlačítko **Stáhnout ZIP** stáhne archiv. Zkontrolujte také log
a `README.txt` uvnitř ZIPu. Chyba jednotlivého PDF či ISDOC může soubor
přeskočit a zbytek exportu přesto dokončit; stav Hotovo sám úplnost nepotvrzuje.

Co lze zabalit:

- **Vystavené faktury** - PDF a/nebo ISDOC.
- **Přijaté faktury** - PDF a/nebo ISDOC. U PDF má přednost originál od dodavatele, pokud chybí, vloží se naše rekonstrukce s příponou `-rekonstrukce`.
- **Výpisy z účtu** - PDF a/nebo GPC (originální soubory).
- **GoPay vyúčtování** - XML a/nebo PDF.
- **Kniha DPH** - PDF. U čtvrtletí se přiloží tři PDF, jeden za každý měsíc kvartálu.

> [!TIP]
> Dokončený export zůstává v seznamu **Poslední exporty** a jde stáhnout opakovaně. Stažením se soubor nemaže.

## 48.4 Krok za krokem: zrušení nebo smazání exportu

1. V seznamu **Poslední exporty** najděte řádek exportu.
2. Běžící nebo čekající úlohu ukončíte tlačítkem **Zrušit**.
3. Hotový, zrušený nebo neúspěšný řádek smažete ikonou koše a potvrdíte dotaz. ZIP se odstraní a znovu ho stáhnout nepůjde.

**Jak poznáte, že je hotovo:** Řádek zmizí ze seznamu, resp. má stav **Zrušeno**.

## 48.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Část nejde zaškrtnout, u počtu je pomlčka | Za zvolené období nejsou pro ni žádná data | Zvolte jiné období nebo část vynechejte |
| Tlačítko **Připravit export** je neaktivní | Není vybrána žádná část s daty, nebo už jiný export běží | Vyberte alespoň jednu část s daty, případně počkejte na dokončení. Souběžně běží vždy jen jeden export. |
| Nejde změnit období | Úloha je aktivní | Počkejte na dokončení, nebo ji zrušte tlačítkem **Zrušit** |
| Export skončil stavem **Chyba** s textem chyby | Příprava selhala | Odstraňte příčinu podle textu chyby a spusťte nový export |
| Stránka ani tlačítka nejsou dostupné | Chybí oprávnění k exportu výkazů | Požádejte správce firmy o oprávnění |

## 48.6 Podrobnosti a pravidla

### 48.6.1 Zařazení dokladů do období

Zařazení do období je daňově korektní a shodné s výkazy DPH (přiznání, kontrolní
hlášení, kniha DPH):

- vystavené doklady podle DUZP,
- přijaté tuzemské podle nejpozdějšího z dat DUZP, vystavení a vědomě ručně
  zadaného data přijetí; automaticky doplněné datum přijetí rozhodné období neposouvá,
- přijaté zahraniční s reverse charge podle DUZP,
- výpisy podle data výpisu,
- GoPay podle období uvedeného ve vyúčtování. Zasahuje-li vyúčtování do zvoleného období, exportuje se jeho XML i dostupné PDF.

Do fakturační části se zahrnují dokončené doklady. Koncepty, doklady ve stavu **Stornováno** a hlavičky
platebních či zálohových kalendářů se neexportují.

### 48.6.2 Běh na pozadí a úklid

Příprava PDF u většího počtu faktur chvíli trvá, proto export běží jako úloha na
pozadí. Hotové exporty zůstávají v seznamu a jdou stáhnout opakovaně. Úklid proběhne
automaticky po 7 dnech nebo ručně ikonou koše. Souběžně běží vždy jen jeden export.
Po dobu aktivní úlohy nelze měnit období ani spustit další export. Neúspěšná úloha
zůstane v historii se stavem a textem chyby.

## 48.7 Související kapitoly

- [Exporty](20_Exporty.md)
- [Kniha DPH](42_Kniha_DPH.md)
- [Přijaté faktury](23_Prijate_faktury.md)
