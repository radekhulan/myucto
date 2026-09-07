<script setup lang="ts">
/**
 * Jednorázové opravy DPH mimo běžný chod dokladů — § 43 a § 79/§ 79a ZDPH.
 *
 * Obě evidence jsou na jedné stránce záměrně: potkají firmu nejvýš párkrát za život,
 * takže samostatné položky v menu by jen zabíraly místo a nikdo by je nenašel. Věcně
 * spolu nesouvisí, proto jsou v oddělených záložkách s vlastním vysvětlením.
 *
 * Co musí UI říct nahlas, protože právě tady se chybuje:
 *   § 43 — období opravy je období PŮVODNÍHO plnění, ne doručení opravného dokladu
 *          (to je § 42, a míří opačně). Podává se DODATEČNÉ přiznání.
 *   § 79 — nárok při registraci se uvádí KLADNĚ, snížení při zrušení ZÁPORNĚ;
 *          zásoby vracejí odpočet celý, dlouhodobý majetek jen za zbývající roky lhůty.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  reportsApi,
  type S43Correction,
  type S43RateKind,
  type S79Overview,
} from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()

const tab = ref<'s43' | 's79'>('s43')
const year = ref(new Date().getFullYear())
const yearOptions = useYearOptions('combined', year)

const s43Rows = ref<S43Correction[]>([])
const s79 = ref<S79Overview | null>(null)
const loading = ref(false)
const error = ref('')

const canWrite = computed(() => auth.canWrite('reports.finalize'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [a, b] = await Promise.all([
      reportsApi.s43List(year.value),
      reportsApi.s79List(`${year.value}-01-01`, `${year.value}-12-31`),
    ])
    s43Rows.value = a.rows
    s79.value = b
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

// ── § 43 ─────────────────────────────────────────────────────────────────────
const show43 = ref(false)
const saving = ref(false)
const f43 = ref({
  source_type: 'invoice' as 'invoice' | 'purchase_invoice',
  source_id: 0,
  period_year: new Date().getFullYear(),
  period_month: 1,
  rate_kind: 'basic' as S43RateKind,
  base_delta: 0,
  vat_delta: 0,
  delivered_on: '',
  corrective_doc_number: '',
  reason: '',
})

async function save43() {
  if (!f43.value.source_id || !f43.value.delivered_on || !f43.value.reason.trim()) {
    toast.error(t('reports.vatCorrections.s43.required'))
    return
  }
  saving.value = true
  try {
    await reportsApi.s43Create({
      ...f43.value,
      corrective_doc_number: f43.value.corrective_doc_number.trim() || null,
      reason: f43.value.reason.trim(),
    })
    show43.value = false
    await load()
    toast.success(t('reports.vatCorrections.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// ── § 79 ─────────────────────────────────────────────────────────────────────
const show79 = ref(false)
const f79 = ref({
  kind: 'registration' as 'registration' | 'deregistration',
  label: '',
  acquired_on: '',
  effective_on: '',
  asset_kind: 'inventory' as 'inventory' | 'fixed_asset',
  vat_amount: 0,
  period_years: 5 as number | null,
})

async function save79() {
  if (!f79.value.label.trim() || !f79.value.acquired_on || !f79.value.effective_on) {
    toast.error(t('reports.vatCorrections.s79.required'))
    return
  }
  saving.value = true
  try {
    await reportsApi.s79Create({
      ...f79.value,
      label: f79.value.label.trim(),
      period_years: f79.value.asset_kind === 'fixed_asset' ? f79.value.period_years : null,
    })
    show79.value = false
    await load()
    toast.success(t('reports.vatCorrections.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function remove(kind: 's43' | 's79', id: number) {
  if (!confirm(t('reports.vatCorrections.delete_confirm'))) return
  try {
    await (kind === 's43' ? reportsApi.s43Delete(id) : reportsApi.s79Delete(id))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

// Prefill z query (VH-07): odkaz z bloku Plátcovství DPH v Nastavení předává
// ?tab=s79&kind=registration|deregistration&effective_on=YYYY-MM-DD — otevře
// záložku § 79 s předvyplněným druhem a rozhodným dnem (formulář jen pro toho,
// kdo smí zapisovat).
function applyQueryPrefill(): boolean {
  const kind = String(route.query.kind ?? '')
  if (route.query.tab !== 's79' && kind === '') return false
  tab.value = 's79'
  if (kind !== 'registration' && kind !== 'deregistration') return false
  f79.value.kind = kind
  let yearChanged = false
  const effectiveOn = String(route.query.effective_on ?? '')
  if (/^\d{4}-\d{2}-\d{2}$/.test(effectiveOn)) {
    f79.value.effective_on = effectiveOn
    const y = Number(effectiveOn.slice(0, 4))
    if (Number.isFinite(y) && y !== year.value) {
      year.value = y   // watch(year) načte data sám
      yearChanged = true
    }
  }
  if (canWrite.value) show79.value = true
  return yearChanged
}

watch(year, load)
onMounted(() => {
  if (!applyQueryPrefill()) load()
})
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.vatCorrections.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.vatCorrections.subtitle') }}</p>
      </div>
      <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
    </div>

    <div class="flex gap-1 mb-4 border-b border-neutral-200">
      <button type="button" class="px-4 py-2 text-sm -mb-px border-b-2"
              :class="tab === 's43' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-500'"
              @click="tab = 's43'">{{ t('reports.vatCorrections.s43.tab') }}</button>
      <button type="button" class="px-4 py-2 text-sm -mb-px border-b-2"
              :class="tab === 's79' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-500'"
              @click="tab = 's79'">{{ t('reports.vatCorrections.s79.tab') }}</button>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <!-- ── § 43 ─────────────────────────────────────────────────────────── -->
    <div v-if="tab === 's43'" class="space-y-4">
      <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 text-sm text-neutral-700">
        <p class="font-medium text-primary-800 mb-1">{{ t('reports.vatCorrections.s43.explainer_title') }}</p>
        <p>{{ t('reports.vatCorrections.s43.explainer_body') }}</p>
      </div>

      <button v-if="canWrite" type="button"
              class="h-9 px-4 bg-primary-600 text-white rounded-md text-sm"
              @click="show43 = true">{{ t('reports.vatCorrections.s43.add') }}</button>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <EmptyState v-if="s43Rows.length === 0" accent="neutral" icon="edit" :title="t('reports.vatCorrections.s43.empty')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.period') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.rate') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('reports.vatCorrections.col.base_delta') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('reports.vatCorrections.col.vat_delta') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.delivered_on') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.reason') }}</th>
                <th class="px-2 py-2 w-8"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in s43Rows" :key="r.id">
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                  {{ String(r.period_month).padStart(2, '0') }}/{{ r.period_year }}
                </td>
                <td class="px-2 py-1.5">{{ t(`reports.vatCorrections.rate.${r.rate_kind}`) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(r.base_delta) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                    :class="r.vat_delta < 0 ? 'text-danger-600' : 'text-success-700'">
                  {{ formatMoney(r.vat_delta) }}
                </td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.delivered_on) }}</td>
                <td class="px-2 py-1.5">{{ r.reason }}</td>
                <td class="px-2 py-1.5 text-right">
                  <button v-if="canWrite" type="button" class="text-danger-500 hover:underline"
                          @click="remove('s43', r.id)">×</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── § 79 ─────────────────────────────────────────────────────────── -->
    <div v-else class="space-y-4">
      <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 text-sm text-neutral-700">
        <p class="font-medium text-primary-800 mb-1">{{ t('reports.vatCorrections.s79.explainer_title') }}</p>
        <p>{{ t('reports.vatCorrections.s79.explainer_body') }}</p>
      </div>

      <button v-if="canWrite" type="button"
              class="h-9 px-4 bg-primary-600 text-white rounded-md text-sm"
              @click="show79 = true">{{ t('reports.vatCorrections.s79.add') }}</button>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <EmptyState v-if="!s79 || s79.rows.length === 0" accent="neutral" icon="archive" :title="t('reports.vatCorrections.s79.empty')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.kind') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.label') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.asset_kind') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.effective_on') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('reports.vatCorrections.col.vat_amount') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('reports.vatCorrections.col.line45') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.vatCorrections.col.reason') }}</th>
                <th class="px-2 py-2 w-8"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in s79.rows" :key="r.id" :class="r.applies ? '' : 'text-neutral-400'">
                <td class="px-2 py-1.5">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded"
                        :class="r.kind === 'registration' ? 'bg-success-100 text-success-700' : 'bg-warning-100 text-warning-700'">
                    {{ t(`reports.vatCorrections.kind.${r.kind}`) }}
                  </span>
                </td>
                <td class="px-2 py-1.5">{{ r.label }}</td>
                <td class="px-2 py-1.5">{{ t(`reports.vatCorrections.assetKind.${r.asset_kind}`) }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.effective_on) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(r.vat_amount) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                    :class="r.amount < 0 ? 'text-danger-600' : 'text-success-700'">
                  {{ formatMoney(r.amount) }}
                </td>
                <td class="px-2 py-1.5">{{ r.reason }}</td>
                <td class="px-2 py-1.5 text-right">
                  <button v-if="canWrite" type="button" class="text-danger-500 hover:underline"
                          @click="remove('s79', r.id)">×</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 border-t border-neutral-200">
              <tr>
                <td colspan="5" class="px-2 py-2 font-medium">{{ t('reports.vatCorrections.s79.total') }}</td>
                <td class="px-2 py-2 text-right font-mono font-bold">{{ formatMoney(s79.total) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- Formulář § 43 -->
    <div v-if="show43" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="show43 = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg p-5 space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold">{{ t('reports.vatCorrections.s43.form_title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('reports.vatCorrections.s43.form_hint') }}</p>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.doc_type') }}</label>
            <select v-model="f43.source_type" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="invoice">{{ t('reports.vatCorrections.docType.invoice') }}</option>
              <option value="purchase_invoice">{{ t('reports.vatCorrections.docType.purchase_invoice') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.doc_id') }}</label>
            <input v-model.number="f43.source_id" type="number" min="1"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.period_year') }}</label>
            <input v-model.number="f43.period_year" type="number"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.period_month') }}</label>
            <input v-model.number="f43.period_month" type="number" min="1" max="12"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.rate') }}</label>
            <select v-model="f43.rate_kind" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="basic">{{ t('reports.vatCorrections.rate.basic') }}</option>
              <option value="reduced">{{ t('reports.vatCorrections.rate.reduced') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.delivered_on') }}</label>
            <DateInput v-model="f43.delivered_on"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.base_delta') }}</label>
            <input v-model.number="f43.base_delta" type="number" step="0.01"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.vat_delta') }}</label>
            <input v-model.number="f43.vat_delta" type="number" step="0.01"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          </div>
        </div>
        <div>
          <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.reason') }}</label>
          <textarea v-model="f43.reason" rows="2"
                    class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="h-9 px-4 border border-neutral-300 rounded-md text-sm" @click="show43 = false">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="saving"
                  class="h-9 px-4 bg-primary-600 text-white rounded-md text-sm disabled:opacity-50" @click="save43">
            {{ t('common.save') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Formulář § 79 -->
    <div v-if="show79" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="show79 = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg p-5 space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold">{{ t('reports.vatCorrections.s79.form_title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('reports.vatCorrections.s79.form_hint') }}</p>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.kind') }}</label>
            <select v-model="f79.kind" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="registration">{{ t('reports.vatCorrections.kind.registration') }}</option>
              <option value="deregistration">{{ t('reports.vatCorrections.kind.deregistration') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.asset_kind') }}</label>
            <select v-model="f79.asset_kind" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="inventory">{{ t('reports.vatCorrections.assetKind.inventory') }}</option>
              <option value="fixed_asset">{{ t('reports.vatCorrections.assetKind.fixed_asset') }}</option>
            </select>
          </div>
          <div class="col-span-2">
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.label') }}</label>
            <input v-model="f79.label" type="text"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.acquired_on') }}</label>
            <DateInput v-model="f79.acquired_on"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.effective_on') }}</label>
            <DateInput v-model="f79.effective_on"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.vat_amount') }}</label>
            <input v-model.number="f79.vat_amount" type="number" min="0" step="0.01"
                   class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          </div>
          <div v-if="f79.asset_kind === 'fixed_asset'">
            <label class="block text-sm mb-1">{{ t('reports.vatCorrections.col.period_years') }}</label>
            <select v-model.number="f79.period_years" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option :value="5">5</option>
              <option :value="10">10</option>
            </select>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="h-9 px-4 border border-neutral-300 rounded-md text-sm" @click="show79 = false">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="saving"
                  class="h-9 px-4 bg-primary-600 text-white rounded-md text-sm disabled:opacity-50" @click="save79">
            {{ t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
