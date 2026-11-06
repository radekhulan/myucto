import { ref } from 'vue'
import { useTablePrefs } from '@/composables/useTablePrefs'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'

/**
 * Volba výkazů „průběžné časové rozlišení" (381/384 k rozvahovému dni, mimo deník).
 * Zapamatuje se jako lepkavý přepínač pohledu v preferencích uživatele a sdílí ji
 * výsledovka v obou členěních i rozvaha, aby obě sestavy pro klienta seděly k sobě.
 * Výchozí stav je vypnutý: výkaz z deníku beze změny.
 */
export function useAccrualSpread() {
  const prefs = useTablePrefs('financial_statements', [])
  const enabled = ref(false)

  /** Načte zapamatovaný stav; stránka ho volá v onMounted před prvním load(). */
  async function restore(): Promise<void> {
    await ensurePrefsLoaded()
    enabled.value = prefs.flag('accrual_spread')
  }

  function set(value: boolean): void {
    enabled.value = value
    prefs.setFlag('accrual_spread', value)
  }

  /** Parametr dotazu výkazu (i exportu PDF/XLSX). */
  function param(): { accrual_spread?: 1 } {
    return enabled.value ? { accrual_spread: 1 } : {}
  }

  return { enabled, restore, set, param }
}
