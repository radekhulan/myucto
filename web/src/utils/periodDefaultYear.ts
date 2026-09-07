/**
 * Který rok předvolit v rychlém filtru „měsíc a rok".
 *
 * Why: bez roku je „leden" matoucí — není poznat, o který leden jde, a seznam
 * pak vypadá prázdný nebo naopak plný cizích řádků. Předvolí se proto letošek,
 * a když v něm data nejsou, nejbližší rok, ve kterém něco je (seznam roků chodí
 * ze serveru od nejnovějšího). Prázdná nabídka znamená, že filtrovat není co —
 * a tak se bere i chybějící seznam, protože starší instalace API roky nevrací.
 */
export function pickDefaultYear(years: number[] | null | undefined): number | null {
  if (!years || years.length === 0) return null
  const thisYear = new Date().getFullYear()
  return years.includes(thisYear) ? thisYear : years[0]
}
