<script setup lang="ts">
/**
 * Systém → Diagnostika.
 *
 * Dvě věci na jedné stránce:
 *  1. audit prostředí instalace s verdiktem (ne výpis hodnot),
 *  2. diagnostický balíček jako podklad k incidentu placené podpory.
 *
 * Balíček se NIKAM neodesílá — vygeneruje se na disku instalace, uživatel si ho
 * stáhne a k incidentu ho na portálu podpory přiloží sám. Logy jsou proto
 * vědomý opt-in s náhledem obsahu, ne výchozí volba.
 */
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { journalEntryLink, journalSourceLink } from '@/utils/journalSourceLink'
import {
  diagnosticsApi,
  type BundleOptions,
  type BundlePreview,
  type BundleResult,
  type DiagnosticsReport,
  type LogPreview,
} from '@/api/diagnostics'
import EnvironmentCheckList from '@/components/system/EnvironmentCheckList.vue'
import PayrollArchiveEncryptionPanel from '@/components/system/PayrollArchiveEncryptionPanel.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const suppliers = useSupplierStore()

const isAdmin = computed(() => auth.isSuperadmin)

const report = ref<DiagnosticsReport | null>(null)
const loading = ref(false)
let loadSequence = 0
const errorMsg = ref<string | null>(null)

const options = ref<Required<BundleOptions>>({
  include_version: true,
  include_environment: true,
  include_license: true,
  include_migrations: true,
  include_cron: true,
  include_config: true,
  // Logy jsou v balíčku ve výchozím stavu: bez nich podpora typicky nemá z čeho
  // vyjít a stejně si je vyžádá. Souhlas s jejich obsahem se pořád potvrzuje
  // zvlášť (viz `acknowledged`) a jde je odškrtnout.
  include_logs: true,
  days: 7,
  log_level: 'WARNING',
})

const preview = ref<BundlePreview | null>(null)
const previewBusy = ref(false)
const building = ref(false)
const built = ref<BundleResult | null>(null)
const acknowledged = ref(false)

const logPreview = ref<LogPreview | null>(null)
const logBusy = ref(false)
const logOpen = ref(false)
const logDay = ref<string>('')
const logPage = ref(1)

const DAY_CHOICES = [1, 3, 7, 14]
const LEVEL_CHOICES = ['INFO', 'NOTICE', 'WARNING', 'ERROR']

async function load(postingAfter = 0) {
  if (!isAdmin.value) return
  const sequence = ++loadSequence
  loading.value = true
  errorMsg.value = null
  try {
    const next = await diagnosticsApi.report(undefined, postingAfter)
    if (sequence === loadSequence) report.value = next
  } catch (e) {
    if (sequence === loadSequence) errorMsg.value = (e as Error)?.message ?? t('diagnostics.load_failed')
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function loadPreview() {
  if (!isAdmin.value) return
  previewBusy.value = true
  try {
    preview.value = await diagnosticsApi.preview(options.value)
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.load_failed')
  } finally {
    previewBusy.value = false
  }
}

/** Každá změna rozsahu zneplatní jak náhled, tak už vygenerovaný balíček. */
async function onOptionsChanged() {
  built.value = null
  acknowledged.value = false
  await loadPreview()
  if (options.value.include_logs && logOpen.value) await loadLogs(1)
}

async function loadLogs(page: number) {
  logBusy.value = true
  try {
    logPage.value = page
    logPreview.value = await diagnosticsApi.logs({
      day: logDay.value || undefined,
      days: options.value.days,
      level: options.value.log_level,
      page,
      per_page: 100,
    })
    if (!logDay.value && logPreview.value?.day) logDay.value = logPreview.value.day
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.load_failed')
  } finally {
    logBusy.value = false
  }
}

async function toggleLogPreview() {
  logOpen.value = !logOpen.value
  if (logOpen.value && !logPreview.value) await loadLogs(1)
}

async function selectLogDay(day: string) {
  logDay.value = day
  await loadLogs(1)
}

async function build() {
  building.value = true
  errorMsg.value = null
  try {
    built.value = await diagnosticsApi.create(options.value)
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('diagnostics.bundle.failed')
  } finally {
    building.value = false
  }
}

onMounted(async () => {
  await load()
  await loadPreview()
})

let activatedBefore = false
onActivated(() => {
  if (activatedBefore) void load()
  activatedBefore = true
})

watch(() => suppliers.currentSupplierId, () => {
  report.value = null
  void load()
})

// ── Zobrazení ──────────────────────────────────────────────────────────────

const verdictClass = computed(() => {
  switch (report.value?.summary.status) {
    case 'fail':
      return 'border-danger-500/40 bg-danger-50/40'
    case 'warn':
      return 'border-warning-300 bg-warning-50/40'
    default:
      return 'border-success-300 bg-success-50/40'
  }
})

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const totalPages = computed(() =>
  logPreview.value ? Math.max(1, Math.ceil(logPreview.value.total / logPreview.value.per_page)) : 1,
)

/** Balíček smí vzniknout až po potvrzení, když jsou uvnitř logy. */
const canBuild = computed(() => {
  if (!preview.value?.within_limit) return false
  if (options.value.include_logs && !acknowledged.value) return false
  return !building.value
})
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <header class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('diagnostics.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('diagnostics.subtitle') }}</p>
      </div>
      <RouterLink
        v-if="isAdmin && !auth.isManagedInstallation"
        to="/admin/instance"
        :class="[btnOutline('neutral'), 'whitespace-nowrap']"
        data-testid="diagnostics-instance-admin"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('instance_admin.title') }}
      </RouterLink>
    </header>

    <div
      v-if="!isAdmin"
      class="rounded-md bg-warning-50 border border-warning-200 p-4 text-sm text-warning-800"
    >
      {{ t('diagnostics.no_admin') }}
    </div>

    <div v-else class="space-y-6">
      <!-- Verdikt -->
      <section v-if="report" class="rounded-lg border p-5" :class="verdictClass">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t(`diagnostics.verdict.${report.summary.status}`) }}
            </h2>
            <p class="text-sm text-neutral-600 mt-0.5">
              {{
                t('diagnostics.summary_counts', {
                  fail: report.summary.fail,
                  warn: report.summary.warn,
                  ok: report.summary.ok,
                })
              }}
            </p>
          </div>
          <button type="button" :disabled="loading" :class="btnOutline('neutral')" @click="load()">
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': loading }"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
            </svg>
            {{ loading ? t('diagnostics.refreshing') : t('diagnostics.refresh') }}
          </button>
        </div>
      </section>

      <!-- Náprava šifrování mzdového archivu: jen když kontrola něco našla. -->
      <PayrollArchiveEncryptionPanel v-if="report" :checks="report.checks" @changed="load()" />

      <section v-if="report?.imported_posting_ambiguities" class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('diagnostics.imported_postings.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('diagnostics.imported_postings.scope') }}</p>
        <p v-if="!report.imported_posting_ambiguities.available" class="mt-3 text-sm text-warning-800">
          {{ t('diagnostics.imported_postings.unavailable') }}
        </p>
        <p v-else-if="!report.imported_posting_ambiguities.total" class="mt-3 text-sm text-success-700">
          {{ t('diagnostics.imported_postings.empty') }}
        </p>
        <template v-else>
          <p class="mt-3 text-sm text-warning-800">{{ t('diagnostics.imported_postings.summary', { count: report.imported_posting_ambiguities.total }) }}</p>
          <p class="mt-1 text-sm text-neutral-600">{{ t('diagnostics.imported_postings.guidance') }}</p>
          <div class="mt-4 space-y-3">
            <article v-for="entry in report.imported_posting_ambiguities.entries" :key="entry.entry_id" class="rounded-md border border-warning-200 p-3">
              <div class="flex flex-wrap items-center gap-2">
                <RouterLink :to="journalEntryLink(entry.entry_id)" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
                  {{ t('diagnostics.imported_postings.entry', { id: entry.entry_id }) }}
                </RouterLink>
                <span class="text-sm text-neutral-600">{{ entry.entry_date }} · {{ entry.description }}</span>
              </div>
              <p class="mt-2 text-xs text-neutral-600">{{ t('diagnostics.imported_postings.owners', { count: entry.owner_count }) }}</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <RouterLink v-for="doc in entry.candidates" :key="`${doc.doc_type}:${doc.doc_id}`" :to="journalSourceLink({ source_type: doc.doc_type, source_id: doc.doc_id })!" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                  {{ t(`diagnostics.imported_postings.${doc.doc_type}`) }} {{ doc.document_number || `#${doc.doc_id}` }}
                </RouterLink>
              </div>
              <p v-if="entry.owner_count > entry.candidates.length" class="mt-2 text-xs text-neutral-500">{{ t('diagnostics.imported_postings.candidate_limit', { count: report.imported_posting_ambiguities.candidate_limit }) }}</p>
            </article>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load()">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ t('diagnostics.imported_postings.first') }}
            </button>
            <button v-if="report.imported_posting_ambiguities.next_after !== null" type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load(report.imported_posting_ambiguities.next_after)">
              {{ t('diagnostics.imported_postings.next') }}
              <svg class="w-4 h-4 -rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
            </button>
          </div>
        </template>
      </section>

      <!-- Kontroly -->
      <section v-if="report" class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('diagnostics.checks_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('diagnostics.checks_hint') }}</p>

        <EnvironmentCheckList class="mt-4" :checks="report.checks" />
      </section>

      <!-- Balíček -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('diagnostics.bundle.title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('diagnostics.bundle.subtitle') }}</p>

        <h3 class="text-sm font-semibold text-neutral-800 mt-4">{{ t('diagnostics.bundle.scope') }}</h3>
        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
          <label
            v-for="key in [
              'include_version',
              'include_environment',
              'include_license',
              'include_migrations',
              'include_cron',
              'include_config',
            ]"
            :key="key"
            class="flex items-start gap-2 text-sm text-neutral-700"
          >
            <input
              v-model="(options as any)[key]"
              type="checkbox"
              class="mt-0.5"
              @change="onOptionsChanged"
            />
            <span>{{ t(`diagnostics.bundle.item.${key}`) }}</span>
          </label>
        </div>

        <!-- Logy: vědomý opt-in -->
        <div class="mt-4 rounded-md border border-warning-200 bg-warning-50 p-3">
          <label class="flex items-start gap-2 text-sm text-warning-900">
            <input
              v-model="options.include_logs"
              type="checkbox"
              class="mt-0.5"
              @change="onOptionsChanged"
            />
            <span class="font-medium">{{ t('diagnostics.bundle.logs_enable') }}</span>
          </label>
          <p class="mt-1.5 text-sm text-warning-800">{{ t('diagnostics.bundle.logs_warning') }}</p>
          <p class="mt-1 text-sm text-warning-800">{{ t('diagnostics.bundle.logs_removed') }}</p>

          <div v-if="options.include_logs" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-xs text-warning-900">
              <span class="block mb-0.5">{{ t('diagnostics.bundle.logs_days') }}</span>
              <select
                v-model.number="options.days"
                class="rounded-md border border-warning-300 bg-surface px-2 py-1 text-sm"
                @change="onOptionsChanged"
              >
                <option v-for="d in DAY_CHOICES" :key="d" :value="d">
                  {{ t(`diagnostics.bundle.days_${d}`) }}
                </option>
              </select>
            </label>
            <label class="text-xs text-warning-900">
              <span class="block mb-0.5">{{ t('diagnostics.bundle.logs_level') }}</span>
              <select
                v-model="options.log_level"
                class="rounded-md border border-warning-300 bg-surface px-2 py-1 text-sm"
                @change="onOptionsChanged"
              >
                <option v-for="l in LEVEL_CHOICES" :key="l" :value="l">{{ l }}</option>
              </select>
            </label>
            <button type="button" :class="btnOutline('warning')" @click="toggleLogPreview">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" />
              </svg>
              {{ logOpen ? t('diagnostics.bundle.log_preview_hide') : t('diagnostics.bundle.log_preview_show') }}
            </button>
          </div>

          <!-- Náhled logu -->
          <div v-if="options.include_logs && logOpen" class="mt-3">
            <div v-if="logPreview && logPreview.days.length" class="flex flex-wrap gap-1.5 mb-2">
              <button
                v-for="d in logPreview.days"
                :key="d"
                type="button"
                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="d === logDay ? 'bg-warning-700 text-white' : 'bg-warning-100 text-warning-800'"
                @click="selectLogDay(d)"
              >
                {{ d }}
              </button>
            </div>

            <div
              v-if="logPreview && logPreview.lines.length"
              class="rounded-md bg-neutral-900 text-neutral-100 p-3 text-[11px] leading-relaxed overflow-x-auto max-h-96 overflow-y-auto"
            >
              <pre class="whitespace-pre-wrap break-all"><code>{{ logPreview.lines.join('\n') }}</code></pre>
            </div>
            <p v-else-if="!logBusy" class="text-sm text-warning-800">
              {{ t('diagnostics.bundle.log_preview_empty') }}
            </p>

            <div v-if="logPreview && logPreview.total > 0" class="mt-2 flex items-center gap-2 text-xs text-warning-900">
              <button
                type="button"
                :disabled="logPage <= 1 || logBusy"
                :class="btnOutline('neutral')"
                @click="loadLogs(logPage - 1)"
              >
                ‹
              </button>
              <span>{{ t('diagnostics.bundle.log_page', { page: logPage, pages: totalPages, total: logPreview.total }) }}</span>
              <button
                type="button"
                :disabled="logPage >= totalPages || logBusy"
                :class="btnOutline('neutral')"
                @click="loadLogs(logPage + 1)"
              >
                ›
              </button>
            </div>
          </div>
        </div>

        <!-- Náhled obsahu balíčku -->
        <h3 class="text-sm font-semibold text-neutral-800 mt-5">{{ t('diagnostics.bundle.preview_title') }}</h3>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('diagnostics.bundle.preview_hint') }}</p>

        <ul v-if="preview" class="mt-2 divide-y divide-neutral-100 rounded-md border border-neutral-200">
          <li
            v-for="item in preview.items"
            :key="item.name"
            class="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5 text-sm"
          >
            <span class="font-mono text-neutral-800">{{ item.name }}</span>
            <span class="flex items-center gap-2">
              <span
                v-if="item.sensitive"
                class="inline-flex items-center rounded-full bg-warning-100 text-warning-800 px-2 py-0.5 text-[11px] font-medium"
              >{{ t('diagnostics.bundle.sensitive') }}</span>
              <span class="text-neutral-500 tabular-nums">{{ formatBytes(item.bytes) }}</span>
            </span>
          </li>
          <li class="flex items-center justify-between px-3 py-1.5 text-sm font-medium bg-neutral-50">
            <span>{{ t('diagnostics.bundle.total') }}</span>
            <span class="tabular-nums">{{ formatBytes(preview.total_bytes) }}</span>
          </li>
        </ul>

        <p v-if="preview && !preview.within_limit" class="mt-2 text-sm text-danger-600">
          {{ t('diagnostics.bundle.too_large', { max: formatBytes(preview.max_bytes) }) }}
        </p>

        <!-- Potvrzení -->
        <label
          v-if="options.include_logs"
          class="mt-4 flex items-start gap-2 text-sm text-neutral-800"
        >
          <input v-model="acknowledged" type="checkbox" class="mt-0.5" />
          <span>{{ t('diagnostics.bundle.acknowledge') }}</span>
        </label>

        <div class="mt-4 flex flex-wrap gap-2">
          <button type="button" :disabled="!canBuild" :class="btnFilled('primary')" @click="build">
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': building }"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="building ? ICONS.cycle : ICONS.archive" />
            </svg>
            {{ building ? t('diagnostics.bundle.building') : t('diagnostics.bundle.create') }}
          </button>
          <button type="button" :disabled="previewBusy" :class="btnOutline('neutral')" @click="loadPreview">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
            </svg>
            {{ t('diagnostics.bundle.refresh_preview') }}
          </button>
        </div>

        <!-- Hotovo -->
        <div
          v-if="built && built.ok && built.filename"
          class="mt-4 rounded-md border border-success-300 bg-success-50 p-3"
        >
          <div class="text-sm font-medium text-success-800">{{ t('diagnostics.bundle.ready') }}</div>
          <dl class="mt-1 text-xs text-success-900 space-y-0.5">
            <div class="flex gap-1.5">
              <dt>{{ t('diagnostics.bundle.file') }}:</dt>
              <dd class="font-mono break-all">{{ built.filename }}</dd>
            </div>
            <div class="flex gap-1.5">
              <dt>{{ t('diagnostics.bundle.size') }}:</dt>
              <dd class="tabular-nums">{{ formatBytes(built.bytes ?? 0) }}</dd>
            </div>
            <div class="flex gap-1.5">
              <dt>SHA-256:</dt>
              <dd class="font-mono break-all">{{ built.sha256 }}</dd>
            </div>
          </dl>
          <a
            :href="diagnosticsApi.downloadUrl(built.filename)"
            :class="[btnFilled('success'), 'mt-3']"
            download
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" />
            </svg>
            {{ t('diagnostics.bundle.download') }}
          </a>
          <p class="mt-2 text-xs text-success-900">{{ t('diagnostics.bundle.next_step') }}</p>
          <p class="mt-1 text-xs text-success-900">{{ t('diagnostics.bundle.retention') }}</p>
        </div>
      </section>

      <div
        v-if="errorMsg"
        class="rounded-md bg-danger-50 border border-danger-500/40 p-4 text-sm text-danger-600"
      >
        {{ errorMsg }}
      </div>
    </div>
  </div>
</template>
