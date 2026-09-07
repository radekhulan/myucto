<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { reportsApi, type ShvVariant } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'
import { downloadApiFile } from '@/utils/downloadFile'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const periodOverride = ref<'monthly' | 'quarterly' | ''>('')
const effectivePeriod = computed<'monthly' | 'quarterly'>(() => periodOverride.value || 'monthly')
const currentQuarter = computed(() => Math.ceil(month.value / 3))

function setQuarter(q: number) {
  month.value = q * 3
}

// Typ podání: řádné, nebo následné (§ 102 odst. 6) — opravné řádky se stornem proti
// naposledy podanému stavu ve VIES.
const variant = ref<ShvVariant>('radne')
const dZjist = ref('')
const isFollowUp = computed(() => variant.value === 'nasledne')

const preview = ref<Awaited<ReturnType<typeof reportsApi.shvPreview>> | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.shvPreview(
      year.value, month.value, effectivePeriod.value,
      variant.value, isFollowUp.value ? dZjist.value : undefined,
    )
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function downloadXml() {
  try {
    await downloadApiFile(reportsApi.shvDownloadUrl(
      year.value, month.value, effectivePeriod.value,
      variant.value, isFollowUp.value ? dZjist.value : undefined,
    ))
    await router.push('/reports/submissions')
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

const daysToDeadline = computed(() => {
  if (!preview.value?.summary.submission_deadline) return null
  const d = new Date(preview.value.summary.submission_deadline)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.ceil((d.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
})

// Popisky typů plnění (k_pln_eu) dle DPHSHV XSD — pořadí MUSÍ sedět s backendem
// (SouhrnneHlaseniBuilder::VAT_CODE_TO_SH_TYPE): 0=zboží, 1=přemístění majetku,
// 2=třístranný obchod, 3=služby. Dřív byly 1/2/3 posunuté (issue #238).
function shTypeLabel(code: string): string {
  const key = `reports.shv.sh_type.${code}`
  const label = t(key)
  return label === key ? code : label
}

watch([year, month, effectivePeriod, variant, dZjist], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-5xl">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.shv.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.shv.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle (SH: kvartálně jen pro čistě servisní dodávky) -->
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodOverride = 'monthly'"
            :class="effectivePeriod === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer px-3 h-9">{{ t('reports.dph.monthly') }}</button>
          <button type="button" @click="periodOverride = 'quarterly'"
            :class="effectivePeriod === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer px-3 h-9 border-l border-neutral-300">{{ t('reports.dph.quarterly') }}</button>
        </div>
        <!-- Quarter selector (quarterly) nebo month selector (monthly) -->
        <template v-if="effectivePeriod === 'quarterly'">
          <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
          <select :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
            class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
          </select>
        </template>
        <template v-else>
          <select v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
          </select>
          <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </template>
        <button v-if="auth.canRead('reports.export')" type="button" @click="downloadXml" :disabled="loading || !preview"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('reports.shv.download_xml') }}
        </button>
      </div>
    </div>

    <!-- Typ podání (řádné / následné) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center gap-3">
      <label class="text-sm font-medium text-neutral-700">{{ t('reports.shv.variant.label') }}</label>
      <select v-model="variant" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="radne">{{ t('reports.shv.variant.radne') }}</option>
        <option value="nasledne">{{ t('reports.shv.variant.nasledne') }}</option>
      </select>
      <template v-if="isFollowUp">
        <label class="text-sm text-neutral-600">{{ t('reports.shv.variant.d_zjist') }}</label>
        <DateInput v-model="dZjist"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
      </template>
      <span class="text-xs text-neutral-500">{{ t('reports.shv.variant.hint') }}</span>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.shv.rows_count') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">{{ preview.summary.rows_count }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.shv.rows_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.shv.total_amount') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_amount, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.shv.total_hint') }}</div>
        </div>
        <div v-if="preview.summary.submission_deadline" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
          <div class="text-xl font-bold font-mono"
            :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-900'">
            {{ preview.summary.submission_deadline }}
          </div>
          <div class="text-xs mt-1"
            :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-500'">
            <template v-if="daysToDeadline !== null && daysToDeadline >= 0">{{ t('reports.dph.deadline_in', { n: daysToDeadline }) }}</template>
            <template v-else-if="daysToDeadline !== null">{{ t('reports.dph.deadline_passed', { n: Math.abs(daysToDeadline) }) }}</template>
          </div>
        </div>
      </div>

      <!-- Rows table -->
      <div v-if="preview.summary.rows.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.shv.rows_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.shv.country') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.shv.vat_id') }} / {{ t('reports.shv.counterparty') }}</th>
              <th class="text-center px-3 py-2 w-16">{{ t('reports.shv.code') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.shv.type') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.shv.count') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.shv.amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in preview.summary.rows" :key="r.country_iso2 + r.vat_id + r.sh_type" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-xs font-medium">{{ r.country_iso2 }}</td>
              <td class="px-3 py-2.5">
                <div class="font-mono text-xs">{{ r.vat_id }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">{{ r.counterparty_name }}</div>
              </td>
              <td class="px-3 py-2.5 text-center font-mono text-xs">{{ r.sh_type }}</td>
              <td class="px-3 py-2.5 text-xs text-neutral-700">{{ shTypeLabel(r.sh_type) }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ r.count }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(r.amount, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <EmptyState v-else boxed accent="neutral" icon="box" :title="t('reports.shv.no_data')" />

      <!-- Tip -->
      <div class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ t('reports.shv.note') }}
        <span v-if="effectivePeriod === 'quarterly'" class="block mt-1 text-warning-700">{{ t('reports.shv.note_quarterly_goods') }}</span>
      </div>
    </div>
  </div>
</template>
