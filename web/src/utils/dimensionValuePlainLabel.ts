/**
 * Kód a název hodnoty dimenze bez překladu (pravidlo viz `dimensionValueLabel.ts`).
 * Samostatný modul bez překladových klíčů: importují ho i texty skládané z odpovědí
 * serveru mimo stránky dimenzí, které jmenný prostor `dimensions` nenačítají.
 */
export interface PlainDimensionValue {
  code: string
  name: string | null
  semantic_key?: string | null
}

/** Název doplněný kódem; systémová hodnota, prázdný kód nebo kód shodný s názvem jen názvem. */
export function withDimensionCode(value: PlainDimensionValue, name: string, separator: string): string {
  if (value.semantic_key || !value.name || !value.code || value.code === value.name) return name
  return `${value.code}${separator}${name}`
}

/** Kód a název bez překladu systémových hodnot, pro texty složené z odpovědi serveru (chybové hlášky). */
export function dimensionValuePlainLabel(value: PlainDimensionValue, separator = ' '): string {
  return withDimensionCode(value, value.name || value.code, separator)
}
