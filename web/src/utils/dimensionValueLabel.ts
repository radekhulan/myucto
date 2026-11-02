/**
 * Popisek hodnoty dimenze pro všechna místa, která ho skládají sama.
 *
 * Systémová hodnota (má `semantic_key`) nese v kódu interní identifikátor
 * (`economic`, `unit:12`), proto se ukazuje jen název. Pevné hodnoty katalogu
 * rámce 504 se překládají podle významu a typu, ostatní systémové hodnoty
 * (jednotky a služby registru domů) mají název z databáze. Názvy typů se
 * nepřekládají: systémový typ lze přejmenovat, dokud není použitý.
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

export function dimensionValueName(i18n: DimensionLabelI18n, value: LabelledDimensionValue, systemKey?: string | null): string {
  if (value.semantic_key && systemKey && /^\w+$/.test(value.semantic_key) && /^\w+$/.test(systemKey)) {
    const key = `dimensions.system_values.${systemKey}.${value.semantic_key}`
    if (i18n.te(key)) return i18n.t(key)
  }
  return value.name || value.code
}

/** Kód a název („STR01 – Brno“, v sestavách „STR01 Brno“); systémová hodnota jen názvem. */
export function dimensionValueLabel(i18n: DimensionLabelI18n, value: LabelledDimensionValue, systemKey?: string | null, separator = ' – '): string {
  const name = dimensionValueName(i18n, value, systemKey)
  if (value.semantic_key || !value.name || value.code === value.name) return name
  return `${value.code}${separator}${name}`
}
