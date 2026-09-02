<script setup lang="ts">
/**
 * Výmaz osobních údajů mzdové agendy — návrh, schválení, provedení.
 *
 * Obrazovka existuje kvůli jedné vlastnosti téhle operace: je NEVRATNÁ a týká se
 * dat, která nikdo zpátky nezadá. Proto neukazuje tlačítko „smazat", ale celý
 * postup, ve kterém je každý krok samostatný a doložený:
 *
 *   1. sestavit návrh k datu — server do něj vezme jen osoby, kterým uplynula
 *      lhůta a nic je nedrží; koho vynechal a proč, ukazuje obrazovka retence,
 *   2. schválit nebo zamítnout — návrh JMENUJE osoby a rozepisuje dopad,
 *   3. provést schválený návrh — až tady se maže.
 *
 * Provedení se nedá odklepnout jedním kliknutím: potvrzovací dialog vyžaduje
 * zaškrtnutí a opsání čísla návrhu. Není to obřad pro obřad — je to jediná
 * pojistka proti tomu, aby se nevratný úkon spustil omylem z rozjeté ruky.
 *
 * Co zůstane, se říká PŘEDEM: auditní stopa výmazu (kdo, kdy, podle které lhůty
 * a se jménem osoby) je vědomé rozhodnutí a přežívá i úplný výmaz. Osobní údaje
 * ve zmrazeném obsahu (vystavená PDF, odeslaná XML) se nepřepisují — obrazovka
 * je vypisuje jako „zbytek", ne aby se na ně přišlo až potom.
 */
import { computed, onMounted, reactive, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollRetentionApi,
  type PayrollErasureProposal,
  type PayrollErasureProposalItem,
  type PayrollErasureSummary,
} from '@/api/payrollRetention'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { appIsoDate } from '@/utils/date'

const { t, te, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()

const canWrite = computed(() => auth.canWrite('payroll.erasure'))

const proposals = ref<PayrollErasureProposal[]>([])
const loading = ref(true)
/** Seznam se nenačetl — o návrzích nevíme NIC, takže se nesmí ukázat prázdno. */
const failed = ref(false)
const error = ref('')

const selectedId = ref<number | null>(null)
const detail = ref<{ proposal: PayrollErasureProposal; items: PayrollErasureProposalItem[] } | null>(null)
const detailLoading = ref(false)
const detailError = ref('')

const busy = ref(false)
const asOf = ref(appIsoDate())

const STATUS_BADGE: Record<string, string> = {
  pending: 'bg-warning-100 text-warning-700',
  approved: 'bg-primary-100 text-primary-700',
  rejected: 'bg-neutral-200 text-neutral-600',
  executed: 'bg-danger-100 text-danger-600',
}

const OUTCOME_BADGE: Record<string, string> = {
  pending: 'bg-neutral-200 text-neutral-600',
  done: 'bg-danger-100 text-danger-600',
  skipped_hold: 'bg-warning-100 text-warning-700',
  skipped_changed: 'bg-primary-100 text-primary-700',
}

const LOG_COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'payroll.erasure.col.number', required: true },
  { key: 'as_of', labelKey: 'payroll.erasure.col.as_of' },
  { key: 'status', labelKey: 'payroll.erasure.col.status' },
  { key: 'people', labelKey: 'payroll.erasure.col.people' },
  { key: 'created_at', labelKey: 'payroll.erasure.col.created_at' },
  { key: 'executed_at', labelKey: 'payroll.erasure.col.executed_at' },
]
const logTbl = useTablePrefs('payroll-erasure-log', LOG_COLUMNS)

const CANDIDATE_COLUMNS: ColumnDef[] = [
  { key: 'person', labelKey: 'payroll.erasure.col.person', required: true },
  { key: 'action', labelKey: 'payroll.erasure.col.action' },
  { key: 'source', labelKey: 'payroll.erasure.col.source' },
  { key: 'retained_until', labelKey: 'payroll.erasure.col.retained_until' },
  { key: 'impact', labelKey: 'payroll.erasure.col.impact' },
  { key: 'outcome', labelKey: 'payroll.erasure.col.outcome' },
]
const candidatesTbl = useTablePrefs('payroll-erasure-candidates', CANDIDATE_COLUMNS)

async function load() {
  loading.value = true
  error.value = ''
  try {
    proposals.value = await payrollRetentionApi.proposals()
    failed.value = false
  } catch (e) {
    error.value = apiErrorMessage(e)
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function openDetail(id: number) {
  selectedId.value = id
  detailLoading.value = true
  detailError.value = ''
  try {
    detail.value = await payrollRetentionApi.proposal(id)
  } catch (e) {
    detail.value = null
    detailError.value = apiErrorMessage(e)
  } finally {
    detailLoading.value = false
  }
}

async function createProposal() {
  busy.value = true
  try {
    const created = await payrollRetentionApi.createProposal(asOf.value)
    toast.success(t('payroll.erasure.proposal_created'))
    await load()
    await openDetail(created.id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function approve() {
  if (selectedId.value === null) return
  busy.value = true
  try {
    await payrollRetentionApi.approveProposal(selectedId.value)
    toast.success(t('payroll.erasure.approved'))
    await refreshBoth()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function reject() {
  if (selectedId.value === null) return
  if (!confirm(t('payroll.erasure.reject_confirm'))) return
  busy.value = true
  try {
    await payrollRetentionApi.rejectProposal(selectedId.value)
    toast.success(t('payroll.erasure.rejected'))
    await refreshBoth()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function refreshBoth() {
  const id = selectedId.value
  await load()
  if (id !== null) await openDetail(id)
}

// ── Provedení ───────────────────────────────────────────────────────────────
// Dvě nezávislá potvrzení: zaškrtnutí (rozumím, že je to nevratné) a opsání
// čísla návrhu (dívám se na TENHLE návrh, ne na ten, co byl otevřený předtím).
// Jedno kliknutí by tu bylo tolik jako smazat lidi omylem.
const confirmExecute = reactive({
  open: false,
  acknowledged: false,
  typedId: '',
})

const executeSummary = ref<PayrollErasureSummary | null>(null)

const executeReady = computed(() =>
  confirmExecute.acknowledged
  && selectedId.value !== null
  && confirmExecute.typedId.trim() === String(selectedId.value),
)

function openExecute() {
  confirmExecute.open = true
  confirmExecute.acknowledged = false
  confirmExecute.typedId = ''
}

async function execute() {
  if (!executeReady.value || selectedId.value === null) return
  busy.value = true
  try {
    executeSummary.value = await payrollRetentionApi.executeProposal(selectedId.value)
    confirmExecute.open = false
    toast.success(t('payroll.erasure.executed'))
    await refreshBoth()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// ── Odvozené pohledy ────────────────────────────────────────────────────────

const status = computed<string>(() => detail.value?.proposal.status ?? '')

const items = computed<PayrollErasureProposalItem[]>(() => detail.value?.items ?? [])

/** Kolik řádků osobních dat návrh smaže — součet přes všechny položky. */
const impact = computed<{ identity: number; residue: number }>(() => {
  let identity = 0
  let residue = 0
  for (const item of items.value) {
    for (const n of Object.values(item.cascade_counts?.identity ?? {})) identity += n
    for (const n of Object.values(item.cascade_counts?.residue ?? {})) residue += n
  }
  return { identity, residue }
})

function counts(record: Record<string, number> | undefined): Array<[string, number]> {
  return Object.entries(record ?? {}).filter(([, n]) => n > 0)
}

/**
 * Co se pod tím klíčem skrývá, česky.
 *
 * Why: rozpis dopadu vypisoval klíče tak, jak přijdou z API — u zbytků jsou to
 * rovnou NÁZVY DATABÁZOVÝCH TABULEK (`payroll_jmhz_eldp_evidence_snapshots`).
 * U nevratného úkonu je to ta nejhorší možná forma: schvalující má potvrdit
 * rozsah, kterému nerozumí. Neznámý klíč se dál ukáže tak, jak je — pořád je
 * to lepší než prázdno, a nový klíč tak nezmizí bez povšimnutí.
 */
function cascadeLabel(key: string): string {
  const path = `payroll.erasure.cascade.${key}`
  return te(path) ? t(path) : key
}

/**
 * Co chybí k provedení. Tlačítko je vypnuté ze dvou nezávislých důvodů
 * (zaškrtnutí, opsané číslo) a dřív o žádném z nich neřeklo ani slovo —
 * u kroku, který se nedá vzít zpět, je to nejhorší místo na hádanku.
 */
const executeBlockedReason = computed<string | null>(() => {
  if (!confirmExecute.acknowledged) return t('payroll.erasure.confirm_missing_ack')
  if (selectedId.value === null) return t('payroll.erasure.confirm_missing_id')
  if (confirmExecute.typedId.trim() === '') return t('payroll.erasure.confirm_missing_id')
  if (confirmExecute.typedId.trim() !== String(selectedId.value)) {
    return t('payroll.erasure.confirm_wrong_id', { id: selectedId.value })
  }
  return null
})

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'propose',
    label: t('payroll.erasure.action_propose'),
    icon: 'plus',
    tier: 'primary',
    variant: 'primary',
    show: canWrite.value,
    disabled: busy.value || loading.value,
    loading: busy.value,
    title: t('payroll.erasure.action_propose_hint'),
    run: createProposal,
  },
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'secondary',
    variant: 'neutral',
    disabled: loading.value,
    run: load,
  },
  {
    key: 'retention',
    label: t('nav.payroll_retention'),
    icon: 'archive',
    tier: 'secondary',
    variant: 'neutral',
    show: auth.canRead('payroll.retention'),
    title: t('payroll.erasure.action_retention_hint'),
    to: '/payroll/retention',
  },
])

onMounted(load)
</script>

<template>
  <div class="max-w-6xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('payroll.erasure.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('payroll.erasure.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-danger-50 border border-danger-500/40 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-danger-600 mb-1">{{ t('payroll.erasure.explainer_title') }}</p>
      <p>{{ t('payroll.erasure.explainer_body') }}</p>
      <p class="mt-1.5 text-xs text-neutral-600">{{ t('payroll.erasure.explainer_audit') }}</p>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <label class="flex items-center gap-2 text-xs text-neutral-500 flex-wrap">
        {{ t('payroll.erasure.as_of') }}
        <input
          v-model="asOf"
          type="date"
          data-test="erasure-as-of"
          class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
        />
        <span class="text-neutral-400">{{ t('payroll.erasure.as_of_hint') }}</span>
      </label>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState
      v-else-if="failed && proposals.length === 0"
      boxed
      variant="failed"
      accent="danger"
      :title="t('payroll.erasure.load_failed')"
      :message="error"
      :cta="t('common.refresh')"
      @action="load"
    />

    <EmptyState
      v-else-if="proposals.length === 0"
      boxed
      accent="neutral"
      icon="archive"
      :title="t('payroll.erasure.empty')"
      :message="t('payroll.erasure.empty_hint')"
    />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="flex items-center justify-end gap-2 border-b border-neutral-200 px-3 py-2">
        <ColumnPicker :ctrl="logTbl" />
        <DensityToggle :ctrl="logTbl" />
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="logTbl.densityClass.value">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th v-if="logTbl.isVisible('number')" class="px-3 py-2 text-left text-xs font-medium">{{ t('payroll.erasure.col.number') }}</th>
              <th v-if="logTbl.isVisible('as_of')" class="px-3 py-2 text-left text-xs font-medium whitespace-nowrap">{{ t('payroll.erasure.col.as_of') }}</th>
              <th v-if="logTbl.isVisible('status')" class="px-3 py-2 text-left text-xs font-medium">{{ t('payroll.erasure.col.status') }}</th>
              <th v-if="logTbl.isVisible('people')" class="px-3 py-2 text-left text-xs font-medium">{{ t('payroll.erasure.col.people') }}</th>
              <th v-if="logTbl.isVisible('created_at')" class="px-3 py-2 text-left text-xs font-medium whitespace-nowrap">{{ t('payroll.erasure.col.created_at') }}</th>
              <th v-if="logTbl.isVisible('executed_at')" class="px-3 py-2 text-left text-xs font-medium whitespace-nowrap">{{ t('payroll.erasure.col.executed_at') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="p in proposals"
              :key="p.id"
              :data-test="`erasure-proposal-${p.id}`"
              class="cursor-pointer hover:bg-neutral-50"
              :class="selectedId === p.id ? 'bg-primary-50' : ''"
              @click="openDetail(p.id)"
            >
              <td v-if="logTbl.isVisible('number')" class="px-3 py-2 font-mono font-semibold">#{{ p.id }}</td>
              <td v-if="logTbl.isVisible('as_of')" class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(p.as_of) }}</td>
              <td v-if="logTbl.isVisible('status')" class="px-3 py-2">
                <span
                  class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                  :class="STATUS_BADGE[p.status] ?? 'bg-neutral-200 text-neutral-600'"
                >{{ t(`payroll.erasure.status.${p.status}`) }}</span>
              </td>
              <td v-if="logTbl.isVisible('people')" class="px-3 py-2 font-mono">{{ p.item_count ?? '—' }}</td>
              <td v-if="logTbl.isVisible('created_at')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ fmtDate(p.created_at) }}</td>
              <td v-if="logTbl.isVisible('executed_at')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ fmtDate(p.executed_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail návrhu: co přesně se stane a s KÝM. -->
    <div v-if="selectedId !== null" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
        <h2 class="text-sm font-semibold">{{ t('payroll.erasure.detail_title', { id: selectedId }) }}</h2>
        <div v-if="canWrite && detail" class="flex items-center gap-2 flex-wrap">
          <button
            v-if="status === 'pending'"
            type="button"
            data-test="erasure-approve"
            class="cursor-pointer whitespace-nowrap h-8 px-3 border border-success-500/50 text-success-600 rounded-md text-xs hover:bg-success-50 disabled:opacity-50"
            :disabled="busy"
            @click="approve"
          >{{ t('payroll.erasure.action_approve') }}</button>
          <button
            v-if="status === 'pending'"
            type="button"
            data-test="erasure-reject"
            class="cursor-pointer whitespace-nowrap h-8 px-3 border border-neutral-300 text-neutral-700 rounded-md text-xs hover:bg-neutral-50 disabled:opacity-50"
            :disabled="busy"
            @click="reject"
          >{{ t('payroll.erasure.action_reject') }}</button>
          <button
            v-if="status === 'approved'"
            type="button"
            data-test="erasure-execute"
            class="cursor-pointer whitespace-nowrap h-8 px-3 bg-danger-600 hover:bg-danger-700 text-white rounded-md text-xs disabled:opacity-50"
            :disabled="busy"
            @click="openExecute"
          >{{ t('payroll.erasure.action_execute') }}</button>
        </div>
      </div>

      <div v-if="detailLoading" class="p-6 text-center text-neutral-400 text-sm">{{ t('common.loading') }}</div>

      <EmptyState
        v-else-if="detailError"
        dense
        variant="failed"
        accent="danger"
        :title="t('payroll.erasure.detail_failed')"
        :message="detailError"
        :cta="t('common.refresh')"
        @action="openDetail(selectedId)"
      />

      <div v-else-if="detail" class="p-4 space-y-3">
        <p v-if="status === 'pending'" class="text-xs text-warning-700" data-test="erasure-state-hint">
          {{ t('payroll.erasure.state_pending') }}
        </p>
        <p v-else-if="status === 'approved'" class="text-xs text-danger-600" data-test="erasure-state-hint">
          {{ t('payroll.erasure.state_approved') }}
        </p>
        <p v-else-if="status === 'executed'" class="text-xs text-neutral-600" data-test="erasure-state-hint">
          {{ t('payroll.erasure.state_executed') }}
        </p>
        <p v-else-if="status === 'rejected'" class="text-xs text-neutral-600" data-test="erasure-state-hint">
          {{ t('payroll.erasure.state_rejected') }}
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="border border-neutral-200 rounded-lg p-3">
            <div class="text-2xl font-semibold leading-tight">{{ items.length }}</div>
            <div class="text-xs text-neutral-600">{{ t('payroll.erasure.tile_people') }}</div>
          </div>
          <div class="border border-danger-500/40 bg-danger-50 rounded-lg p-3" data-test="erasure-tile-identity">
            <div class="text-2xl font-semibold leading-tight text-danger-600">{{ impact.identity }}</div>
            <div class="text-xs text-neutral-600">{{ t('payroll.erasure.tile_identity') }}</div>
          </div>
          <div class="border border-warning-500/40 bg-warning-50 rounded-lg p-3" data-test="erasure-tile-residue">
            <div class="text-2xl font-semibold leading-tight text-warning-700">{{ impact.residue }}</div>
            <div class="text-xs text-neutral-600">{{ t('payroll.erasure.tile_residue') }}</div>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2">
          <ColumnPicker :ctrl="candidatesTbl" />
          <DensityToggle :ctrl="candidatesTbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs" :class="candidatesTbl.densityClass.value">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th v-if="candidatesTbl.isVisible('person')" class="px-3 py-2 text-left font-medium">{{ t('payroll.erasure.col.person') }}</th>
                <th v-if="candidatesTbl.isVisible('action')" class="px-3 py-2 text-left font-medium">{{ t('payroll.erasure.col.action') }}</th>
                <th v-if="candidatesTbl.isVisible('source')" class="px-3 py-2 text-left font-medium">{{ t('payroll.erasure.col.source') }}</th>
                <th v-if="candidatesTbl.isVisible('retained_until')" class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('payroll.erasure.col.retained_until') }}</th>
                <th v-if="candidatesTbl.isVisible('impact')" class="px-3 py-2 text-left font-medium">{{ t('payroll.erasure.col.impact') }}</th>
                <th v-if="candidatesTbl.isVisible('outcome')" class="px-3 py-2 text-left font-medium">{{ t('payroll.erasure.col.outcome') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in items" :key="item.id" :data-test="`erasure-item-${item.employee_id}`">
                <td v-if="candidatesTbl.isVisible('person')" class="px-3 py-2">
                  <span v-if="item.full_name" class="font-medium">{{ item.full_name }}</span>
                  <!-- Prázdné jméno po provedeném výmazu není chyba: osoba už
                       neexistuje. Musí to ale být napsané, ne jen pomlčka. -->
                  <span v-else class="text-neutral-400 italic">{{ t('payroll.erasure.person_erased') }}</span>
                </td>
                <td v-if="candidatesTbl.isVisible('action')" class="px-3 py-2">
                  <span
                    class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                    :class="item.action === 'erase' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-700'"
                  >{{ t(`payroll.erasure.action_kind.${item.action}`) }}</span>
                </td>
                <td v-if="candidatesTbl.isVisible('source')" class="px-3 py-2">{{ item.governing_source }}</td>
                <td v-if="candidatesTbl.isVisible('retained_until')" class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(item.retained_until) }}</td>
                <td v-if="candidatesTbl.isVisible('impact')" class="px-3 py-2">
                  <div v-if="counts(item.cascade_counts?.identity).length === 0" class="text-neutral-400">—</div>
                  <div v-else class="space-y-0.5">
                    <div v-for="[key, n] in counts(item.cascade_counts?.identity)" :key="key">
                      <span class="font-mono">{{ n }}×</span> {{ cascadeLabel(key) }}
                    </div>
                  </div>
                  <div
                    v-if="counts(item.cascade_counts?.residue).length > 0"
                    class="mt-1 text-warning-700"
                    :data-test="`erasure-residue-${item.employee_id}`"
                  >
                    {{ t('payroll.erasure.residue_label') }}:
                    <span v-for="[key, n] in counts(item.cascade_counts?.residue)" :key="key" class="mr-1">
                      <span class="font-mono">{{ n }}×</span> {{ cascadeLabel(key) }}
                    </span>
                  </div>
                </td>
                <td v-if="candidatesTbl.isVisible('outcome')" class="px-3 py-2">
                  <span
                    class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                    :class="OUTCOME_BADGE[item.outcome] ?? 'bg-neutral-200 text-neutral-600'"
                  >{{ t(`payroll.erasure.outcome.${item.outcome}`) }}</span>
                  <div v-if="item.skip_reason" class="text-neutral-500 mt-0.5">{{ item.skip_reason }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="executeSummary" class="text-xs text-neutral-600" data-test="erasure-summary">
          {{ t('payroll.erasure.summary', {
            done: executeSummary.done,
            hold: executeSummary.skipped_hold,
            changed: executeSummary.skipped_changed,
          }) }}
        </div>
      </div>
    </div>

    <!-- Provedení: dvě nezávislá potvrzení, ne jeden klik. -->
    <Modal
      v-if="confirmExecute.open"
      :title="t('payroll.erasure.confirm_title')"
      width-class="max-w-lg"
      @close="confirmExecute.open = false"
    >
      <div class="space-y-3 text-sm">
        <div class="bg-danger-50 border border-danger-500/40 rounded-md p-3 text-danger-600">
          {{ t('payroll.erasure.confirm_warning', { count: items.length }) }}
        </div>

        <ul class="text-xs border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-48 overflow-y-auto">
          <li v-for="item in items" :key="item.id" class="px-3 py-1.5 flex justify-between gap-3">
            <span>{{ item.full_name || t('payroll.erasure.person_erased') }}</span>
            <span class="text-neutral-500 shrink-0">{{ t(`payroll.erasure.action_kind.${item.action}`) }}</span>
          </li>
        </ul>

        <p class="text-xs text-neutral-600">{{ t('payroll.erasure.confirm_audit') }}</p>

        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input
            v-model="confirmExecute.acknowledged"
            type="checkbox"
            data-test="erasure-confirm-ack"
            class="mt-0.5 h-3.5 w-3.5 rounded border-neutral-300 text-danger-600"
          />
          {{ t('payroll.erasure.confirm_ack') }}
        </label>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-erasure-confirm-id`">
            {{ t('payroll.erasure.confirm_type_id', { id: selectedId }) }}
          </label>
          <input
            :id="`${pageId}-erasure-confirm-id`"
            v-model="confirmExecute.typedId"
            type="text"
            inputmode="numeric"
            data-test="erasure-confirm-id"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>

        <!-- Vypnuté tlačítko musí říct, CO mu chybí. Pojistky zůstávají obě,
             jen se přestaly tvářit jako rozbité tlačítko. -->
        <p
          v-if="executeBlockedReason"
          class="text-xs text-neutral-600"
          data-test="erasure-confirm-blocked"
        >{{ executeBlockedReason }}</p>

        <div class="flex justify-end gap-2 pt-2 flex-wrap">
          <button
            type="button"
            class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm"
            @click="confirmExecute.open = false"
          >{{ t('common.cancel') }}</button>
          <button
            type="button"
            data-test="erasure-confirm-run"
            class="cursor-pointer h-9 px-4 bg-danger-600 hover:bg-danger-700 text-white rounded-md text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="!executeReady || busy"
            :title="executeBlockedReason ?? undefined"
            @click="execute"
          >{{ busy ? t('common.saving') : t('payroll.erasure.action_execute') }}</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
