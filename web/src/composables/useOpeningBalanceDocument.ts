import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import type { OpeningBalanceState } from '@/api/invoices'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

interface OpeningBalanceApi {
  get: (id: number) => Promise<OpeningBalanceState>
  mark: (id: number) => Promise<OpeningBalanceState>
  unmark: (id: number) => Promise<OpeningBalanceState>
}

/**
 * Doklad převzatý z počátečních stavů (vazba na otevírací zápis) v detailu vydané
 * i přijaté faktury. Stav čte GET, označení a zrušení jde přes potvrzovací dialog.
 * Server na PUT/DELETE vyžaduje `accounting.journal.post` a zápis agendy dokladu,
 * klient ani API token akci nedostane.
 */
export function useOpeningBalanceDocument(opts: {
  api: OpeningBalanceApi
  agenda: 'invoices' | 'purchase_invoices'
  enabled: () => boolean
  onChanged: () => Promise<void> | void
}) {
  const { t } = useI18n()
  const toast = useToast()
  const auth = useAuthStore()
  const state = ref<OpeningBalanceState | null>(null)
  const confirming = ref<'mark' | 'unmark' | null>(null)
  const busy = ref(false)
  let generation = 0

  const inOpening = computed(() => state.value?.in_opening === true)
  const canManage = computed(() => opts.enabled() && state.value !== null && !auth.isClientRole
    && auth.canWrite('accounting.journal.post') && auth.canWrite(opts.agenda))

  async function load(id: number): Promise<void> {
    const current = ++generation
    if (!opts.enabled() || id <= 0) {
      state.value = null
      return
    }
    try {
      const loaded = await opts.api.get(id)
      if (current === generation) state.value = loaded ?? null
    } catch {
      if (current === generation) state.value = null
    }
  }

  function reset(): void {
    generation++
    state.value = null
    confirming.value = null
  }

  async function confirm(id: number): Promise<void> {
    const action = confirming.value
    if (!action || busy.value) return
    busy.value = true
    try {
      state.value = action === 'mark' ? await opts.api.mark(id) : await opts.api.unmark(id)
      confirming.value = null
      toast.success(t(action === 'mark' ? 'accounting.opening_balance_document.marked' : 'accounting.opening_balance_document.unmarked'))
      await opts.onChanged()
    } catch (e) {
      toast.error(apiErrorMessage(e))
    } finally {
      busy.value = false
    }
  }

  return { state, inOpening, canManage, confirming, busy, load, reset, confirm }
}
