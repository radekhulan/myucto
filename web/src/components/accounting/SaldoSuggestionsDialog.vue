<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi,
  type SaldoAdvanceSuggestion,
  type SaldoJournalSuggestion,
  type SaldoSettlementSuggestion,
} from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { MAX_GROUPS_PER_REQUEST, apiErrorMessage, withPeriodForce, type SaldoAllocationResultLike } from './saldoSettle'

/**
 * Návrh párování: jisté shody otevřených dokladů saldokonta se zápisy v deníku
 * a záloh 314/324 s konečnými fakturami k hromadnému potvrzení. Každý potvrzený návrh
 * je samostatná skupina: když jednu server odmítne (doklad mezitím uhrazený), ostatní projdou.
 */
const props = defineProps<{
  periodId: number
  asOf: string
  account: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done'): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const items = ref<SaldoSettlementSuggestion[]>([])
const truncated = ref(false)
const loading = ref(true)
const saving = ref(false)
const checked = ref<Set<string>>(new Set())
const failures = ref<{ key: string; message: string }[]>([])

const allChecked = computed(() => items.value.length > 0 && checked.value.size === items.value.length)
const selectedTotal = computed(() => Math.round(items.value
  .filter(i => checked.value.has(i.key))
  .reduce((s, i) => s + amountOf(i), 0) * 100) / 100)

function amountOf(s: SaldoSettlementSuggestion): number {
  return s.type === 'advance' ? s.amount : s.allocations.reduce((a, x) => a + x.amount, 0)
}

async function load() {
  loading.value = true
  try {
    const r = await accountingApi.getSaldoSettlementSuggestions({ period_id: props.periodId, as_of: props.asOf, account: props.account })
    items.value = r.items
    truncated.value = r.truncated
    checked.value = new Set(r.items.map(i => i.key))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    items.value = []
  } finally {
    loading.value = false
  }
}

function toggle(key: string) {
  const next = new Set(checked.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  checked.value = next
}

function toggleAll() {
  checked.value = allChecked.value ? new Set() : new Set(items.value.map(i => i.key))
}

/**
 * Pošle skupiny po dávkách (server přijme nejvýš MAX_GROUPS_PER_REQUEST); index odmítnuté
 * skupiny se přepočte na pořadí v `groups`. Null = správce odmítl přebít uzavřené období.
 */
async function sendBatched<G>(
  groups: G[],
  send: (part: G[], force: boolean) => Promise<SaldoAllocationResultLike>,
): Promise<SaldoAllocationResultLike | null> {
  if (groups.length === 0) return { created: [], failed: [] }
  const run = async (force: boolean): Promise<SaldoAllocationResultLike> => {
    const merged: SaldoAllocationResultLike = { created: [], failed: [] }
    for (let start = 0; start < groups.length; start += MAX_GROUPS_PER_REQUEST) {
      const part = await send(groups.slice(start, start + MAX_GROUPS_PER_REQUEST), force)
      merged.created.push(...part.created)
      merged.failed.push(...part.failed.map(f => ({ ...f, index: f.index + start })))
    }
    return merged
  }
  return withPeriodForce(run, auth.isCompanyAdminRole, () => window.confirm(t('accounting.saldo_settle.period_closed_confirm')))
}

async function confirmSelected() {
  const chosen = items.value.filter(i => checked.value.has(i.key))
  if (chosen.length === 0 || saving.value) return
  saving.value = true
  failures.value = []
  try {
    // Rozpad zápisu a čerpání zálohy mají každé svůj endpoint; každý druh se potvrzuje zvlášť.
    const journal = chosen.filter((i): i is SaldoJournalSuggestion => i.type !== 'advance')
    const advance = chosen.filter((i): i is SaldoAdvanceSuggestion => i.type === 'advance')
    const journalResult = await sendBatched(
      journal.map(i => ({
        allocations: i.allocations.map(a => ({ line_id: i.line.line_id, doc_type: a.doc_type, doc_id: a.doc_id, amount: a.amount })),
      })),
      (part, force) => accountingApi.createSaldoAllocations({ groups: part }, force),
    )
    const advanceResult = await sendBatched(
      advance.map(i => ({
        deductions: [{ doc_type: i.advance.doc_type, advance_id: i.advance.doc_id, final_id: i.final.doc_id, amount: i.amount }],
      })),
      (part, force) => accountingApi.createSaldoAdvanceDeductions({ groups: part }, force),
    )
    if (journalResult === null && advanceResult === null) return
    failures.value = [
      ...(journalResult?.failed ?? []).map(f => ({ key: journal[f.index]?.key ?? String(f.index), message: f.message })),
      ...(advanceResult?.failed ?? []).map(f => ({ key: advance[f.index]?.key ?? String(f.index), message: f.message })),
    ]
    const skipped = new Set([
      ...(journalResult === null ? journal.map(i => i.key) : []),
      ...(advanceResult === null ? advance.map(i => i.key) : []),
    ])
    const confirmed = chosen.length - failures.value.length - skipped.size
    if (confirmed > 0) toast.success(t('accounting.saldo_settle.suggestions_saved', { count: confirmed }))
    if (failures.value.length > 0 || skipped.size > 0) {
      if (failures.value.length > 0) toast.error(t('accounting.saldo_settle.suggestions_failed', { count: failures.value.length }))
      const keep = new Set([...failures.value.map(f => f.key), ...skipped])
      items.value = items.value.filter(i => !checked.value.has(i.key) || keep.has(i.key))
      checked.value = new Set()
      if (confirmed > 0) emit('done')
      return
    }
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

function failureOf(key: string): string | undefined {
  return failures.value.find(f => f.key === key)?.message
}

onMounted(load)
</script>

<template>
  <Modal :title="t('accounting.saldo_settle.suggestions_title')" width-class="max-w-5xl" @close="emit('close')">
    <div class="space-y-3" data-testid="saldo-suggestions-dialog">
      <p class="text-sm text-neutral-600">{{ t('accounting.saldo_settle.suggestions_intro') }}</p>
      <p v-if="truncated" class="text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">{{ t('accounting.saldo_settle.suggestions_truncated') }}</p>

      <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" dense accent="success" icon="checkCircle" :title="t('accounting.saldo_settle.suggestions_empty')" />
      <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8">
                <input type="checkbox" class="rounded border-neutral-300" :checked="allChecked" :aria-label="t('accounting.saldo_settle.suggestions_all')" @change="toggleAll" />
              </th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_source') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_doc') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_partner') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="s in items" :key="s.key" class="hover:bg-neutral-50 align-top">
              <td class="px-3 py-2">
                <input type="checkbox" class="rounded border-neutral-300" :checked="checked.has(s.key)"
                  :aria-label="s.type === 'advance'
                    ? t('accounting.saldo_settle.advance_pick_aria', { doc: s.final.doc_no })
                    : t('accounting.saldo_settle.pick_aria', { entry: s.line.document_no || s.line.entry_id })"
                  @change="toggle(s.key)" />
              </td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ s.type === 'advance' ? s.account.code : s.line.account_code }}</td>
              <td class="px-3 py-2 min-w-[14rem]">
                <template v-if="s.type === 'advance'">
                  <span class="block">
                    <span class="whitespace-nowrap">{{ formatDate(s.final.posted_on) }}</span> ·
                    {{ t('accounting.saldo_settle.advance_final_label', { doc: s.final.doc_no }) }}
                  </span>
                </template>
                <span v-else class="block"><span class="whitespace-nowrap">{{ formatDate(s.line.entry_date) }}</span> · {{ [s.line.document_no, s.line.description].filter(Boolean).join(' - ') }}</span>
                <span class="text-[11px] text-neutral-500">{{ t(`accounting.saldo_settle.reason_${s.reason}`) }}</span>
                <span v-if="failureOf(s.key)" class="block text-xs text-danger-600">{{ failureOf(s.key) }}</span>
              </td>
              <td class="px-3 py-2">
                <span v-if="s.type === 'advance'" class="block font-mono whitespace-nowrap">{{ s.advance.doc_no }}</span>
                <template v-else>
                  <span v-for="a in s.allocations" :key="`${a.doc_type}-${a.doc_id}`" class="block font-mono whitespace-nowrap">{{ a.doc_no }}</span>
                </template>
              </td>
              <td class="px-3 py-2">{{ s.partner_name }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(s.type === 'advance' ? s.amount : s.line.free_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span v-if="items.length > 0" class="text-sm mr-auto">
          {{ t('accounting.saldo_settle.suggestions_selected', { count: checked.size }) }}
          <strong class="font-mono">{{ formatMoney(selectedTotal) }}</strong>
        </span>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="button" :class="btnFilled('success')" :disabled="checked.size === 0 || saving" data-testid="saldo-suggestions-submit" @click="confirmSelected">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.saldo_settle.suggestions_confirm') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
