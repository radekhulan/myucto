<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockTake, type Warehouse } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const id = computed<number | null>(() => (route.params.id ? Number(route.params.id) : null))

// ── Seznam (bez :id) ────────────────────────────────────────────────────
const takes = ref<StockTake[]>([])
const warehouses = ref<Warehouse[]>([])
const listLoading = ref(false)

async function loadList() {
  listLoading.value = true
  try { takes.value = await stockApi.listTakes() } catch { takes.value = [] } finally { listLoading.value = false }
}

const createOpen = ref(false)

/**
 * Escape zavírá dialog nové inventury. Ten je psaný ručně (ne sdílený Modal.vue,
 * který Escape umí), takže z něj šlo ven jen křížkem nebo Zrušit.
 */
function onCreateEscape(e: KeyboardEvent) {
  if (e.key === 'Escape' && createOpen.value) {
    e.stopPropagation()
    createOpen.value = false
  }
}
onMounted(() => document.addEventListener('keydown', onCreateEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', onCreateEscape))
const createForm = reactive({
  warehouse_id: null as number | null,
  take_date: appIsoDate(),
  note: '',
  counting_method: 'physical_count',
  responsible_count_name: '',
  responsible_inventory_name: '',
})
const creating = ref(false)
async function createTake() {
  if (!createForm.warehouse_id) return
  creating.value = true
  try {
    const take = await stockApi.createTake({
      warehouse_id: createForm.warehouse_id,
      take_date: createForm.take_date,
      note: createForm.note || undefined,
      counting_method: createForm.counting_method,
      responsible_count_name: createForm.responsible_count_name,
      responsible_inventory_name: createForm.responsible_inventory_name,
    })
    createOpen.value = false
    router.push(`/stock/takes/${take.id}`)
  } catch (e: any) {
    const err = e?.response?.data?.error
    const code = String(err?.code ?? '')
    const existingId = err?.items?.stock_take_id
    if (code.endsWith('stock_take_in_progress')) {
      toast.warning(t('stock.takes.already_in_progress'))
      if (existingId) { createOpen.value = false; router.push(`/stock/takes/${existingId}`) }
    } else if (code.endsWith('stock_take_exists')) {
      // Inventura pro sklad+datum už existuje → otevři ji místo syrové chyby.
      toast.warning(err?.message || t('common.error'))
      if (existingId) { createOpen.value = false; router.push(`/stock/takes/${existingId}`) }
    } else {
      toast.error(err?.message || t('common.error'))
    }
  } finally {
    creating.value = false
  }
}

// ── Wizard (s :id) ──────────────────────────────────────────────────────
const take = ref<StockTake | null>(null)
const wizLoading = ref(false)
const starting = ref(false)
const closing = ref(false)
const savingLines = ref(false)

const step = computed<'setup' | 'counting' | 'recap'>(() => {
  if (!take.value) return 'setup'
  if (take.value.status === 'draft') return 'setup'
  if (take.value.status === 'counting') return 'counting'
  return 'recap'
})

async function loadTake() {
  if (!id.value) return
  wizLoading.value = true
  try { take.value = await stockApi.getTake(id.value) } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { wizLoading.value = false }
}

async function startCounting() {
  if (!id.value) return
  starting.value = true
  try {
    take.value = await stockApi.startTake(id.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { starting.value = false }
}

function takeAllExpected() {
  if (!take.value?.lines) return
  for (const l of take.value.lines) l.counted_qty = l.expected_qty
}

async function saveProgress() {
  if (!id.value || !take.value?.lines) return
  savingLines.value = true
  try {
    take.value = await stockApi.updateTake(id.value, take.value.lines.map(l => ({ id: l.id, counted_qty: l.counted_qty, surplus_unit_cost: l.surplus_unit_cost })))
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { savingLines.value = false }
}

const diffLines = computed(() => (take.value?.lines ?? []).filter(l => l.diff_qty != null && Number(l.diff_qty) !== 0))

async function closeTake() {
  if (!id.value) return
  if (!confirm(t('stock.takes.close_confirm'))) return
  closing.value = true
  try {
    // M2: nejdřív ulož napočítané stavy (jinak by se inventura uzavřela jako by nic
    // nenapočítala → žádné rozdílové doklady). Až pak uzavři.
    if (take.value?.status === 'counting' && take.value.lines) {
      await stockApi.updateTake(id.value, take.value.lines.map(l => ({ id: l.id, counted_qty: l.counted_qty, surplus_unit_cost: l.surplus_unit_cost })))
    }
    const res = await stockApi.closeTake(id.value)
    take.value = res
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { closing.value = false }
}

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  if (id.value) await loadTake()
  else await loadList()
})

// TakeWizard slouží pro /stock/takes i /stock/takes/:id — Vue Router recykluje stejnou
// instanci, takže onMounted se při router.push (po vytvoření / klik na řádek / zpět na seznam)
// znovu nespustí. Bez tohoto watche zůstane detail prázdný do ručního refreshe.
watch(id, async (newId, oldId) => {
  if (newId === oldId) return
  if (newId) {
    await loadTake()
  } else {
    take.value = null
    await loadList()
  }
})

function warehouseName(wid: number): string {
  return warehouses.value.find(w => w.id === wid)?.name ?? `#${wid}`
}
const STATUS_BADGE: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  counting: 'bg-warning-50 text-warning-600',
  closed: 'bg-success-50 text-success-600',
}
</script>

<template>
  <div>
    <!-- Seznam inventur -->
    <template v-if="!id">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h1 class="text-2xl font-semibold">{{ t('stock.takes.title') }}</h1>
          <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.takes.subtitle') }}</p>
        </div>
        <button v-if="auth.canWrite('stock.take')" @click="createOpen = true" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('stock.takes.new') }}
        </button>
      </div>

      <div v-if="listLoading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="takes.length === 0" boxed icon="clipboardCheck"
        :title="t('stock.takes.empty_title')"
        :message="t('stock.takes.empty_hint')"
        :cta="auth.canWrite('stock.take') ? t('stock.takes.new') : undefined"
        @action="createOpen = true" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('stock.takes.field_date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('stock.documents.col_warehouse') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('stock.documents.col_status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tk in takes" :key="tk.id" class="cursor-pointer hover:bg-neutral-50" @click="router.push(`/stock/takes/${tk.id}`)">
              <td class="px-3 py-2 whitespace-nowrap">
                <RouterLink class="row-link" :to="`/stock/takes/${tk.id}`" @click.stop @auxclick.stop>{{ formatDate(tk.take_date) }}</RouterLink>
              </td>
              <td class="px-3 py-2">{{ warehouseName(tk.warehouse_id) }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGE[tk.status]">{{ t(`stock.take_status.${tk.status}`) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Modal: nová inventura -->
      <div v-if="createOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="createOpen = false">
        <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
          <h3 class="text-lg font-semibold mb-3">{{ t('stock.takes.new') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_warehouse') }}</label>
              <select v-model="createForm.warehouse_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">—</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_date') }}</label>
              <DateInput v-model="createForm.take_date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_note') }}</label>
              <textarea v-model="createForm.note" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_counting_method') }}</label>
              <select v-model="createForm.counting_method" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="physical_count">{{ t('stock.takes.method_physical_count') }}</option>
                <option value="measurement">{{ t('stock.takes.method_measurement') }}</option>
                <option value="weighing">{{ t('stock.takes.method_weighing') }}</option>
                <option value="other">{{ t('stock.takes.method_other') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_responsible_count') }}</label>
              <input v-model="createForm.responsible_count_name" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.takes.field_responsible_inventory') }}</label>
              <input v-model="createForm.responsible_inventory_name" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="flex justify-end gap-2 mt-4">
            <button @click="createOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="createTake" :disabled="creating || !createForm.warehouse_id || !createForm.responsible_count_name || !createForm.responsible_inventory_name" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ creating ? t('common.saving') : t('common.create') }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Wizard -->
    <template v-else>
      <div v-if="wizLoading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
      <template v-else-if="take">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div>
            <h1 class="text-2xl font-semibold">{{ t('stock.takes.title') }} — {{ warehouseName(take.warehouse_id) }}</h1>
            <p class="text-sm text-neutral-500 mt-0.5">{{ formatDate(take.take_date) }}</p>
          </div>
          <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGE[take.status]">{{ t(`stock.take_status.${take.status}`) }}</span>
        </div>

        <!-- Stepper -->
        <div class="flex items-center gap-2 mb-5 text-sm">
          <span class="px-3 py-1.5 rounded-md font-medium" :class="step === 'setup' ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-500'">1. {{ t('stock.takes.step_setup') }}</span>
          <span class="text-neutral-300">→</span>
          <span class="px-3 py-1.5 rounded-md font-medium" :class="step === 'counting' ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-500'">2. {{ t('stock.takes.step_counting') }}</span>
          <span class="text-neutral-300">→</span>
          <span class="px-3 py-1.5 rounded-md font-medium" :class="step === 'recap' ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-500'">3. {{ t('stock.takes.step_recap') }}</span>
        </div>

        <!-- Krok 1: založení -->
        <div v-if="step === 'setup'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <p class="text-sm text-neutral-600 mb-4">{{ t('stock.takes.setup_hint') }}</p>
          <button v-if="auth.canWrite('stock.take')" @click="startCounting" :disabled="starting" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ starting ? t('common.saving') : t('stock.takes.start') }}
          </button>
        </div>

        <!-- Krok 2: sčítání -->
        <div v-else-if="step === 'counting'">
          <div class="mb-4 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm">
            {{ t('stock.takes.counting_in_progress', { date: formatDate(take.take_date) }) }}
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.takes.step_counting') }}</h3>
              <button v-if="auth.canWrite('stock.take')" @click="takeAllExpected" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                {{ t('stock.takes.take_all_expected') }}
              </button>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">{{ t('stock.takes.col_item') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('stock.takes.col_expected') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('stock.takes.col_counted') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('stock.takes.col_diff') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('stock.takes.col_surplus_cost') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="l in take.lines" :key="l.id">
                    <td class="px-3 py-2">
                      <span class="font-mono text-xs text-neutral-500">{{ l.item_sku }}</span> {{ l.item_name }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">{{ l.expected_qty }}</td>
                    <td class="px-3 py-2 text-right">
                      <input v-if="auth.canWrite('stock.take')" v-model="l.counted_qty" type="number" step="0.001" min="0"
                        class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
                    </td>
                    <td class="px-3 py-2 text-right font-mono"
                      :class="l.counted_qty == null ? 'text-neutral-300' : Number(l.counted_qty) - Number(l.expected_qty) !== 0 ? 'text-warning-600 font-semibold' : 'text-success-600'">
                      {{ l.counted_qty != null ? (Number(l.counted_qty) - Number(l.expected_qty)) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                      <input v-if="auth.canWrite('stock.take') && l.counted_qty != null && Number(l.counted_qty) > Number(l.expected_qty)" v-model="l.surplus_unit_cost" type="number" min="0.000001" step="0.000001" class="w-32 h-9 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
                      <span v-else class="text-neutral-300">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="auth.canWrite('stock.take')" class="px-5 py-3 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
              <button @click="saveProgress" :disabled="savingLines" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                {{ savingLines ? t('common.saving') : t('stock.takes.save_progress') }}
              </button>
              <button @click="closeTake" :disabled="closing" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
                {{ closing ? t('common.saving') : t('stock.takes.close') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Krok 3: rekapitulace -->
        <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('stock.takes.recap_title') }}</h3>
          <p class="text-xs text-neutral-500 mb-4">{{ t('stock.takes.recap_hint') }}</p>
          <div v-if="diffLines.length === 0" class="text-sm text-neutral-500 py-4">{{ t('stock.takes.no_diffs') }}</div>
          <table v-else class="w-full text-sm mb-4">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left font-medium py-1">{{ t('stock.takes.col_item') }}</th>
                <th class="text-right font-medium py-1">{{ t('stock.takes.col_expected') }}</th>
                <th class="text-right font-medium py-1">{{ t('stock.takes.col_counted') }}</th>
                <th class="text-right font-medium py-1">{{ t('stock.takes.col_diff') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="l in diffLines" :key="l.id">
                <td class="py-1"><span class="font-mono text-xs text-neutral-500">{{ l.item_sku }}</span> {{ l.item_name }}</td>
                <td class="py-1 text-right font-mono">{{ l.expected_qty }}</td>
                <td class="py-1 text-right font-mono">{{ l.counted_qty }}</td>
                <td class="py-1 text-right font-mono" :class="Number(l.diff_qty) < 0 ? 'text-danger-500' : 'text-success-600'">{{ l.diff_qty }}</td>
              </tr>
            </tbody>
          </table>
          <div v-if="take.status === 'closed'" class="flex flex-wrap gap-3 text-sm">
            <a :href="stockApi.takePdfUrl(take.id)" target="_blank" class="text-primary-600 hover:text-primary-700">{{ t('stock.takes.pdf') }} →</a>
            <RouterLink v-if="take.receipt_document_id" :to="`/stock/documents/${take.receipt_document_id}`" class="text-primary-600 hover:text-primary-700">
              {{ t('stock.takes.closed_receipt') }} →
            </RouterLink>
            <RouterLink v-if="take.issue_document_id" :to="`/stock/documents/${take.issue_document_id}`" class="text-primary-600 hover:text-primary-700">
              {{ t('stock.takes.closed_issue') }} →
            </RouterLink>
          </div>
          <button v-else-if="auth.canWrite('stock.take')" @click="closeTake" :disabled="closing" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ closing ? t('common.saving') : t('stock.takes.close') }}
          </button>
        </div>
      </template>
    </template>
  </div>
</template>
