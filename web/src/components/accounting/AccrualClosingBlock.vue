<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accrualsApi, type AccrualClosingPending } from '@/api/accruals'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Krok Časové rozlišení v uzávěrce: nezaúčtované řádky položek agendy do konce období.
 * Roční řádky zaúčtuje krok uzávěrky, měsíční patří do agendy (jen upozornění s odkazem).
 */
const props = withDefaults(defineProps<{ periodId: number; periodStatus?: string | null; rowVersion?: number }>(), { periodStatus: null, rowVersion: 0 })
const emit = defineEmits<{ posted: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const pending = ref<AccrualClosingPending | null>(null)
const loading = ref(false)
const posting = ref(false)

const canRead = computed(() => auth.canRead('accounting.accruals'))
// Uzávěrka zaúčtuje rozpuštění agendy jen v období, které se uzavírá; v otevřeném období
// se účtuje v agendě (se zámkem k datu jako každý zápis).
const canPostYearly = computed(() => props.periodStatus === 'closing' && auth.canWrite('accounting.accruals.write')
  && auth.canWrite('accounting.journal.post') && auth.canWrite('accounting.periods.close'))
const pendingTotal = computed(() => (pending.value?.yearly_total ?? 0) + (pending.value?.monthly_total ?? 0))

async function load() {
  if (!canRead.value || !props.periodId) return
  loading.value = true
  try {
    pending.value = await accrualsApi.closingPending(props.periodId)
  } catch {
    pending.value = null
  } finally {
    loading.value = false
  }
}

async function postYearly() {
  if (!pending.value || posting.value || blockDemoMutation()
    || !window.confirm(t('accruals.closing.confirm', { amount: formatMoney(pendingTotal.value) }))) return
  posting.value = true
  try {
    const result = await accrualsApi.closingPost(props.periodId, props.rowVersion)
    toast.success(t('accruals.closing.posted', { count: result.posted, amount: formatMoney(result.total) }))
    emit('posted')
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    posting.value = false
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <div v-if="canRead" class="space-y-3 rounded-md border border-neutral-200 p-3" data-test="accrual-closing-block">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-medium text-neutral-700">{{ t('accruals.closing.title') }}</h3>
      <RouterLink :to="{ name: 'accounting-accruals', query: pending?.ends_on ? { as_of: pending.ends_on } : {} }"
        :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="accrual-closing-agenda">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
        {{ t('accruals.closing.open_agenda') }}
      </RouterLink>
    </div>
    <p class="text-xs text-neutral-500">{{ t('accruals.closing.hint') }}</p>
    <p v-if="loading && !pending" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else-if="pending">
      <p v-if="!pending.yearly.length && !pending.monthly.length" class="text-sm text-neutral-500" data-test="accrual-closing-empty">{{ t('accruals.closing.nothing') }}</p>
      <div v-if="pending.yearly.length" class="space-y-2" data-test="accrual-closing-yearly">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('accruals.item_no') }}</th>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('accruals.title_label') }}</th>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('accruals.account') }}</th>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('accruals.detail.due_on') }}</th>
                <th class="px-2 py-1.5 text-right font-medium">{{ t('accruals.detail.amount_planned') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in pending.yearly" :key="row.schedule_id">
                <td class="whitespace-nowrap px-2 py-1.5 font-mono text-xs">
                  <RouterLink :to="{ name: 'accounting-accrual-detail', params: { id: row.item_id } }" class="text-primary-600 hover:underline">{{ row.item_no }}</RouterLink>
                </td>
                <td class="px-2 py-1.5">{{ row.title }}</td>
                <td class="whitespace-nowrap px-2 py-1.5 font-mono text-xs">{{ row.accrual_account_code }}<span v-if="row.counter_account_code" class="text-neutral-400"> / {{ row.counter_account_code }}</span></td>
                <td class="whitespace-nowrap px-2 py-1.5 text-xs">{{ formatDate(row.due_on) }}</td>
                <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono">{{ formatMoney(row.amount_planned) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t border-neutral-200 font-semibold">
              <tr>
                <td colspan="4" class="px-2 py-1.5">{{ t('accruals.detail.total') }}</td>
                <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono">{{ formatMoney(pending.yearly_total) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div v-if="pending.monthly.length" role="alert" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="accrual-closing-monthly">
        {{ t('accruals.closing.monthly_warning', { count: pending.monthly.length, amount: formatMoney(pending.monthly_total) }) }}
        <RouterLink :to="{ name: 'accounting-accruals', query: { status: 'active', as_of: pending.ends_on } }" class="ml-1 font-medium underline">{{ t('accruals.closing.open_agenda') }}</RouterLink>
      </div>
      <button v-if="canPostYearly && (pending.yearly.length || pending.monthly.length)" type="button" :disabled="posting" data-test="accrual-closing-post"
        :class="[btnFilled('primary'), 'whitespace-nowrap']" @click="postYearly">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('accruals.closing.post') }}
      </button>
      <p v-else-if="periodStatus !== 'closing' && (pending.yearly.length || pending.monthly.length)" class="text-xs text-neutral-500" data-test="accrual-closing-not-closing">
        {{ t('accruals.closing.not_closing') }}
      </p>
    </template>
  </div>
</template>
