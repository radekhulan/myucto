import { computed, unref, type MaybeRef } from 'vue'
import { unitKey } from './useUnitDecimals'

/**
 * Popisky měrných jednotek z číselníku (CS / EN) pro zobrazení v UI.
 *
 * Řádek dokladu ukládá kód jednotky, popisek je jen jeho zobrazení. Kódy se
 * párují jako v `useUnitDecimals` a na serveru (`UnitLabels`). Jednotka mimo
 * číselník a jednotka s prázdným popiskem se zobrazí kódem.
 */
export interface UnitWithLabels {
  code: string
  label_cs?: string | null
  label_en?: string | null
}

export function useUnitLabels(units: MaybeRef<UnitWithLabels[]>, locale: MaybeRef<string>) {
  const map = computed(() => {
    const en = unref(locale) === 'en'
    const out = new Map<string, string>()
    for (const u of unref(units) ?? []) {
      const label = (en ? u.label_en : u.label_cs)?.trim()
      if (label) out.set(unitKey(u.code), label)
    }
    return out
  })

  function unitLabel(unit: string | null | undefined): string {
    return map.value.get(unitKey(unit)) ?? unit ?? ''
  }

  return { unitLabel }
}
