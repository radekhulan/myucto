<script setup lang="ts">
/**
 * Retenční lhůty mzdové agendy — čtecí pohled na `PayrollRetentionCatalog`.
 *
 * Obrazovka odpovídá na čtyři otázky, které katalog dosud uměl jen v kódu:
 * jak dlouho se která skupina mzdových dat drží, ODKDY lhůta běží, KDE to
 * stojí psané (konkrétní ustanovení, ne jen číslo zákona) a KDY se to naposledy
 * ověřilo proti znění předpisu.
 *
 * Nejdůležitější sdělení stránky není číslo, ale PŮVOD lhůty. Zdravotní
 * pojištění drží deset let, které v žádné sbírce nestojí (v zák. č. 592/1992 Sb.
 * uschovávací lhůta prostě není) — je to rozhodnutí aplikace, ne právo. Spis
 * k exekučním srážkám lhůtu nemá vůbec a je to doložené NEGATIVNĚ, ne
 * nedohledané. Obojí je rozdíl mezi „takhle to káže zákon" a „takhle jsme se
 * rozhodli", takže se ukazuje jako sloupec a jako dlaždice nad tabulkou,
 * ne jako poznámka schovaná v rozbaleném detailu.
 *
 * Nic se odsud NEMAŽE. Nastavit jde ale dvojí, protože obojí je vstup výpočtu
 * a bez UI se dalo změnit jen přes API: ODCHYLKA FIRMY od katalogové lhůty
 * (jen nahoru — zkrácení odmítá doména, ne formulář) a ZADRŽENÍ VÝMAZU konkrétní
 * osoby. Samotný výmaz je samostatný návrh ke schválení na vlastní obrazovce
 * (oprávnění `payroll.erasure`); stránka na ni odkazuje a referuje o dopadu.
 *
 * Filtrování i řazení běží na klientovi nad celým katalogem (deset kategorií),
 * takže se nestránkuje vůbec — půlka na klientovi a půlka na serveru by
 * schovala řádky, které filtr našel.
 */
import { ref, reactive, computed, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import PayrollPersonSearchSelect, {
  type PayrollPersonSearchOption,
} from '@/components/payroll/PayrollPersonSearchSelect.vue'
import {
  payrollRetentionApi,
  PAYROLL_RETENTION_HOLD_REASONS,
  type PayrollRetentionCategory,
  type PayrollRetentionPolicy,
  type PayrollRetentionAssessment,
  type PayrollRetentionAssessmentItem,
  type PayrollRetentionBlock,
  type PayrollRetentionHold,
  type PayrollRetentionHoldReason,
  type RetentionOrigin,
} from '@/api/payrollRetention'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { appIsoDate } from '@/utils/date'

const { t, locale } = useI18n()
const pageId = useId()
const auth = useAuthStore()
const toast = useToast()

/** Odchylku i zadržení pouští TÁŽ brána jako API (`payroll.retention` zápis). */
const canWrite = computed(() => auth.canWrite('payroll.retention'))

const categories = ref<PayrollRetentionCategory[]>([])
const policies = ref<PayrollRetentionPolicy[]>([])
const loading = ref(true)
/** Katalog se nenačetl — o obsahu nevíme NIC, takže se nesmí ukázat prázdno. */
const failed = ref(false)
const error = ref('')

const assessment = ref<PayrollRetentionAssessment | null>(null)
const assessmentLoading = ref(true)
const assessmentFailed = ref(false)
const assessmentError = ref('')
const asOf = ref(appIsoDate())

const filters = reactive({ q: '', origin: '' as '' | RetentionOrigin })

const ORIGINS: RetentionOrigin[] = ['statute', 'house_policy', 'none']

const ORIGIN_BADGE: Record<RetentionOrigin, string> = {
  statute: 'bg-success-100 text-success-700',
  house_policy: 'bg-warning-100 text-warning-700',
  none: 'bg-neutral-200 text-neutral-600',
}
const ORIGIN_TILE: Record<RetentionOrigin, string> = {
  statute: 'border-success-500/40 bg-success-50',
  house_policy: 'border-warning-500/40 bg-warning-50',
  none: 'border-neutral-300 bg-neutral-50',
}
const STATUS_BADGE: Record<string, string> = {
  statute_verified: 'bg-success-100 text-success-700',
  statute_silent: 'bg-primary-100 text-primary-700',
  external_unverified: 'bg-warning-100 text-warning-700',
  undetermined: 'bg-neutral-200 text-neutral-600',
}

const COLUMNS: ColumnDef[] = [
  { key: 'label', labelKey: 'payroll.retention.col.category', required: true, sortable: true },
  { key: 'years', labelKey: 'payroll.retention.col.years', required: true, sortable: true },
  { key: 'basis', labelKey: 'payroll.retention.col.basis' },
  { key: 'origin', labelKey: 'payroll.retention.col.origin', sortable: true },
  { key: 'source', labelKey: 'payroll.retention.col.source' },
  { key: 'status', labelKey: 'payroll.retention.col.status' },
  { key: 'verified', labelKey: 'payroll.retention.col.verified_on' },
  { key: 'erasure', labelKey: 'payroll.retention.col.erasure' },
  { key: 'tables', labelKey: 'payroll.retention.col.tables', defaultHidden: true },
  { key: 'accounting', labelKey: 'payroll.retention.col.accounting', defaultHidden: true },
]
const tbl = useTablePrefs('payroll-retention', COLUMNS)
// Rozbalený detail se roztahuje pod celou šířku tabulky, takže colspan musí
// dopočítat skryté sloupce i sloupec akcí — natvrdo zapsané číslo se po
// skrytí sloupce rozjelo o buňku.
const detailColspan = computed(
  () => COLUMNS.filter(column => tbl.isVisible(column.key)).length + (canWrite.value ? 1 : 0),
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await payrollRetentionApi.overview()
    categories.value = data.categories
    policies.value = data.policies
    failed.value = false
  } catch (e) {
    // Kolekce se ZÁMĚRNĚ nevynuluje — poslední načtený katalog je pořád lepší
    // informace než prázdná tabulka, která by tvrdila, že žádné lhůty nejsou.
    error.value = apiErrorMessage(e)
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadAssessment() {
  assessmentLoading.value = true
  assessmentError.value = ''
  try {
    assessment.value = await payrollRetentionApi.assessment(asOf.value)
    assessmentFailed.value = false
  } catch (e) {
    assessmentError.value = apiErrorMessage(e)
    assessmentFailed.value = true
  } finally {
    assessmentLoading.value = false
  }
}

// ── Zadržení výmazu (§ 32 ZoÚ + mzdové důvody) ──────────────────────────────
const holds = ref<PayrollRetentionHold[]>([])
const holdsIncludeReleased = ref(false)
const holdsError = ref('')

async function loadHolds() {
  holdsError.value = ''
  try {
    holds.value = await payrollRetentionApi.holds(holdsIncludeReleased.value)
  } catch (e) {
    holdsError.value = apiErrorMessage(e)
  }
}

function reloadAll() {
  void load()
  void loadAssessment()
  void loadHolds()
}

const policyByCategory = computed<Record<string, PayrollRetentionPolicy>>(() => {
  const out: Record<string, PayrollRetentionPolicy> = {}
  for (const p of policies.value) out[p.category] = p
  return out
})

const originCounts = computed<Record<RetentionOrigin, number>>(() => {
  const out: Record<RetentionOrigin, number> = { statute: 0, house_policy: 0, none: 0 }
  for (const c of categories.value) out[c.origin]++
  return out
})

const verifiedOn = computed<string | null>(() => {
  for (const c of categories.value) if (c.verified_on) return c.verified_on
  return null
})

const filtered = computed<PayrollRetentionCategory[]>(() => {
  const q = filters.q.trim().toLocaleLowerCase(locale.value === 'en' ? 'en' : 'cs')
  return categories.value.filter(c => {
    if (filters.origin && c.origin !== filters.origin) return false
    if (!q) return true
    return [c.label, c.act, c.section ?? '', c.source, c.note, ...c.employee_tables, ...c.employment_tables]
      .join(' ')
      .toLocaleLowerCase(locale.value === 'en' ? 'en' : 'cs')
      .includes(q)
  })
})

const sorted = computed<PayrollRetentionCategory[]>(() => {
  const s = tbl.sort.value
  if (!s) return filtered.value
  const dir = s.dir === 'desc' ? -1 : 1
  return [...filtered.value].sort((a, b) => {
    if (s.key === 'years') {
      // Neurčená lhůta není nula — řadí se vždy na konec, ať se třídí jakkoliv.
      const av = a.effective_years ?? Number.POSITIVE_INFINITY
      const bv = b.effective_years ?? Number.POSITIVE_INFINITY
      return (av - bv) * dir
    }
    const av = s.key === 'origin' ? a.origin : a.label
    const bv = s.key === 'origin' ? b.origin : b.label
    return av.localeCompare(bv, locale.value === 'en' ? 'en' : 'cs') * dir
  })
})

function resetFilters() {
  filters.q = ''
  filters.origin = ''
}

function toggleOrigin(origin: RetentionOrigin) {
  filters.origin = filters.origin === origin ? '' : origin
}

const expanded = ref<string | null>(null)
function toggleRow(category: string) {
  expanded.value = expanded.value === category ? null : category
}

/**
 * Lhůta po započtení odchylky firmy — číslo, podle kterého se opravdu počítá.
 *
 * Tvar se skládá ručně ze tří klíčů, ne přes vestavěné množné číslo vue-i18n:
 * to má napevno anglický dvoutvar, takže by ze stejnopisů ELDP udělalo „3 let".
 */
function yearsLabel(c: PayrollRetentionCategory): string {
  const years = c.effective_years
  if (years === null) return t('payroll.retention.years_undetermined')
  const form = years === 1 ? 'one' : years >= 2 && years <= 4 ? 'few' : 'many'
  return t(`payroll.retention.years_count_${form}`, { years })
}

/** Odchylka firmy — prodloužení zákonné lhůty, nebo lhůta dodaná tam, kde zákon mlčí. */
function deviation(c: PayrollRetentionCategory): string | null {
  const p = policyByCategory.value[c.category]
  if (!p) return null
  return p.override_years !== null
    ? t('payroll.retention.deviation_override', { years: p.override_years })
    : t('payroll.retention.deviation_extra', { years: p.extra_years })
}

function tablesOf(c: PayrollRetentionCategory): string[] {
  return [...c.employee_tables, ...c.employment_tables]
}

const BLOCKS: PayrollRetentionBlock[] = [
  'within_retention',
  'legal_hold',
  'undetermined_retention',
  'no_retention_basis',
  'already_anonymized',
]

const blockCounts = computed<Record<string, number>>(() => {
  const out: Record<string, number> = {}
  for (const b of BLOCKS) out[b] = 0
  for (const i of assessment.value?.items ?? []) {
    if (i.blocked_by) out[i.blocked_by] = (out[i.blocked_by] ?? 0) + 1
  }
  return out
})

/**
 * Osoby, kterým lhůta uplynula a nic je nedrží — JMENOVITĚ.
 *
 * Souhrn „3 osoby k výmazu" se nedá zkontrolovat: schvalující nevidí, jestli
 * mezi nimi není někdo, o kom ví něco, co v datech není. Proto seznam jmen,
 * ne jen číslo.
 */
const proposablePeople = computed<PayrollRetentionAssessmentItem[]>(() =>
  (assessment.value?.items ?? []).filter(i => i.proposable),
)

/** Osoby držené zadržením — druhá polovina téhož: kdo je zadržený a proč. */
const heldPeople = computed<PayrollRetentionAssessmentItem[]>(() =>
  (assessment.value?.items ?? []).filter(i => i.blocked_by === 'legal_hold'),
)

// ── Odchylka firmy od katalogové lhůty ──────────────────────────────────────
// Jeden formulář = jedno Uložit. Zkrácení pod zákonné minimum se tu ani
// nevaliduje: je to doménová invarianta, kterou drží server, a duplikát
// pravidla ve formuláři by se s ní časem rozešel. Formulář jen nenabídne
// vlastní lhůtu tam, kde ji katalog má — server by ji stejně odmítl.
const policyForm = reactive({
  open: false,
  saving: false,
  category: '',
  label: '',
  determined: true,
  statutoryYears: null as number | null,
  extraYears: 0,
  overrideYears: null as number | null,
  reason: '',
  existing: false,
})

function openPolicy(c: PayrollRetentionCategory) {
  const p = policyByCategory.value[c.category]
  policyForm.open = true
  policyForm.saving = false
  policyForm.category = c.category
  policyForm.label = c.label
  policyForm.determined = c.retention_years !== null
  policyForm.statutoryYears = c.retention_years
  policyForm.extraYears = p?.extra_years ?? 0
  policyForm.overrideYears = p?.override_years ?? null
  policyForm.reason = p?.reason ?? ''
  policyForm.existing = p !== undefined
}

async function savePolicy() {
  policyForm.saving = true
  try {
    await payrollRetentionApi.putPolicy(policyForm.category, {
      extra_years: Number(policyForm.extraYears) || 0,
      override_years: policyForm.determined ? null : policyForm.overrideYears,
      reason: policyForm.reason,
    })
    policyForm.open = false
    toast.success(t('payroll.retention.policy_saved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    policyForm.saving = false
  }
}

/**
 * Zrušení odchylky bez dialogu, ale s možností vzít to zpět.
 *
 * Odchylka je pouhé nastavení: zrušením se nic nesmaže ani nezpřístupní k
 * výmazu, jen se lhůta vrátí na katalogovou hodnotu. Vrácení je doslovné —
 * `putPolicy()` je upsert, takže se zapíšou tytéž tři hodnoty, které tu byly.
 * Proto tady dialog jen zdržoval; vratná věc si ho nezaslouží.
 */
async function deletePolicy() {
  const restored = {
    category: policyForm.category,
    label: policyForm.label,
    extra_years: Number(policyForm.extraYears) || 0,
    override_years: policyForm.determined ? null : policyForm.overrideYears,
    reason: policyForm.reason,
  }
  policyForm.saving = true
  try {
    await payrollRetentionApi.deletePolicy(restored.category)
    policyForm.open = false
    toast.success(
      t('payroll.retention.policy_deleted_named', { label: restored.label }),
      { label: t('common.undo'), handler: () => void restorePolicy(restored) },
    )
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    policyForm.saving = false
  }
}

async function restorePolicy(policy: {
  category: string
  label: string
  extra_years: number
  override_years: number | null
  reason: string
}) {
  try {
    await payrollRetentionApi.putPolicy(policy.category, {
      extra_years: policy.extra_years,
      override_years: policy.override_years,
      reason: policy.reason,
    })
    toast.success(t('payroll.retention.policy_restored', { label: policy.label }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── Zadržení konkrétní osoby ────────────────────────────────────────────────
const holdForm = reactive({
  open: false,
  saving: false,
  employeeId: null as number | null,
  reason: 'enforcement' as PayrollRetentionHoldReason,
  description: '',
  placedOn: appIsoDate(),
})

function openHold(employeeId: number | null = null) {
  holdForm.open = true
  holdForm.saving = false
  holdForm.employeeId = employeeId
  holdForm.reason = 'enforcement'
  holdForm.description = ''
  holdForm.placedOn = appIsoDate()
}

async function saveHold() {
  const employeeId = holdForm.employeeId
  if (employeeId === null || employeeId <= 0) {
    toast.error(t('payroll.retention.hold_person_required'))
    return
  }
  if (holdForm.description.trim() === '') {
    toast.error(t('payroll.retention.hold_description_required'))
    return
  }
  holdForm.saving = true
  try {
    await payrollRetentionApi.placeHold({
      employee_id: employeeId,
      reason: holdForm.reason,
      description: holdForm.description.trim(),
      placed_on: holdForm.placedOn,
    })
    holdForm.open = false
    toast.success(t('payroll.retention.hold_placed'))
    await Promise.all([loadHolds(), loadAssessment()])
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    holdForm.saving = false
  }
}

/**
 * Uvolnění výmaz zase PUSTÍ — proto se potvrzuje. Není to úklid seznamu,
 * ale rozhodnutí, že důvod zadržení pominul. Undo toast by tady byl slib, který
 * aplikace neudrží: mezi uvolněním a vrácením může výmaz proběhnout.
 *
 * Dialog ale pojmenuje, koho a čeho se to týká — firemní zadržení a zadržení
 * konkrétní osoby stojí ve stejném seznamu a záměna má opačné následky.
 */
async function releaseHold(hold: PayrollRetentionHold) {
  const subject = hold.subject_kind === 'company'
    ? t('payroll.retention.hold_subject_company')
    : (hold.employee_full_name || t('payroll.retention.person_gone'))
  if (!confirm(t('payroll.retention.hold_release_confirm', {
    subject,
    reason: t(`payroll.retention.hold_reason.${hold.reason}`),
  }))) return
  try {
    await payrollRetentionApi.releaseHold(hold.id)
    toast.success(t('payroll.retention.hold_released'))
    await Promise.all([loadHolds(), loadAssessment()])
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function toggleReleased() {
  holdsIncludeReleased.value = !holdsIncludeReleased.value
  await loadHolds()
}

/** Osoby do výběru zadržení — z posudku, ne ze seznamu osob: posudek stojí
 *  na `payroll.retention`, takže výběr nevyžaduje druhé oprávnění. */
const holdCandidates = computed<PayrollRetentionAssessmentItem[]>(() =>
  [...(assessment.value?.items ?? [])].sort(
    (a, b) => a.full_name.localeCompare(b.full_name, locale.value === 'en' ? 'en' : 'cs'),
  ),
)
const holdCandidateOptions = computed<PayrollPersonSearchOption[]>(() =>
  holdCandidates.value.map(person => ({
    value: person.employee_id,
    label: person.full_name,
  })),
)

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'primary',
    variant: 'primary',
    disabled: loading.value,
    loading: loading.value,
    run: reloadAll,
  },
  {
    key: 'erasure',
    label: t('nav.payroll_erasure'),
    icon: 'trash',
    tier: 'secondary',
    variant: 'danger',
    show: auth.canRead('payroll.erasure'),
    title: t('payroll.retention.action_erasure_hint'),
    to: '/payroll/erasure',
  },
  {
    key: 'hold',
    label: t('payroll.retention.action_hold'),
    icon: 'lock',
    tier: 'secondary',
    variant: 'warning',
    show: canWrite.value,
    disabled: assessmentLoading.value,
    title: t('payroll.retention.action_hold_hint'),
    run: () => openHold(),
  },
  {
    key: 'people',
    label: t('nav.payroll_people'),
    icon: 'user',
    tier: 'overflow',
    variant: 'neutral',
    show: auth.canRead('payroll'),
    to: '/payroll/people',
  },
  {
    key: 'accounting',
    label: t('payroll.retention.action_accounting_retention'),
    icon: 'archive',
    tier: 'overflow',
    variant: 'neutral',
    show: auth.canRead('accounting'),
    title: t('payroll.retention.action_accounting_retention_hint'),
    to: '/accounting/retention',
  },
])

onMounted(reloadAll)
</script>

<template>
  <div class="max-w-6xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('payroll.retention.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('payroll.retention.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('payroll.retention.explainer_title') }}</p>
      <p>{{ t('payroll.retention.explainer_body') }}</p>
      <p v-if="verifiedOn" class="mt-1.5 text-xs text-neutral-600">
        {{ t('payroll.retention.verified_stamp', { date: fmtDate(verifiedOn) }) }}
      </p>
    </div>

    <!-- Původ lhůty jako první věc na stránce: rozdíl mezi zákonem a rozhodnutím
         aplikace nesmí být schovaný v detailu řádku. Dlaždice zároveň filtrují. -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
      <button
        v-for="o in ORIGINS"
        :key="o"
        type="button"
        :aria-pressed="filters.origin === o"
        :data-test="`origin-tile-${o}`"
        class="cursor-pointer text-left border rounded-lg p-3 transition-colors hover:brightness-[0.98]"
        :class="[ORIGIN_TILE[o], filters.origin === o ? 'ring-2 ring-primary-400' : '']"
        @click="toggleOrigin(o)"
      >
        <div class="text-2xl font-semibold leading-tight">{{ originCounts[o] }}</div>
        <div class="text-sm font-medium">{{ t(`payroll.retention.origin.${o}`) }}</div>
        <div class="text-xs text-neutral-600 mt-0.5">{{ t(`payroll.retention.origin_hint.${o}`) }}</div>
      </button>
    </div>

    <!-- Filtr + tabulkové vybavení. Stránkování tu není vůbec — katalog má deset
         kategorií a filtr i řazení běží na klientovi nad celou sadou. -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-retention-q`">
            {{ t('payroll.retention.filter_q') }}
          </label>
          <input
            :id="`${pageId}-retention-q`"
            v-model="filters.q"
            type="text"
            :placeholder="t('payroll.retention.filter_q_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-retention-origin`">
            {{ t('payroll.retention.col.origin') }}
          </label>
          <select
            :id="`${pageId}-retention-origin`"
            v-model="filters.origin"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          >
            <option value="">{{ t('common.all') }}</option>
            <option v-for="o in ORIGINS" :key="o" :value="o">{{ t(`payroll.retention.origin.${o}`) }}</option>
          </select>
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button
          type="button"
          class="cursor-pointer whitespace-nowrap text-xs text-neutral-500 hover:text-neutral-700"
          @click="resetFilters"
        >{{ t('payroll.retention.reset_filters') }}</button>
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- Selhání se NIKDY nekreslí jako prázdný katalog: „žádné lhůty" a „lhůty
         se nenačetly" jsou dva úplně jiné stavy a záměna prvního za druhý by
         tvrdila, že se nic nedrží. -->
    <EmptyState
      v-else-if="failed && categories.length === 0"
      boxed
      variant="failed"
      accent="danger"
      :title="t('payroll.retention.load_failed')"
      :message="error"
      :cta="t('common.refresh')"
      @action="load"
    />

    <EmptyState
      v-else-if="sorted.length === 0"
      boxed
      variant="filtered"
      accent="neutral"
      icon="funnel"
      :title="t('payroll.retention.no_match')"
      :message="t('payroll.retention.no_match_hint')"
      :cta="t('payroll.retention.reset_filters')"
      @action="resetFilters"
    />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="failed" class="px-3 py-2 bg-danger-50 border-b border-danger-500/40 text-xs text-danger-600">
        {{ t('payroll.retention.stale_warning', { error }) }}
      </div>

      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50">
            <tr>
              <SortableTh
                v-if="tbl.isVisible('label')"
                :label="t('payroll.retention.col.category')"
                sort-key="label" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <SortableTh
                v-if="tbl.isVisible('years')"
                :label="t('payroll.retention.col.years')"
                sort-key="years" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <th v-if="tbl.isVisible('basis')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.basis') }}</th>
              <SortableTh
                v-if="tbl.isVisible('origin')"
                :label="t('payroll.retention.col.origin')"
                sort-key="origin" :sort="tbl.sort.value" @toggle="tbl.toggleSort"
              />
              <th v-if="tbl.isVisible('source')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.source') }}</th>
              <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.status') }}</th>
              <th v-if="tbl.isVisible('verified')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.verified_on') }}</th>
              <th v-if="tbl.isVisible('erasure')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.erasure') }}</th>
              <th v-if="tbl.isVisible('tables')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.tables') }}</th>
              <th v-if="tbl.isVisible('accounting')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.retention.col.accounting') }}</th>
              <th v-if="canWrite" class="px-3 py-2 w-28"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="c in sorted" :key="c.category">
              <tr class="cursor-pointer hover:bg-neutral-50" :data-test="`retention-row-${c.category}`" @click="toggleRow(c.category)">
                <td v-if="tbl.isVisible('label')" class="px-3 py-2">
                  <span class="font-medium">{{ c.label }}</span>
                  <span v-if="c.closing_agenda"
                        class="ml-1.5 inline-block text-[10px] font-bold px-1.5 py-px rounded bg-neutral-200 text-neutral-600">
                    {{ t('payroll.retention.closing_agenda') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('years')" class="px-3 py-2 whitespace-nowrap" :data-test="`retention-years-${c.category}`">
                  <span :class="c.effective_years === null ? 'text-neutral-400 italic' : 'font-mono font-semibold'">
                    {{ yearsLabel(c) }}
                  </span>
                  <div v-if="deviation(c)" class="text-[11px] text-warning-700">{{ deviation(c) }}</div>
                </td>
                <td v-if="tbl.isVisible('basis')" class="px-3 py-2 text-xs text-neutral-600">
                  {{ t(`payroll.retention.basis.${c.basis}`) }}
                </td>
                <td v-if="tbl.isVisible('origin')" class="px-3 py-2" :data-test="`retention-origin-${c.category}`">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                        :class="ORIGIN_BADGE[c.origin]">
                    {{ t(`payroll.retention.origin.${c.origin}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('source')" class="px-3 py-2 text-xs" :data-test="`retention-source-${c.category}`">
                  <span :class="c.statutory ? '' : 'text-warning-700'">{{ c.source }}</span>
                </td>
                <td v-if="tbl.isVisible('status')" class="px-3 py-2">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                        :class="STATUS_BADGE[c.source_status] ?? 'bg-neutral-200 text-neutral-600'">
                    {{ t(`payroll.retention.source_status.${c.source_status}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('verified')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                  {{ fmtDate(c.verified_on) }}
                </td>
                <td v-if="tbl.isVisible('erasure')" class="px-3 py-2 text-xs" :data-test="`retention-erasure-${c.category}`">
                  <span :class="c.determined ? 'text-neutral-600' : 'text-neutral-400'">
                    {{ c.determined ? t('payroll.retention.erasure_proposed') : t('payroll.retention.erasure_never') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('tables')" class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">
                  {{ t('payroll.retention.tables_count', { count: tablesOf(c).length }) }}
                </td>
                <td v-if="tbl.isVisible('accounting')" class="px-3 py-2 text-xs">
                  {{ c.accounting_relevant ? t('common.yes') : t('common.no') }}
                </td>
                <td v-if="canWrite" class="px-3 py-2 text-right">
                  <button
                    type="button"
                    class="cursor-pointer whitespace-nowrap text-[11px] text-primary-700 hover:underline"
                    :data-test="`retention-policy-edit-${c.category}`"
                    @click.stop="openPolicy(c)"
                  >{{ t('payroll.retention.action_policy') }}</button>
                </td>
              </tr>
              <tr v-if="expanded === c.category">
                <td :colspan="detailColspan" :data-test="`retention-detail-${c.category}`" class="px-4 py-3 bg-neutral-50/60 text-xs text-neutral-700 space-y-2">
                  <div>
                    <span class="font-semibold">{{ t('payroll.retention.detail_act') }}:</span> {{ c.act }}
                  </div>
                  <div v-if="c.section">
                    <span class="font-semibold">{{ t('payroll.retention.detail_section') }}:</span> {{ c.section }}
                  </div>
                  <div v-if="c.amendment">
                    <span class="font-semibold">{{ t('payroll.retention.detail_amendment') }}:</span> {{ c.amendment }}
                  </div>
                  <div v-if="c.alternative_basis">
                    <span class="font-semibold">{{ t('payroll.retention.detail_alternative_basis') }}:</span>
                    {{ t(`payroll.retention.basis.${c.alternative_basis}`) }}
                    <span class="text-neutral-500">— {{ t('payroll.retention.detail_alternative_basis_hint') }}</span>
                  </div>
                  <div v-if="policyByCategory[c.category]">
                    <span class="font-semibold">{{ t('payroll.retention.detail_policy') }}:</span>
                    {{ policyByCategory[c.category].reason }}
                  </div>
                  <div>
                    <span class="font-semibold">{{ t('payroll.retention.detail_tables') }}:</span>
                    <span v-if="tablesOf(c).length === 0" class="text-neutral-500"> {{ t('payroll.retention.detail_no_tables') }}</span>
                    <span v-else class="font-mono"> {{ tablesOf(c).join(', ') }}</span>
                  </div>
                  <p class="text-neutral-600 whitespace-pre-line">{{ c.note }}</p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobil: karty. Původ a lhůta jsou hlavní sdělení, proto stojí nahoře
           a ne za vodorovným rolováním. -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="c in sorted" :key="c.category" class="p-3" @click="toggleRow(c.category)">
          <div class="flex items-start justify-between gap-2 flex-wrap">
            <div class="font-medium text-sm">{{ c.label }}</div>
            <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                  :class="ORIGIN_BADGE[c.origin]">
              {{ t(`payroll.retention.origin.${c.origin}`) }}
            </span>
          </div>
          <div class="mt-1 text-sm" :class="c.effective_years === null ? 'text-neutral-400 italic' : 'font-mono font-semibold'">
            {{ yearsLabel(c) }}
          </div>
          <div class="text-xs text-neutral-600">{{ t(`payroll.retention.basis.${c.basis}`) }}</div>
          <div class="text-xs mt-1" :class="c.statutory ? 'text-neutral-600' : 'text-warning-700'">{{ c.source }}</div>
          <div v-if="expanded === c.category" class="mt-2 text-xs text-neutral-600 space-y-1">
            <div v-if="c.amendment">{{ c.amendment }}</div>
            <div class="font-mono break-all">{{ tablesOf(c).join(', ') || t('payroll.retention.detail_no_tables') }}</div>
            <p class="whitespace-pre-line">{{ c.note }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Návaznost na výmaz: podle lhůt se nevratně maže, takže obrazovka musí
         ukázat i to, co z nich k dnešnímu dni plyne — a hlavně PROČ se osoba
         nenavrhla. Návrh, který někoho mlčky vynechá, se nedá zkontrolovat. -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
        <h2 class="text-sm font-semibold">{{ t('payroll.retention.erasure_title') }}</h2>
        <label class="flex items-center gap-1.5 text-xs text-neutral-500">
          {{ t('payroll.retention.as_of') }}
          <input
            v-model="asOf"
            type="date"
            class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
            @change="loadAssessment"
          />
        </label>
      </div>

      <div v-if="assessmentLoading" class="p-6 text-center text-neutral-400 text-sm">{{ t('common.loading') }}</div>

      <EmptyState
        v-else-if="assessmentFailed"
        dense
        variant="failed"
        accent="danger"
        :title="t('payroll.retention.assessment_failed')"
        :message="assessmentError"
        :cta="t('common.refresh')"
        @action="loadAssessment"
      />

      <EmptyState
        v-else-if="!assessment || assessment.items.length === 0"
        dense
        accent="neutral"
        icon="user"
        :title="t('payroll.retention.assessment_empty')"
        :message="t('payroll.retention.assessment_empty_hint')"
      />

      <div v-else class="p-4 space-y-3">
        <div class="flex items-baseline gap-2 flex-wrap">
          <span class="text-2xl font-semibold" :class="assessment.proposable > 0 ? 'text-warning-700' : 'text-neutral-500'">
            {{ assessment.proposable }}
          </span>
          <span class="text-sm text-neutral-600">
            {{ t('payroll.retention.proposable_of', { total: assessment.items.length }) }}
          </span>
        </div>
        <p class="text-xs text-neutral-500">{{ t('payroll.retention.erasure_hint') }}</p>

        <ul class="text-xs divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="b in BLOCKS" :key="b" :data-test="`retention-block-${b}`" class="flex items-start justify-between gap-3 px-3 py-1.5">
            <span>
              <span class="font-medium">{{ t(`payroll.retention.block.${b}`) }}</span>
              <span class="block text-neutral-500">{{ t(`payroll.retention.block_hint.${b}`) }}</span>
            </span>
            <span class="font-mono shrink-0" :data-test="`retention-block-count-${b}`" :class="blockCounts[b] ? '' : 'text-neutral-400'">{{ blockCounts[b] }}</span>
          </li>
        </ul>

        <!-- Souhrn nestačí: podle čísla „3 osoby" se nevratný úkon odklepnout
             nedá. Kdo přesně je na řadě a koho drží zadržení, musí být vidět. -->
        <div v-if="proposablePeople.length > 0" data-test="retention-proposable-people">
          <div class="text-xs font-semibold text-neutral-600 mb-1">
            {{ t('payroll.retention.proposable_people') }}
          </div>
          <ul class="text-xs divide-y divide-neutral-100 border border-warning-500/40 rounded-md">
            <li
              v-for="p in proposablePeople"
              :key="p.employee_id"
              :data-test="`retention-proposable-${p.employee_id}`"
              class="flex items-center justify-between gap-3 px-3 py-1.5 flex-wrap"
            >
              <span>
                <span class="font-medium">{{ p.full_name }}</span>
                <span class="text-neutral-500">
                  — {{ t(`payroll.retention.action_kind.${p.action ?? 'anonymize'}`) }},
                  {{ t('payroll.retention.retained_until_label') }} {{ fmtDate(p.retained_until) }}
                </span>
              </span>
              <button
                v-if="canWrite"
                type="button"
                class="cursor-pointer whitespace-nowrap text-[11px] text-warning-700 hover:underline"
                @click="openHold(p.employee_id)"
              >{{ t('payroll.retention.action_hold_person') }}</button>
            </li>
          </ul>
        </div>

        <div v-if="heldPeople.length > 0" data-test="retention-held-people" class="text-xs text-neutral-600">
          <span class="font-semibold">{{ t('payroll.retention.held_people') }}:</span>
          {{ heldPeople.map(p => p.full_name).join(', ') }}
        </div>
      </div>
    </div>

    <!-- Zadržení výmazu. Sdílí tabulku s účetní stranou (§ 32 ZoÚ), ale rozsah
         je osoba — firemní zadržení se zadává v Účetnictví a padá i sem. -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between gap-2 flex-wrap">
        <h2 class="text-sm font-semibold">{{ t('payroll.retention.holds_title') }}</h2>
        <div class="flex items-center gap-3 flex-wrap">
          <label class="flex items-center gap-1.5 text-xs text-neutral-500 cursor-pointer">
            <input
              type="checkbox"
              data-test="retention-holds-released"
              :checked="holdsIncludeReleased"
              class="h-3.5 w-3.5 rounded border-neutral-300 text-primary-600"
              @change="toggleReleased"
            />
            {{ t('payroll.retention.show_released') }}
          </label>
          <button
            v-if="canWrite"
            type="button"
            data-test="retention-hold-new"
            class="cursor-pointer whitespace-nowrap text-xs text-warning-700 hover:underline"
            @click="openHold()"
          >{{ t('payroll.retention.action_hold') }}</button>
        </div>
      </div>

      <div v-if="holdsError" class="px-3 py-2 bg-danger-50 border-b border-danger-500/40 text-xs text-danger-600">
        {{ holdsError }}
      </div>

      <EmptyState
        v-if="holds.length === 0"
        dense
        accent="neutral"
        icon="lock"
        :title="t('payroll.retention.no_holds')"
        :message="t('payroll.retention.no_holds_hint')"
      />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('payroll.retention.col.person') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('payroll.retention.col.reason') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('payroll.retention.col.description') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('payroll.retention.col.placed_on') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('payroll.retention.col.released_on') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="h in holds" :key="h.id" :data-test="`retention-hold-${h.id}`" :class="{ 'opacity-60': h.released_on }">
              <td class="px-3 py-2 font-medium">
                {{ h.employee_full_name || t('payroll.retention.person_gone') }}
              </td>
              <td class="px-3 py-2">
                <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded bg-warning-100 text-warning-700 whitespace-nowrap">
                  {{ t(`payroll.retention.hold_reason.${h.reason}`) }}
                </span>
              </td>
              <td class="px-3 py-2">{{ h.description }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(h.placed_on) }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(h.released_on) }}</td>
              <td class="px-3 py-2 text-right">
                <button
                  v-if="canWrite && !h.released_on"
                  type="button"
                  :data-test="`retention-hold-release-${h.id}`"
                  class="cursor-pointer whitespace-nowrap text-[11px] text-danger-600 hover:underline"
                  @click="releaseHold(h)"
                >{{ t('payroll.retention.action_release') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Odchylka firmy: jeden formulář, jedno Uložit. -->
    <Modal
      v-if="policyForm.open"
      :title="t('payroll.retention.policy_title', { label: policyForm.label })"
      width-class="max-w-lg"
      @close="policyForm.open = false"
    >
      <div class="space-y-3 text-sm">
        <p class="text-xs text-neutral-500">{{ t('payroll.retention.policy_hint') }}</p>

        <div v-if="policyForm.determined" class="bg-neutral-50 border border-neutral-200 rounded-md p-2 text-xs">
          {{ t('payroll.retention.policy_statutory', { years: policyForm.statutoryYears }) }}
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-policy-extra`">
            {{ t('payroll.retention.policy_extra_years') }}
          </label>
          <input
            :id="`${pageId}-policy-extra`"
            v-model.number="policyForm.extraYears"
            type="number"
            min="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>

        <div v-if="!policyForm.determined">
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-policy-override`">
            {{ t('payroll.retention.policy_override_years') }}
          </label>
          <input
            :id="`${pageId}-policy-override`"
            v-model.number="policyForm.overrideYears"
            type="number"
            min="1"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
          <p class="text-[11px] text-neutral-500 mt-1">{{ t('payroll.retention.policy_override_hint') }}</p>
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-policy-reason`">
            {{ t('payroll.retention.policy_reason') }}
          </label>
          <textarea
            :id="`${pageId}-policy-reason`"
            v-model="policyForm.reason"
            rows="3"
            maxlength="500"
            :placeholder="t('payroll.retention.policy_reason_placeholder')"
            class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"
          ></textarea>
        </div>

        <div class="flex justify-between gap-2 pt-2 flex-wrap">
          <button
            v-if="policyForm.existing"
            type="button"
            data-test="retention-policy-delete"
            class="cursor-pointer h-9 px-4 border border-danger-500/50 text-danger-500 rounded-md text-sm hover:bg-danger-50"
            :disabled="policyForm.saving"
            @click="deletePolicy"
          >{{ t('payroll.retention.policy_delete') }}</button>
          <span v-else></span>
          <div class="flex gap-2 flex-wrap">
            <button
              type="button"
              class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm"
              @click="policyForm.open = false"
            >{{ t('common.cancel') }}</button>
            <button
              type="button"
              data-test="retention-policy-save"
              class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm disabled:opacity-50"
              :disabled="policyForm.saving"
              @click="savePolicy"
            >{{ policyForm.saving ? t('common.saving') : t('common.save') }}</button>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Zadržení osoby -->
    <Modal
      v-if="holdForm.open"
      :title="t('payroll.retention.hold_title')"
      width-class="max-w-lg"
      @close="holdForm.open = false"
    >
      <div class="space-y-3 text-sm">
        <p class="text-xs text-neutral-500">{{ t('payroll.retention.hold_hint') }}</p>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-hold-person`">
            {{ t('payroll.retention.col.person') }}
          </label>
          <PayrollPersonSearchSelect
            v-model="holdForm.employeeId"
            :input-id="`${pageId}-hold-person`"
            data-test="retention-hold-person"
            :label="t('payroll.retention.col.person')"
            :placeholder="t('payroll.retention.hold_person_pick')"
            :candidates="holdCandidateOptions"
            :clearable="false"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-hold-reason`">
            {{ t('payroll.retention.col.reason') }}
          </label>
          <select
            :id="`${pageId}-hold-reason`"
            v-model="holdForm.reason"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          >
            <option v-for="r in PAYROLL_RETENTION_HOLD_REASONS" :key="r" :value="r">
              {{ t(`payroll.retention.hold_reason.${r}`) }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-hold-description`">
            {{ t('payroll.retention.col.description') }}
          </label>
          <textarea
            :id="`${pageId}-hold-description`"
            v-model="holdForm.description"
            rows="2"
            maxlength="255"
            :placeholder="t('payroll.retention.hold_description_placeholder')"
            class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"
          ></textarea>
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-hold-placed`">
            {{ t('payroll.retention.col.placed_on') }}
          </label>
          <input
            :id="`${pageId}-hold-placed`"
            v-model="holdForm.placedOn"
            type="date"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>

        <div class="flex justify-end gap-2 pt-2 flex-wrap">
          <button
            type="button"
            class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm"
            @click="holdForm.open = false"
          >{{ t('common.cancel') }}</button>
          <button
            type="button"
            data-test="retention-hold-save"
            class="cursor-pointer h-9 px-4 bg-warning-500 hover:bg-warning-600 text-white rounded-md text-sm disabled:opacity-50"
            :disabled="holdForm.saving"
            @click="saveHold"
          >{{ holdForm.saving ? t('common.saving') : t('payroll.retention.action_hold') }}</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
