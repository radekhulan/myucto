<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { reportsApi, type DphPriznaniPreview, type DphSettings, type DphTrendRow, type DphDraftsPrediction, type DphVariant, type DphCrossCheckDocument, type DphCrossCheckFinding, type DphCrossCheck343Reason } from '@/api/reports'
import { vatClearingApi, type VatClearingStatus } from '@/api/vatClearing'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'
import { ICONS, btnOutline, btnFilled } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { downloadApiFile } from '@/utils/downloadFile'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const settings = ref<DphSettings | null>(null)
const preview = ref<DphPriznaniPreview | null>(null)
const trend = ref<DphTrendRow[]>([])
const draftsPrediction = ref<DphDraftsPrediction | null>(null)
const loading = ref(false)
const error = ref('')

// Period override (jinak default ze settings.vat_period)
const periodOverride = ref<'monthly' | 'quarterly' | ''>('')
const effectivePeriod = computed<'monthly' | 'quarterly'>(() => {
  if (periodOverride.value) return periodOverride.value
  return (settings.value?.vat_period as 'monthly' | 'quarterly') || 'monthly'
})

const isQuarterly = computed(() => effectivePeriod.value === 'quarterly')

// Quarter 1-4 z měsíce
const currentQuarter = computed(() => Math.ceil(month.value / 3))

// C7' — typ podání. Dodatečné (D/E) vyžaduje datum zjištění důvodů (§141 DŘ).
const variant = ref<DphVariant>('radne')
const dZjist = ref('')
// Důvody pro podání dodatečného přiznání (§ 141 odst. 5 DŘ) — jdou do textové přílohy
// výkazu. Bez nich EPO podání sice přijme, ale vytkne je.
const reason = ref('')
const isAmendment = computed(() => variant.value === 'dodatecne' || variant.value === 'dodatecne_opravne')
const amendmentReady = computed(() => !isAmendment.value || dZjist.value !== '')

async function loadAll() {
  loading.value = true
  error.value = ''
  try {
    const [s, tr, dp] = await Promise.all([
      reportsApi.dphSettings(),
      reportsApi.dphTrend(12),
      reportsApi.dphDraftsPrediction(year.value, month.value, periodOverride.value || undefined).catch(() => null),
    ])
    settings.value = s
    trend.value = tr
    draftsPrediction.value = dp
    // Dodatečné bez data zjištění nelze spočítat — počkáme, až uživatel datum zadá.
    if (amendmentReady.value) {
      preview.value = await reportsApi.dphPreview(
        year.value, month.value, periodOverride.value || undefined,
        variant.value, isAmendment.value ? dZjist.value : undefined,
        isAmendment.value ? reason.value : undefined,
      )
    } else {
      preview.value = null
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

// ── Interní doklad zúčtování DPH (migrace 1332) ────────────────────────────────
// Primárně ho zakládá PODÁNÍ přiznání; tenhle panel je ruční cesta pro období, za
// která se přiznání v aplikaci nepodává, a pro nápravu po opravě zpětného dokladu.
// Náhled (co se zaúčtuje) je vždy samostatný krok PŘED zápisem do deníku.
const clearing = ref<VatClearingStatus | null>(null)
const clearingBusy = ref(false)
const clearingError = ref('')
const clearingDone = ref('')

async function loadClearing() {
  clearing.value = null
  clearingError.value = ''
  if (!auth.canRead('accounting')) return
  try {
    clearing.value = await vatClearingApi.status(year.value, month.value)
  } catch {
    // Neplátce / jednoduché účetnictví / chybějící osnova — panel se prostě neukáže.
    clearing.value = null
  }
}

async function runClearing() {
  clearingBusy.value = true
  clearingError.value = ''
  clearingDone.value = ''
  try {
    const r = await vatClearingApi.run(year.value, month.value)
    clearing.value = r
    clearingDone.value = r.status === 'deleted_zero'
      ? t('reports.dph.clearing.done_deleted')
      : t('reports.dph.clearing.done_posted', { period: r.period_label })
    await loadClearing()
  } catch (e) {
    clearingError.value = apiErrorMessage(e)
  } finally {
    clearingBusy.value = false
  }
}

const postFilingDocs = computed(() => preview.value?.post_filing_changes?.documents ?? [])
const documentPageSize = 20
const postFilingPage = ref(1)
const pagedPostFilingDocs = computed(() => postFilingDocs.value.slice(
  (postFilingPage.value - 1) * documentPageSize,
  postFilingPage.value * documentPageSize,
))
const crossCheckPages = reactive<Record<string, number>>({})
function crossCheckKey(finding: DphCrossCheckFinding): string {
  return `${finding.blocking ? 'blocking' : 'info'}:${finding.check}`
}
function crossCheckPage(finding: DphCrossCheckFinding): number {
  return crossCheckPages[crossCheckKey(finding)] ?? 1
}
function pagedCrossCheckDocuments(finding: DphCrossCheckFinding): DphCrossCheckDocument[] {
  const page = crossCheckPage(finding)
  return finding.documents.slice((page - 1) * documentPageSize, page * documentPageSize)
}
function setCrossCheckPage(finding: DphCrossCheckFinding, page: number) {
  crossCheckPages[crossCheckKey(finding)] = page
}

const blockingCrossCheck = computed(() => (preview.value?.cross_check ?? []).filter(f => f.blocking))
const infoCrossCheck = computed(() => (preview.value?.cross_check ?? []).filter(f => !f.blocking))

// Kontrola 343 — lidská věta ke kódu reason (01-UX P2: vždy věta, nikdy jen kód).
// Exhaustivní Record: nová hodnota enumu bez věty = chyba vue-tsc.
function formatClaimPeriod(period: string): string {
  const [y, m] = period.split('-')
  return y && m ? `${m}/${y}` : period
}
const periodEndYm = computed(() => {
  const m = isQuarterly.value ? currentQuarter.value * 3 : month.value
  return `${year.value}-${String(m).padStart(2, '0')}`
})
const reasonSentence: Record<DphCrossCheck343Reason, (d: DphCrossCheckDocument) => string> = {
  timing_73: d => {
    if (d.claim_period) {
      const base = t('reports.dph.cross_check.reason.timing_claim_later', { period: formatClaimPeriod(d.claim_period) })
      return d.received_at
        ? `${base} ${t('reports.dph.cross_check.reason.timing_received', { date: formatDate(d.received_at) })}`
        : base
    }
    const key = d.entry_date && d.entry_date.slice(0, 7) > periodEndYm.value
      ? 'reports.dph.cross_check.reason.timing_booked_later'
      : 'reports.dph.cross_check.reason.timing_booked_earlier'
    return t(key, { date: formatDate(d.entry_date ?? '') })
  },
  value_mismatch: () => t('reports.dph.cross_check.reason.value_mismatch'),
  missing_entry: () => t('reports.dph.cross_check.reason.missing_entry'),
  extra_entry: () => t('reports.dph.cross_check.reason.extra_entry'),
}
function reasonText(d: DphCrossCheckDocument): string {
  return d.reason ? reasonSentence[d.reason](d) : ''
}
function findingLabel(f: DphCrossCheckFinding): string {
  return f.check === 'draft_advance_tax_documents'
    ? t('reports.dph.cross_check.draft_advance_title')
    : f.label
}
function findingNote(f: DphCrossCheckFinding): string {
  return f.check === 'draft_advance_tax_documents'
    ? t('reports.dph.cross_check.draft_advance_note')
    : f.note
}

function formatCrossAmount(value: number | null): string {
  return value == null ? '—' : formatMoney(value, 'CZK')
}

async function downloadXml() {
  if (!preview.value) return
  const acknowledge = blockingCrossCheck.value.length > 0
    ? confirm(t('reports.dph.cross_check.confirm_download_anyway'))
    : false
  if (blockingCrossCheck.value.length > 0 && !acknowledge) return
  try {
    await downloadApiFile(reportsApi.dphDownloadUrl(
      year.value, month.value, periodOverride.value || undefined, acknowledge,
      variant.value, isAmendment.value ? dZjist.value : undefined,
      isAmendment.value ? reason.value : undefined,
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

const quarterOptions = [1, 2, 3, 4]

// Pro quarterly: month input = poslední měsíc kvartálu (3/6/9/12)
function setQuarter(q: number) {
  month.value = q * 3
}

const linesSorted = computed(() => {
  if (!preview.value) return []
  return Object.entries(preview.value.summary.lines)
    .map(([line, data]) => ({ line, ...data }))
    .sort((a, b) => Number(a.line) - Number(b.line))
})

const outputLines = computed(() => linesSorted.value.filter(l => Number(l.line) < 40))
const inputLines = computed(() => linesSorted.value.filter(l => Number(l.line) >= 40))

// Vývoj DPH řazený sestupně dle data — nejnovější měsíc nahoře.
const trendSorted = computed(() => [...trend.value].sort((a, b) => b.period.localeCompare(a.period)))

// Trend chart helpers
const trendMaxVat = computed(() => {
  let max = 0
  for (const t of trend.value) {
    if (t.vat_output > max) max = t.vat_output
    if (t.vat_input > max) max = t.vat_input
    if (Math.abs(t.vat_due) > max) max = Math.abs(t.vat_due)
  }
  return max
})
function trendBarPct(value: number): number {
  if (trendMaxVat.value === 0) return 0
  return Math.round((Math.abs(value) / trendMaxVat.value) * 100)
}
function formatMonthLabel(period: string): string {
  const [y, m] = period.split('-')
  if (!y || !m) return period
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' })
}

// Deadline countdown
const daysToDeadline = computed(() => {
  if (!preview.value?.summary.submission_deadline) return null
  const deadline = new Date(preview.value.summary.submission_deadline)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.ceil((deadline.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
})

watch([year, month, periodOverride, variant, dZjist, reason], () => {
  postFilingPage.value = 1
  clearingDone.value = ''
  for (const key of Object.keys(crossCheckPages)) delete crossCheckPages[key]
  void loadAll()
  void loadClearing()
})
onMounted(() => {
  void loadAll()
  void loadClearing()
})
</script>

<template>
  <div class="max-w-5xl">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.dph.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('reports.dph.subtitle') }}
          <span v-if="settings?.vat_period" class="ml-2 px-2 py-0.5 text-xs rounded border bg-primary-50 text-primary-700 border-primary-500/40">
            {{ t('reports.dph.you_are_' + settings.vat_period) }}
          </span>
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle (override settings.vat_period) -->
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodOverride = 'monthly'"
            :class="effectivePeriod === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer">
            {{ t('reports.dph.monthly') }}
          </button>
          <button type="button" @click="periodOverride = 'quarterly'"
            :class="effectivePeriod === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer border-l border-neutral-300">
            {{ t('reports.dph.quarterly') }}
          </button>
        </div>

        <!-- Quarter picker pokud quarterly, jinak month -->
        <select v-if="isQuarterly" :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-else v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button v-if="auth.canRead('reports.export')" type="button" @click="downloadXml" :disabled="loading || !preview"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('reports.dph.download_xml') }}
        </button>
      </div>
    </div>

    <!-- Typ podání (C7' — řádné / opravné / dodatečné) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center gap-3">
      <label class="text-sm font-medium text-neutral-700">{{ t('reports.dph.variant.label') }}</label>
      <select v-model="variant" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="radne">{{ t('reports.dph.variant.radne') }}</option>
        <option value="opravne">{{ t('reports.dph.variant.opravne') }}</option>
        <option value="dodatecne">{{ t('reports.dph.variant.dodatecne') }}</option>
        <!-- C7': opravné dodatečné (E) má náhradovou sémantiku, backend ji zatím bezpečně
             nezrekonstruuje (vrací amendment_correction_unsupported) → skryto z nabídky. -->
      </select>
      <template v-if="isAmendment">
        <label class="text-sm text-neutral-600">{{ t('reports.dph.variant.d_zjist') }}</label>
        <DateInput v-model="dZjist"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        <label class="text-sm text-neutral-600">{{ t('reports.dph.variant.reason') }}</label>
        <input type="text" v-model="reason" :placeholder="t('reports.dph.variant.reason_placeholder')"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm flex-1 min-w-64" />
      </template>
      <span class="text-xs text-neutral-500">{{ t('reports.dph.variant.hint') }}</span>
    </div>

    <!-- Dodatečné bez data zjištění — čekáme na doplnění -->
    <div v-if="isAmendment && !amendmentReady && !loading"
      class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700 mb-4">
      {{ t('reports.dph.variant.need_date') }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- Fronta „doklady změněné po podání" (C7') -->
      <div v-if="postFilingDocs.length > 0"
        class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700 space-y-2">
        <strong>{{ t('reports.dph.post_filing.title') }}</strong>
        <p class="text-xs">{{ t('reports.dph.post_filing.hint') }}</p>
        <ul class="list-disc list-inside">
          <li v-for="d in pagedPostFilingDocs" :key="d.source + d.invoice_id">
            <span class="font-mono">{{ d.doc_number ?? ('#' + d.invoice_id) }}</span>
            — {{ formatMoney(d.total, 'CZK') }}
            <span class="text-xs text-neutral-500">({{ t('reports.dph.post_filing.changed_at') }}: {{ d.updated_at }})</span>
          </li>
        </ul>
        <PaginationBar embedded :page="postFilingPage" :per-page="documentPageSize" :total="postFilingDocs.length" @update:page="postFilingPage = $event" />
      </div>
      <div v-if="preview.post_filing_changes?.has_filing && !preview.post_filing_changes.snapshot_available"
        class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        {{ t('reports.dph.post_filing.legacy_snapshot_warning') }}
      </div>

      <!-- Interní doklad zúčtování DPH (migrace 1332) — 343.100/343.200 → 343.900.
           Primární spouštěč je PODÁNÍ přiznání; tady je náhled a ruční přepočet. -->
      <div v-if="clearing && clearing.freshness !== 'not_applicable'"
        class="rounded-md border p-3 text-sm space-y-2"
        :class="clearing.freshness === 'ok'
          ? 'bg-neutral-50 border-neutral-200 text-neutral-700'
          : 'bg-warning-50 border-warning-500/40 text-warning-700'">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div>
            <strong>{{ t('reports.dph.clearing.title') }}</strong>
            <p class="text-xs mt-0.5">{{ t('reports.dph.clearing.trigger_hint') }}</p>
          </div>
          <span class="text-xs font-medium px-2 py-0.5 rounded-full whitespace-nowrap"
            :class="clearing.freshness === 'ok' ? 'bg-success-100 text-success-700' : 'bg-warning-200 text-warning-800'">
            {{ t('reports.dph.clearing.freshness.' + clearing.freshness) }}
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 font-mono text-xs">
          <div>{{ t('reports.dph.clearing.output_vat') }} ({{ clearing.accounts.output }}): {{ formatMoney(clearing.output_vat, 'CZK') }}</div>
          <div>{{ t('reports.dph.clearing.input_vat') }} ({{ clearing.accounts.input }}): {{ formatMoney(clearing.input_vat, 'CZK') }}</div>
          <div class="font-semibold">{{ t('reports.dph.clearing.settlement') }} ({{ clearing.accounts.settlement }}): {{ formatMoney(clearing.settlement, 'CZK') }}</div>
        </div>

        <p v-if="clearing.freshness === 'stale' && clearing.posted" class="text-xs">
          {{ t('reports.dph.clearing.stale_hint', {
            posted: formatMoney(clearing.posted.settlement, 'CZK'),
            fresh: formatMoney(clearing.settlement, 'CZK'),
          }) }}
        </p>
        <p v-else-if="clearing.freshness === 'missing'" class="text-xs">{{ t('reports.dph.clearing.missing_hint') }}</p>

        <p v-if="clearing.run" class="text-xs text-neutral-500">
          {{ t('reports.dph.clearing.last_run', {
            trigger: t('reports.dph.clearing.trigger.' + clearing.run.trigger_source),
            at: formatDate(clearing.run.computed_at),
          }) }}
          <template v-if="clearing.run.submission_id">
            — {{ t('reports.dph.clearing.linked_submission', { id: clearing.run.submission_id, variant: clearing.run.submission_variant ?? '?' }) }}
          </template>
        </p>

        <p v-if="!clearing.writable" class="text-xs font-medium">
          {{ t('reports.dph.clearing.blocked.' + (clearing.writable_reason ?? 'period_not_open')) }}
        </p>

        <div v-if="clearingError" class="text-xs text-danger-600">{{ clearingError }}</div>
        <div v-if="clearingDone" class="text-xs text-success-700">{{ clearingDone }}</div>

        <div class="flex flex-wrap gap-2 pt-1">
          <button v-if="auth.canWrite('accounting.journal.post')" type="button"
            :class="clearing.freshness === 'ok' ? btnOutline('neutral') : btnFilled('primary')"
            :disabled="clearingBusy || !clearing.writable"
            @click="runClearing">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path :d="ICONS.cycle" stroke-linecap="round" stroke-linejoin="round" /></svg>
            {{ clearing.entry_id ? t('reports.dph.clearing.action_recompute') : t('reports.dph.clearing.action_post') }}
          </button>
          <RouterLink v-if="clearing.entry_id" :to="`/accounting/journal?entry_id=${clearing.entry_id}`" :class="btnOutline('neutral')">
            {{ t('reports.dph.clearing.open_entry') }}
          </RouterLink>
        </div>
      </div>

      <!-- Dodatečné přiznání — poslední známá daň vs rozdíl (ř.66) -->
      <div v-if="preview.summary.is_amendment"
        class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        <strong>{{ t('reports.dph.variant.amendment_title') }}</strong>
        <div class="mt-1 grid grid-cols-1 sm:grid-cols-3 gap-2 font-mono">
          <div>{{ t('reports.dph.variant.last_known_tax') }}: {{ formatMoney(preview.summary.last_known_tax ?? 0, 'CZK') }}</div>
          <div>{{ t('reports.dph.variant.tax_difference') }}: {{ formatMoney(preview.summary.tax_difference ?? 0, 'CZK') }}</div>
          <div v-if="preview.summary.d_zjist">{{ t('reports.dph.variant.d_zjist') }}: {{ preview.summary.d_zjist }}</div>
        </div>
      </div>

      <!-- Křížová kontrola DPHDP3↔KH↔SH↔343 (C8') -->
      <div v-if="blockingCrossCheck.length > 0" class="bg-danger-50 border border-danger-500/40 rounded-md p-3 text-sm text-danger-700 space-y-3">
        <strong>{{ t('reports.dph.cross_check.title') }}</strong>
        <div v-for="f in blockingCrossCheck" :key="f.check" class="border-t border-danger-500/20 pt-2 first:border-0 first:pt-0">
          <div class="font-medium">{{ f.label }}</div>
          <div class="mt-0.5">{{ f.note }}</div>
          <div class="mt-1 font-mono text-xs">
            {{ formatCrossAmount(f.declared) }} vs {{ formatCrossAmount(f.counter) }}
            ({{ t('reports.dph.cross_check.difference') }}: {{ formatMoney(f.difference, 'CZK') }})
          </div>
          <ul v-if="f.documents.length > 0" class="mt-1 list-disc list-inside">
            <li v-for="d in pagedCrossCheckDocuments(f)" :key="d.invoice_id + d.source">
              {{ d.doc_number ?? ('#' + d.invoice_id) }} — {{ formatCrossAmount(d.declared) }} vs {{ formatCrossAmount(d.counter) }}<template v-if="d.reason"> — <span :title="d.reason">{{ reasonText(d) }}</span></template>
            </li>
          </ul>
          <PaginationBar embedded :page="crossCheckPage(f)" :per-page="documentPageSize" :total="f.documents.length" @update:page="setCrossCheckPage(f, $event)" />
        </div>
        <p class="text-xs text-danger-600">{{ t('reports.dph.cross_check.download_gate_hint') }}</p>
      </div>
      <div v-if="infoCrossCheck.length > 0" class="bg-neutral-50 border border-neutral-300 rounded-md p-3 text-sm text-neutral-600 space-y-2">
        <div v-for="f in infoCrossCheck" :key="f.check">
          <template v-if="f.check === 'account_343_vs_return' && f.documents.length > 0">
            <strong>{{ t('reports.dph.cross_check.explained_title') }}</strong>
            <div class="mt-0.5">{{ f.note }}</div>
            <ul class="mt-1 list-disc list-inside">
              <li v-for="d in pagedCrossCheckDocuments(f)" :key="d.invoice_id + d.source">
                {{ d.doc_number ?? ('#' + d.invoice_id) }} — {{ formatCrossAmount(d.declared) }} vs {{ formatCrossAmount(d.counter) }}<template v-if="d.reason"> — <span :title="d.reason">{{ reasonText(d) }}</span></template>
              </li>
            </ul>
            <PaginationBar embedded :page="crossCheckPage(f)" :per-page="documentPageSize" :total="f.documents.length" @update:page="setCrossCheckPage(f, $event)" />
          </template>
          <template v-else>
            <strong>{{ findingLabel(f) }}</strong>
            <div class="mt-0.5">{{ findingNote(f) }}</div>
            <ul v-if="f.documents.length > 0" class="mt-1 list-disc list-inside">
              <li v-for="d in pagedCrossCheckDocuments(f)" :key="d.invoice_id + d.source">
                {{ d.doc_number ?? ('#' + d.invoice_id) }}
              </li>
            </ul>
            <PaginationBar embedded :page="crossCheckPage(f)" :per-page="documentPageSize" :total="f.documents.length" @update:page="setCrossCheckPage(f, $event)" />
          </template>
        </div>
      </div>

      <!-- Rekapitulace KPI cards (4 — přidán Termín) -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_output') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_output, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_output_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_input') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_input, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_input_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction') : t('reports.dph.tax_due') }}
          </div>
          <div class="text-xl font-bold font-mono"
            :class="preview.summary.is_excess_deduction ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(Math.abs(preview.summary.tax_due), 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction_hint') : t('reports.dph.tax_due_hint') }}
          </div>
        </div>
        <!-- Deadline countdown -->
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

      <!-- Predikce DPH pro zvolené období (zahrnuje vystavené i koncepty) -->
      <div v-if="draftsPrediction && (draftsPrediction.sale_count + draftsPrediction.purchase_count) > 0"
        class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">{{ t('reports.dph.prediction_vat_output') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(draftsPrediction.vat_output, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.dph.prediction_n_sale', { n: draftsPrediction.sale_count, d: draftsPrediction.sale_draft_count }) }}
          </div>
        </div>
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">{{ t('reports.dph.prediction_vat_input') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(draftsPrediction.vat_input, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.dph.prediction_n_purchase', { n: draftsPrediction.purchase_count, d: draftsPrediction.purchase_draft_count }) }}
          </div>
        </div>
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">
            {{ draftsPrediction.tax_due >= 0 ? t('reports.dph.prediction_tax_due') : t('reports.dph.prediction_excess_deduction') }}
          </div>
          <div class="text-xl font-bold font-mono"
            :class="draftsPrediction.tax_due >= 0 ? 'text-danger-500' : 'text-success-600'">
            {{ formatMoney(Math.abs(draftsPrediction.tax_due), 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.prediction_tax_due_hint') }}</div>
        </div>
        <div class="bg-warning-100 border border-warning-500/50 rounded-lg shadow-sm p-5 flex flex-col justify-center">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-semibold mb-1 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 0 0 1.74-3l-6.93-12a2 2 0 0 0-3.48 0l-6.93 12a2 2 0 0 0 1.74 3z"/></svg>
            {{ t('reports.dph.prediction_label') }}
          </div>
          <div class="text-xs text-neutral-700 leading-snug">{{ t('reports.dph.prediction_explanation') }}</div>
        </div>
      </div>

      <!-- Monthly DPH trend chart — tabulkový layout, čísla zarovnaná doprava -->
      <div v-if="trend.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('reports.dph.monthly_trend') }}</h3>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-danger-400"></span>{{ t('reports.dph.vat_output') }}</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-success-500"></span>{{ t('reports.dph.vat_input') }}</span>
          </div>
        </header>
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-20">{{ t('reports.dph.line') }}</th>
              <th class="text-right px-3 py-2 w-32">{{ t('reports.dph.vat_output') }}</th>
              <th class="px-3 py-2">&nbsp;</th>
              <th class="text-right px-3 py-2 w-32">{{ t('reports.dph.vat_input') }}</th>
              <th class="px-3 py-2">&nbsp;</th>
              <th class="text-right px-5 py-2 w-32">{{ t('reports.dph.net_due') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in trendSorted" :key="m.period">
              <td class="px-5 py-2 font-medium text-neutral-700">{{ formatMonthLabel(m.period) }}</td>
              <td class="px-3 py-2 text-right font-mono text-neutral-700">{{ formatMoney(m.vat_output, 'CZK') }}</td>
              <td class="px-1 py-2 w-32">
                <div class="bg-danger-400 h-2 rounded-sm" :style="{ width: trendBarPct(m.vat_output) + '%' }"></div>
              </td>
              <td class="px-3 py-2 text-right font-mono text-neutral-700">{{ formatMoney(m.vat_input, 'CZK') }}</td>
              <td class="px-1 py-2 w-32">
                <div class="bg-success-500 h-2 rounded-sm" :style="{ width: trendBarPct(m.vat_input) + '%' }"></div>
              </td>
              <td class="px-5 py-2 text-right font-mono"
                :class="m.vat_due >= 0 ? 'text-danger-500' : 'text-success-600'">
                {{ m.vat_due >= 0 ? '↑' : '↓' }} {{ formatMoney(Math.abs(m.vat_due), 'CZK') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- DPH na výstupu -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.dph.output_section') }}</h3>
        </header>
        <EmptyState v-if="outputLines.length === 0" dense accent="neutral" icon="doc"
          :title="t('reports.dph.no_output_lines')" />
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in outputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- DPH na vstupu -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.dph.input_section') }}</h3>
        </header>
        <EmptyState v-if="inputLines.length === 0" dense accent="neutral" icon="doc"
          :title="t('reports.dph.no_input_lines')" />
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in inputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tip -->
      <div v-if="outputLines.length === 0 && inputLines.length === 0" class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ t('reports.dph.no_data_hint') }}
      </div>
    </div>
  </div>
</template>
