<script setup lang="ts">
/**
 * § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (věřitel).
 *
 * Kandidáti jsou jen PRACOVNÍ SEZNAM neuhrazených vydaných dokladů po splatnosti —
 * nárok na opravu z nich neplyne: právní důvod (insolvence, exekuce, smrt, likvidace,
 * malá pohledávka § 46/1/f) a doručení opravného daňového dokladu dlužníkovi (§ 46f)
 * dokládá účetní a zadává je při evidenci opravy. Obnova po úhradě (§ 46e) už
 * automatická je — plyne výhradně z úhrad, které systém eviduje.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { reportsApi, type S46Row, type S46LegalGround, type S46RestorationsPreview } from '@/api/reports'
import { btnFilledSmWrap } from '@/components/ui/buttonStyles'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const LEGAL_GROUNDS: S46LegalGround[] = ['insolvency', 'execution', 'death', 'liquidation', 'small_receivable']

const asOf = ref(appIsoDate())
const candidates = ref<S46Row[]>([])
const loading = ref(false)
const error = ref('')

// Minimální doba po splatnosti — BE vrací všechno po splatnosti k danému dni,
// ale faktura 3 dny po splatnosti není kandidát na § 46 (malá pohledávka
// § 46/1/f vyžaduje 6 měsíců a insolvenční tituly bývají stejně staré).
// Default 180 dní; doklady s už evidovanou opravou se ukazují vždy.
const MIN_OVERDUE_OPTIONS = [30, 90, 180, 365]
const minOverdueDays = ref(180)

function overdueDays(row: S46Row): number {
  const due = new Date(row.due_date + 'T00:00:00')
  const ref_ = new Date(asOf.value + 'T00:00:00')
  return Math.floor((ref_.getTime() - due.getTime()) / 86400000)
}

const visibleCandidates = computed(() =>
  candidates.value.filter(r => r.net_corrected !== 0 || overdueDays(r) >= minOverdueDays.value)
)

const now = new Date()
const restYear = ref(now.getFullYear())
const restMonth = ref(now.getMonth() + 1)
const restorations = ref<S46RestorationsPreview | null>(null)
const loadingRest = ref(false)
const recording = ref(false)

const canFinalize = computed(() => auth.canWrite('reports.finalize'))

async function loadCandidates() {
  loading.value = true
  error.value = ''
  try {
    candidates.value = (await reportsApi.s46Candidates(asOf.value)).rows
  } catch (e) {
    error.value = apiErrorMessage(e)
    candidates.value = []
  } finally {
    loading.value = false
  }
}

async function loadRestorations() {
  loadingRest.value = true
  try {
    restorations.value = await reportsApi.s46Restorations(restYear.value, restMonth.value)
  } catch (e) {
    toast.error(apiErrorMessage(e))
    restorations.value = null
  } finally {
    loadingRest.value = false
  }
}

async function recordRestorations() {
  if (!restorations.value || restorations.value.rows.length === 0) return
  if (!confirm(t('reports.s46.restore_confirm', { count: restorations.value.rows.length }))) return
  recording.value = true
  try {
    const r = await reportsApi.s46RestorationsRecord(restYear.value, restMonth.value)
    toast.success(t('reports.s46.restore_done', { count: r.recorded ?? 0 }))
    await Promise.all([loadRestorations(), loadCandidates()])
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    recording.value = false
  }
}

// ── Evidence opravy k dokladu ────────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const selected = ref<S46Row | null>(null)
const form = ref({
  legal_ground: 'insolvency' as S46LegalGround,
  delivered_on: appIsoDate(),
  corrective_doc_number: '',
  note: '',
})

function openForm(row: S46Row) {
  selected.value = row
  form.value = {
    legal_ground: row.legal_ground ?? 'insolvency',
    delivered_on: appIsoDate(),
    corrective_doc_number: '',
    note: '',
  }
  showForm.value = true
}

async function saveCorrection() {
  if (!selected.value) return
  saving.value = true
  try {
    const r = await reportsApi.s46Correction({
      invoice_id: selected.value.invoice_id,
      legal_ground: form.value.legal_ground,
      delivered_on: form.value.delivered_on,
      corrective_doc_number: form.value.corrective_doc_number.trim() || null,
      note: form.value.note.trim() || null,
    })
    showForm.value = false
    toast.success(t('reports.s46.correction_done', {
      amount: fmtMoney(Math.abs(r.vat_amount)), year: r.period.year, month: r.period.month,
    }))
    await loadCandidates()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('reports'), disabled: loading.value,
    loading: loading.value, run: loadCandidates,
  },
])

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtPct(ratio: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 0, maximumFractionDigits: 1,
  }).format((Number(ratio) || 0) * 100) + ' %'
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const months = Array.from({ length: 12 }, (_, i) => i + 1)
const restYears = computed(() => {
  const y = now.getFullYear()
  return [y - 2, y - 1, y, y + 1].filter(v => v >= 2020)
})

watch([restYear, restMonth], loadRestorations)
watch(asOf, loadCandidates)
onMounted(() => { loadCandidates(); loadRestorations() })
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.s46.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.s46.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-3 flex-wrap">
        <label class="flex items-center gap-2 text-sm text-neutral-600">
          {{ t('reports.s46.min_overdue') }}
          <select v-model.number="minOverdueDays" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="d in MIN_OVERDUE_OPTIONS" :key="d" :value="d">{{ t('reports.s46.min_overdue_days', { days: d }) }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-600">
          {{ t('reports.s46.as_of') }}
          <DateInput v-model="asOf" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        </label>
      </div>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('reports.s46.explainer_title') }}</p>
      <p>{{ t('reports.s46.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <!-- Kandidáti -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
        <h2 class="text-sm font-semibold">{{ t('reports.s46.candidates_title') }}</h2>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('reports.s46.candidates_hint') }}</p>
      </div>
      <div v-if="loading" class="p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
      <EmptyState v-else-if="visibleCandidates.length === 0" accent="neutral" icon="coin"
        :title="candidates.length > 0
          ? t('reports.s46.no_candidates_filtered', { count: candidates.length, days: minOverdueDays })
          : t('reports.s46.no_candidates')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-2 py-2 text-left font-medium">{{ t('reports.s46.col.doc') }}</th>
              <th class="px-2 py-2 text-left font-medium">{{ t('reports.s46.col.client') }}</th>
              <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.s46.col.due_date') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.overdue') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.total') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.output_vat') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.unpaid') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.recorded') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.delta') }}</th>
              <th class="px-2 py-2 text-left font-medium">{{ t('reports.s46.col.ground') }}</th>
              <th class="px-2 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in visibleCandidates" :key="row.invoice_id">
              <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                <RouterLink :to="{ name: 'invoice-detail', params: { id: row.invoice_id } }"
                            class="text-primary-600 hover:underline">{{ row.varsymbol }}</RouterLink>
              </td>
              <td class="px-2 py-1.5">
                {{ row.client_name }}
                <span v-if="row.client_dic" class="text-neutral-400 ml-1">{{ row.client_dic }}</span>
              </td>
              <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(row.due_date) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap"
                  :class="overdueDays(row) >= 180 ? 'text-danger-600 font-semibold' : 'text-neutral-600'">
                {{ t('reports.s46.overdue_days', { days: overdueDays(row) }) }}
              </td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.total_with_vat) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.output_vat) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtPct(row.unpaid_ratio) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap text-neutral-500">{{ fmtMoney(row.net_corrected) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                  :class="row.delta > 0 ? 'text-danger-600' : row.delta < 0 ? 'text-success-600' : 'text-neutral-400'">
                {{ fmtMoney(row.delta) }}
              </td>
              <td class="px-2 py-1.5">
                <span v-if="row.legal_ground" class="inline-block text-[10px] font-bold px-1.5 py-px rounded bg-warning-100 text-warning-700">
                  {{ t(`reports.s46.grounds.${row.legal_ground}`) }}
                </span>
                <span v-else class="text-neutral-300">—</span>
              </td>
              <td class="px-2 py-1.5 text-right">
                <button v-if="canFinalize && row.delta > 0" type="button"
                        :class="btnFilledSmWrap('warning')"
                        @click="openForm(row)">
                  {{ t('reports.s46.record_correction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Obnovy § 46e -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-sm font-semibold">{{ t('reports.s46.restorations_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('reports.s46.restorations_hint') }}</p>
        </div>
        <div class="flex items-center gap-2">
          <select v-model.number="restMonth" class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="m in months" :key="m" :value="m">{{ m }}</option>
          </select>
          <select v-model.number="restYear" class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in restYears" :key="y" :value="y">{{ y }}</option>
          </select>
          <button v-if="canFinalize" type="button"
                  :disabled="recording || loadingRest || !restorations || restorations.rows.length === 0"
                  class="cursor-pointer h-8 px-3 text-xs bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md disabled:opacity-50"
                  @click="recordRestorations">
            {{ recording ? t('common.saving') : t('reports.s46.record_restorations') }}
          </button>
        </div>
      </div>
      <div v-if="loadingRest" class="p-6 text-center text-neutral-400">{{ t('common.loading') }}…</div>
      <EmptyState v-else-if="!restorations || restorations.rows.length === 0" dense accent="neutral" icon="cycle"
        :title="t('reports.s46.no_restorations')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-2 py-2 text-left font-medium">{{ t('reports.s46.col.doc') }}</th>
              <th class="px-2 py-2 text-left font-medium">{{ t('reports.s46.col.client') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.recorded') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.target') }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s46.col.restore_amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in restorations.rows" :key="row.invoice_id">
              <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                <RouterLink :to="{ name: 'invoice-detail', params: { id: row.invoice_id } }"
                            class="text-primary-600 hover:underline">{{ row.varsymbol }}</RouterLink>
              </td>
              <td class="px-2 py-1.5">{{ row.client_name }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.net_corrected) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.target) }}</td>
              <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold text-success-600">
                {{ fmtMoney(-row.delta) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-neutral-50 border-t border-neutral-200">
            <tr>
              <td colspan="4" class="px-2 py-2 font-medium">{{ t('reports.s46.restore_total') }}</td>
              <td class="px-2 py-2 text-right font-mono font-bold">{{ fmtMoney(restorations.total) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Formulář opravy -->
    <div v-if="showForm && selected" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
         @click.self="showForm = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-5 space-y-3">
        <h3 class="text-lg font-semibold">{{ t('reports.s46.form_title') }}</h3>
        <p class="text-xs text-neutral-500">
          {{ selected.varsymbol }} · {{ selected.client_name }} ·
          {{ t('reports.s46.col.delta') }}: <strong class="font-mono">{{ fmtMoney(selected.delta) }}</strong>
        </p>

        <div>
          <label class="block text-sm mb-1">{{ t('reports.s46.col.ground') }}</label>
          <select v-model="form.legal_ground" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="g in LEGAL_GROUNDS" :key="g" :value="g">{{ t(`reports.s46.grounds.${g}`) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.s46.delivered_on') }}</label>
          <DateInput v-model="form.delivered_on"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          <p class="text-[11px] text-neutral-400 mt-1">{{ t('reports.s46.delivered_on_hint') }}</p>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.s46.corrective_doc') }}</label>
          <input v-model="form.corrective_doc_number" type="text" maxlength="60"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.s46.note') }}</label>
          <textarea v-model="form.note" rows="2" maxlength="255"
                    class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm"
                  @click="showForm = false">{{ t('common.cancel') }}</button>
          <button type="button" :disabled="saving"
                  class="cursor-pointer h-9 px-4 bg-warning-500 hover:bg-warning-600 text-white rounded-md text-sm disabled:opacity-50"
                  @click="saveCorrection">{{ saving ? t('common.saving') : t('reports.s46.record_correction') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
