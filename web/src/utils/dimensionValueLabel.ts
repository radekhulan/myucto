/**
 * Popisek hodnoty dimenze pro všechna místa, která ho skládají sama.
 *
 * Systémová hodnota (má `semantic_key`) nese v kódu interní identifikátor
 * (`economic`, `unit:12`), proto se ukazuje jen název. Pevné hodnoty katalogu
 * rámce 504 se překládají podle významu a typu, ostatní systémové hodnoty
 * (jednotky a služby registru domů) mají název z databáze. Názvy typů se
 * nepřekládají: systémový typ lze přejmenovat, dokud není použitý.
 *
 * Jednotky dvou domů se mohou jmenovat stejně. Kdo popisek skládá do seznamu
 * (výběr, sloupce sestavy), předá `siblings` = hodnoty téhož typu; systémová
 * hodnota, jejíž popisek by splynul s jinou hodnotou, pak dostane za název kód
 * v závorce. Stejné pravidlo drží server v `DimensionValueLabel`.
 */
export interface DimensionLabelI18n {
  t: (key: string) => string
  te: (key: string) => boolean
}

export interface LabelledDimensionValue {
  code: string
  name: string | null
  semantic_key?: string | null
}

type Siblings = readonly LabelledDimensionValue[] | null | undefined

function translatedName(i18n: DimensionLabelI18n, value: LabelledDimensionValue, systemKey?: string | null): string {
  if (value.semantic_key && systemKey && /^\w+$/.test(value.semantic_key) && /^\w+$/.test(systemKey)) {
    const key = `dimensions.system_values.${systemKey}.${value.semantic_key}`
    if (i18n.te(key)) return i18n.t(key)
  }
  return value.name || value.code
}

function plain(value: LabelledDimensionValue, name: string, separator: string): string {
  if (value.semantic_key || !value.name || !value.code || value.code === value.name) return name
  return `${value.code}${separator}${name}`
}

function distinct(label: string, value: LabelledDimensionValue, siblings: Siblings, labelOf: (other: LabelledDimensionValue) => string): string {
  if (!value.semantic_key || !value.code || !siblings) return label
  return siblings.some(other => other.code !== value.code && labelOf(other) === label) ? `${label} (${value.code})` : label
}

export function dimensionValueName(i18n: DimensionLabelI18n, value: LabelledDimensionValue, systemKey?: string | null, siblings?: Siblings): string {
  const name = translatedName(i18n, value, systemKey)
  return distinct(name, value, siblings, other => translatedName(i18n, other, systemKey))
}

/** Kód a název („STR01 – Brno“, v sestavách „STR01 Brno“); systémová hodnota jen názvem. */
export function dimensionValueLabel(i18n: DimensionLabelI18n, value: LabelledDimensionValue, systemKey?: string | null, separator = ' – ', siblings?: Siblings): string {
  const label = plain(value, translatedName(i18n, value, systemKey), separator)
  return distinct(label, value, siblings, other => plain(other, translatedName(i18n, other, systemKey), separator))
}

/** Kód a název bez překladu systémových hodnot, pro texty složené z odpovědi serveru (chybové hlášky). */
export function dimensionValuePlainLabel(value: LabelledDimensionValue, separator = ' '): string {
  return plain(value, value.name || value.code, separator)
}
