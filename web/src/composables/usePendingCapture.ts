import { shallowRef } from 'vue'

/**
 * Fotka dokladu pořízená mimo stránku AI importu (dlaždice „Vyfotit doklad"
 * v mobilní nabídce Nákup). Fotoaparát musí otevřít přímo klepnutí uživatele,
 * po navigaci už prohlížeč `input.click()` nepovolí. Dlaždice proto fotku
 * pořídí sama, uloží ji sem a stránka importu si ji po načtení převezme.
 */
const pending = shallowRef<File | null>(null)

export function usePendingCapture() {
  return {
    pending,
    set(file: File): void { pending.value = file },
    take(): File | null {
      const file = pending.value
      pending.value = null
      return file
    },
  }
}
