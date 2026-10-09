<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  reportsApi, KH_EVIDENCE_SECTIONS,
  type KhEvidenceReport, type KhEvidenceSection, type KhEvidenceStatus,
} from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import KhEvidenceSections from '@/components/reports/KhEvidenceSections.vue'

// Soupis dokladů podle PODANÉHO kontrolního hlášení porovnaný s aktuálními daty (issue #142).
const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const submissionId = computed(() => Number(route.params.id))
const section = ref<KhEvidenceSection>('all')
const sectionOptions: KhEvidenceSection[] = ['all', ...KH_EVIDENCE_SECTIONS.filter(s => s !== 'none')]
const report = ref<KhEvidenceReport | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    report.value = await reportsApi.khEvidenceSubmitted(submissionId.value, section.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
    report.value = null
  } finally {
    loading.value = false
  }
}

function download(format: 'pdf' | 'xlsx') {
  window.open(reportsApi.khEvidenceSubmittedDownloadUrl(submissionId.value, section.value, format), '_blank')
}

function sectionLabel(s: KhEvidenceSection): string {
  if (s === 'all') return t('reports.kh_evidence.filter_all')
  return `${s} - ${t(`reports.kh_evidence.section_label.${s.replace('.', '')}`)}`
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const statusOrder: KhEvidenceStatus[] = ['match', 'amount_diff', 'only_submitted', 'only_current']
const hasDifferences = computed(() =>
  !!report.value?.status_counts
  && (report.value.status_counts.amount_diff + report.value.status_counts.only_submitted + report.value.status_counts.only_current) > 0,
)

watch(section, load)
onMounted(load)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <RouterLink to="/reports/submissions" class="text-sm text-primary-700 hover:underline">
          {{ t('reports.kh_evidence.back_to_submissions') }}
        </RouterLink>
        <h1 class="text-2xl font-semibold">{{ t('reports.kh_evidence.submitted_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.kh_evidence.submitted_subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model="section" :aria-label="t('reports.kh_evidence.filter_label')"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm max-w-full">
          <option v-for="s in sectionOptions" :key="s" :value="s">{{ sectionLabel(s) }}</option>
        </select>
        <template v-if="auth.canRead('reports.export')">
          <button type="button" @click="download('pdf')" :disabled="loading || !report"
            :class="btnOutline('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('reports.kh_evidence.download_pdf') }}
          </button>
          <button type="button" @click="download('xlsx')" :disabled="loading || !report"
            :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
            {{ t('reports.kh_evidence.download_xlsx') }}
          </button>
        </template>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="report" class="space-y-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-2">
        <div class="text-lg font-semibold font-mono">{{ report.period.label }}</div>
        <p class="text-sm text-neutral-600">
          {{ t('reports.kh_evidence.source_submitted', {
            date: fmtDate(report.submission?.submitted_at),
            variant: report.submission?.variant_label ?? '',
          }) }}
        </p>
        <div v-if="report.status_counts" class="flex flex-wrap gap-2 text-xs">
          <span v-for="s in statusOrder" :key="s" class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-700 whitespace-nowrap">
            {{ t(`reports.kh_evidence.status.${s}`) }}: {{ report.status_counts[s] }}
          </span>
        </div>
        <p class="text-xs" :class="hasDifferences ? 'text-warning-700' : 'text-success-700'">
          {{ hasDifferences ? t('reports.kh_evidence.has_differences') : t('reports.kh_evidence.no_differences') }}
        </p>
        <p class="text-xs text-neutral-500">{{ t('reports.kh_evidence.aggregate_note') }}</p>
      </div>
      <KhEvidenceSections :report="report" />
    </div>
  </div>
</template>
