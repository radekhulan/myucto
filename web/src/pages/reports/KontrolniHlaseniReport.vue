<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { reportsApi, type DphSettings, type KhVariant } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { downloadApiFile } from '@/utils/downloadFile'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const settings = ref<DphSettings | null>(null)
const periodOverride = ref<'monthly' | 'quarterly' | ''>('')

// PO musí vždy měsíčně (§ 101e/1); FO může kvartálně (§ 101e/2)
const isLegalEntity = computed(() => settings.value?.taxpayer_type === 'po')
const effectivePeriod = computed<'monthly' | 'quarterly'>(() => {
  if (isLegalEntity.value) return 'monthly'
  if (periodOverride.value) return periodOverride.value
  return (settings.value?.vat_period as 'monthly' | 'quarterly') || 'monthly'
})
const currentQuarter = computed(() => Math.ceil(month.value / 3))

function setQuarter(q: number) {
  month.value = q * 3
}

const preview = ref<Awaited<ReturnType<typeof reportsApi.khPreview>> | null>(null)
const loading = ref(false)
const error = ref('')

type KhSectionRow = {
  code: string
  labelKey: string
  count: number
  aggregated: boolean
}

const sectionARows = computed<KhSectionRow[]>(() => preview.value ? [
  { code: 'A.1', labelKey: 'reports.kh.a1_label', count: preview.value.summary.a1_count, aggregated: false },
  { code: 'A.2', labelKey: 'reports.kh.a2_label', count: preview.value.summary.a2_count, aggregated: false },
  { code: 'A.4', labelKey: 'reports.kh.a4_label', count: preview.value.summary.a4_count, aggregated: false },
  { code: 'A.5', labelKey: 'reports.kh.a5_label', count: preview.value.summary.a5_count_aggregated, aggregated: true },
] : [])

const sectionBRows = computed<KhSectionRow[]>(() => preview.value ? [
  { code: 'B.1', labelKey: 'reports.kh.b1_label', count: preview.value.summary.b1_count, aggregated: false },
  { code: 'B.2', labelKey: 'reports.kh.b2_label', count: preview.value.summary.b2_count, aggregated: false },
  { code: 'B.3', labelKey: 'reports.kh.b3_label', count: preview.value.summary.b3_count_aggregated, aggregated: true },
] : [])

// C7' — typ podání. Následné (N/E) přijímá datum zjištění a č.j. výzvy správce daně.
const variant = ref<KhVariant>('radne')
const dZjist = ref('')
const cJedVyzvy = ref('')
const isFollowUp = computed(() => variant.value === 'nasledne' || variant.value === 'nasledne_opravne')
// Rychlá odpověď na výzvu (§ 101g) — datum zjištění nemá, č.j. výzvy je povinné.
const isVyzvaOdpoved = computed(() => variant.value === 'vyzva_nulove' || variant.value === 'vyzva_potvrzeni')
const needsVyzvaRef = computed(() => isFollowUp.value || isVyzvaOdpoved.value)

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.khPreview(
      year.value, month.value, effectivePeriod.value,
      variant.value,
      isFollowUp.value ? dZjist.value : undefined,
      needsVyzvaRef.value ? cJedVyzvy.value : undefined,
    )
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function downloadXml() {
  try {
    await downloadApiFile(reportsApi.khDownloadUrl(
      year.value, month.value, effectivePeriod.value,
      variant.value,
      isFollowUp.value ? dZjist.value : undefined,
      needsVyzvaRef.value ? cJedVyzvy.value : undefined,
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

watch([year, month, effectivePeriod, variant, dZjist, cJedVyzvy], loadPreview)
onMounted(async () => {
  try { settings.value = await reportsApi.dphSettings() } catch {}
  loadPreview()
})
</script>

<template>
  <div class="max-w-5xl">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.kh.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.kh.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle — pouze pro FO (PO musí vždy měsíčně) -->
        <div v-if="!isLegalEntity" class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
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
          {{ t('reports.kh.download_xml') }}
        </button>
      </div>
    </div>

    <!-- Typ podání (C7' — řádné / opravné / následné) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center gap-3">
      <label class="text-sm font-medium text-neutral-700">{{ t('reports.kh.variant.label') }}</label>
      <select v-model="variant" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="radne">{{ t('reports.kh.variant.radne') }}</option>
        <option value="opravne">{{ t('reports.kh.variant.opravne') }}</option>
        <option value="nasledne">{{ t('reports.kh.variant.nasledne') }}</option>
        <option value="nasledne_opravne">{{ t('reports.kh.variant.nasledne_opravne') }}</option>
        <option value="vyzva_nulove">{{ t('reports.kh.variant.vyzva_nulove') }}</option>
        <option value="vyzva_potvrzeni">{{ t('reports.kh.variant.vyzva_potvrzeni') }}</option>
      </select>
      <template v-if="isFollowUp">
        <label class="text-sm text-neutral-600">{{ t('reports.kh.variant.d_zjist') }}</label>
        <DateInput v-model="dZjist"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
      </template>
      <template v-if="needsVyzvaRef">
        <label class="text-sm text-neutral-600">{{ t('reports.kh.variant.c_jed_vyzvy') }}</label>
        <input type="text" v-model="cJedVyzvy" placeholder="99999999/99/9999-99999-999999"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm w-64" />
      </template>
      <span v-if="isVyzvaOdpoved" class="text-xs text-warning-700">
        {{ t('reports.kh.variant.vyzva_hint') }}
      </span>
      <span class="text-xs text-neutral-500">{{ t('reports.kh.variant.hint') }}</span>
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

      <!-- Deadline card -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
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

      <!-- Sekce A — plnění s povinností přiznat daň -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.kh.section_a_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="section in sectionARows" :key="section.code">
              <td class="px-5 py-2.5 text-neutral-700">
                <strong class="font-mono">{{ section.code }}</strong> — {{ t(section.labelKey) }}
              </td>
              <td class="px-5 py-2.5 text-right font-mono">
                {{ section.count }} {{ t(section.aggregated ? 'reports.kh.aggregated' : 'reports.kh.rows') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Sekce B — přijaté -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.kh.section_b_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="section in sectionBRows" :key="section.code">
              <td class="px-5 py-2.5 text-neutral-700">
                <strong class="font-mono">{{ section.code }}</strong> — {{ t(section.labelKey) }}
              </td>
              <td class="px-5 py-2.5 text-right font-mono">
                {{ section.count }} {{ t(section.aggregated ? 'reports.kh.aggregated' : 'reports.kh.rows') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tip -->
      <div class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ isLegalEntity ? t('reports.kh.note_po') : t('reports.kh.note_fo') }}
      </div>
    </div>
  </div>
</template>
