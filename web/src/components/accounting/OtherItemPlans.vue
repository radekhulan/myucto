<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemPlansApi, type OtherItemInstallment, type OtherItemInstallmentStatus, type OtherItemSchedule,
  type OtherItemScheduleTitleSettings } from '@/api/otherItemPlans'
import type { OtherItem } from '@/api/otherItems'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'
import Modal from '@/components/ui/Modal.vue'
import OtherItemScheduleTitleFields from '@/components/accounting/OtherItemScheduleTitleFields.vue'
import { scheduleTitleErrorText, type ScheduleTitleError } from '@/utils/otherItemScheduleTitleError'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import { otherItemStatusClass } from '@/utils/otherItemStatus'
import { apiErrorCode } from '@/api/errors'

/** Co detail nabídne v liště akcí: nastavit opakování, rozložit na splátky. */
export interface OtherItemPlansState {
  canSchedule: boolean
  canSplit: boolean
  /** Otevřený dialog opakování nebo splátek: tichá obnova detailu by rozepsané údaje přepsala. */
  editing: boolean
}

const props = defineProps<{ item: OtherItem }>()
const emit = defineEmits<{ (e: 'state', value: OtherItemPlansState): void }>()
const { t, te } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const busy = ref(false)
const loading = ref(false)
const schedule = ref<OtherItemSchedule | null>(null)
const installments = ref<OtherItemInstallment[]>([])
const frequency = ref<OtherItemSchedule['frequency']>('monthly')
const endsOn = ref('')
const through = ref(appIsoDate())
const autoPost = ref(false)
const emptyTitle = (): OtherItemScheduleTitleSettings => ({ title_template: null, title_sync_period: false })
/** Šablona názvu v dialogu založení i úpravy opakování; chyba uložení patří k poli. */
const titleSettings = ref<OtherItemScheduleTitleSettings>(emptyTitle())
const titleError = ref<ScheduleTitleError | null>(null)
// Úprava šablony po neúspěšném uložení: stará hláška u pole už neplatí, aktuální chybu ukáže náhled.
// Na četnosti závisí i délka dosazeného názvu, proto i její změna.
watch([titleSettings, frequency, endsOn], () => { titleError.value = null })
const canAutoPost = computed(() => ['posted', 'confirmed'].includes(props.item.status))
const canPost = computed(() => supplier.currentSupplier?.accounting_mode !== 'double_entry' || auth.canWrite('accounting.journal.post'))
const canWrite = computed(() => auth.canWrite('other_items'))
// Čerstvý rozvrh (openScheduleEdit) může vědět o stornu zdroje dřív než položka v detailu.
const sourceActive = computed(() => ['draft', 'posted', 'confirmed'].includes(props.item.status)
  && schedule.value?.source_active !== false)
/** Rozvrh po konci opakování (i pozastavený): název už žádné položce nepatří. */
const scheduleFinished = computed(() => schedule.value?.finished === true)
/** Název budoucích položek jde změnit jen u aktivního zdroje a neskončeného rozvrhu. */
const canEditTitle = computed(() => canWrite.value && sourceActive.value && !scheduleFinished.value)
const generatedOccurrences = computed(() => schedule.value?.occurrences?.filter(row => row.occurrence_index > 0) || [])
/**
 * Datum poslední už vytvořené položky, i smazané (stornované): po ní začne nová šablona.
 * Denní úloha je tvoří až 90 dní dopředu a změna názvu je nepřejmenuje.
 */
const lastCreatedOn = computed(() => generatedOccurrences.value
  .reduce<string | null>((last, row) => (last === null || row.issued_on > last ? row.issued_on : last), null))
// Kalendář zamyká každá úhrada, i ta, která pokryla jen úrok nebo poplatek (jistina zůstala celá).
const hasPayments = ref(false)
const canEditInstallments = computed(() => canWrite.value && ['draft', 'posted', 'confirmed'].includes(props.item.status)
  && Number(props.item.paid_amount) === 0 && !hasPayments.value)
/** Uložené splátky jsou rozdělené na jistinu, úrok a poplatek (úvěr, leasing). Částka dokladu je pak jistina. */
const components = ref(false)
const loaded = ref(false)
/** Načtení selhalo: místo akcí karta s opakováním, ať nastavení nezmizí beze slova. */
const failed = ref(false)
let loadSeq = 0

/*
 * Jeden vzor pro oba plány: detail ukazuje jen přehled (karta Opakování, tabulka splátek)
 * a úpravy obou běží ve stejném typu dialogu. Nový plán zakládají akce v „…“ detailu (skupina
 * Plány), existující plán tlačítko Upravit v záhlaví jeho karty.
 */
const scheduleOpen = ref(false)
const scheduleMode = ref<'create' | 'edit'>('create')
const installmentsOpen = ref(false)
/** Rozepsaný kalendář v dialogu; uložené splátky v kartě se mění až uložením. */
const draft = ref<OtherItemInstallment[]>([])
const draftComponents = ref(false)
const hasSavedInstallments = computed(() => installments.value.some(row => row.id))
// Vygenerovaná položka nový rozvrh nenabízí: zdroj ukazuje detail nad kartami.
const state = computed<OtherItemPlansState>(() => ({
  canSchedule: loaded.value && !schedule.value && !props.item.occurrence && canWrite.value
    && ['draft', 'posted', 'confirmed'].includes(props.item.status),
  canSplit: loaded.value && !hasSavedInstallments.value && canEditInstallments.value,
  editing: scheduleOpen.value || installmentsOpen.value,
}))
watch(state, value => emit('state', value), { immediate: true })
function openSchedule() {
  if (!state.value.canSchedule) return
  frequency.value = 'monthly'
  endsOn.value = ''
  autoPost.value = false
  titleSettings.value = emptyTitle()
  titleError.value = null
  scheduleMode.value = 'create'
  scheduleOpen.value = true
}
/**
 * Před otevřením úpravy načte rozvrh znovu: denní úloha mezitím mohla vytvořit další položky
 * a dialog ukazuje, do kterého data už vznikly. Odpověď patří jen položce, ze které se kliklo.
 */
async function openScheduleEdit() {
  if (!schedule.value || !canWrite.value || busy.value) return
  const itemId = props.item.id
  busy.value = true
  let fresh: OtherItemSchedule
  try {
    fresh = await otherItemPlansApi.schedule(schedule.value.id)
  } catch (error: any) {
    if (itemId !== props.item.id) return
    toast.error(scheduleTitleErrorText(error?.response?.data?.error || {}, t, te))
    // Rozvrh smazaný v jiné záložce: karta nesmí dál ukazovat neexistující rozvrh. Bez load(),
    // ten by přepsal i rozepsaný splátkový kalendář.
    if (error?.response?.status === 404) schedule.value = null
    return
  } finally {
    busy.value = false
  }
  if (itemId !== props.item.id) return
  schedule.value = fresh
  const template = fresh.template
  titleSettings.value = {
    title_template: typeof template.title_template === 'string' ? template.title_template : null,
    title_sync_period: template.title_sync_period === true,
  }
  titleError.value = null
  scheduleMode.value = 'edit'
  scheduleOpen.value = true
}
/** Chyba s polem formuláře zůstane u pole v dialogu, ostatní jako toast. */
const FIELD_ERRORS = ['title_template', 'title_sync_period', 'frequency', 'ends_on']
const fieldErrorText = (field: string) => titleError.value?.field === field ? scheduleTitleErrorText(titleError.value, t, te) : ''
function showSaveError(error: any) {
  const data = error?.response?.data?.error
  if (FIELD_ERRORS.includes(data?.field)) {
    titleError.value = data
  } else {
    toast.error(scheduleTitleErrorText(data || {}, t, te))
  }
}
function openInstallments() {
  if (!state.value.canSplit) return
  draft.value = []
  draftComponents.value = false
  installmentsOpen.value = true
}
function openInstallmentsEdit() {
  if (!canEditInstallments.value || !hasSavedInstallments.value) return
  draft.value = installments.value.map(row => ({ ...row }))
  draftComponents.value = components.value
  installmentsOpen.value = true
}
function closeInstallments() {
  installmentsOpen.value = false
  draft.value = []
}
defineExpose({ openSchedule, openInstallments })

/** Kód chyby s vlastním překladem (other_items.error.*) má přednost před českou hláškou serveru. */
function errorMessage(error: unknown): string {
  const code = apiErrorCode(error)
  if (code.startsWith('other_items.error.') && te(code)) return t(code)
  return (error as any)?.response?.data?.error?.message || t('common.error')
}

const hasComponents = (rows: OtherItemInstallment[]) => rows.some(row => row.principal_amount !== null && row.principal_amount !== undefined)

async function load() {
  const seq = ++loadSeq
  loading.value = true
  failed.value = false
  splitFirstDue.value = addMonths(props.item.issued_on || appIsoDate(), 1)
  loan.value.first_due_on = splitFirstDue.value
  try {
    const id = Number(props.item.id)
    const [all, rows] = await Promise.all([otherItemPlansApi.schedules(id), otherItemPlansApi.installments(id)])
    const found = all.find(row => Number(row.source_item_id) === id)
    const detail = found ? await otherItemPlansApi.schedule(found.id) : null
    // Rychlé přepnutí položky: pozdní odpověď předchozí položky se nepoužije.
    if (seq !== loadSeq) return
    schedule.value = detail
    installments.value = rows
    hasPayments.value = rows.some(row => Number(row.paid_amount || 0) > 0)
    components.value = hasComponents(rows)
    loaded.value = true
  } catch (error: any) {
    if (seq !== loadSeq) return
    failed.value = true
    toast.error(errorMessage(error))
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

/**
 * Odpověď uložení patří jen položce, na které se ukládalo: po přepnutí na jinou položku se zahodí.
 * Obnova téže položky (tichý refresh detailu) výsledek nezahazuje, uložení by jinak tiše zmizelo.
 */
function staleGuard() {
  const itemId = props.item.id
  return () => itemId !== props.item.id
}

async function createSchedule() {
  if (busy.value) return
  busy.value = true
  const stale = staleGuard()
  try {
    const created = await otherItemPlansApi.createSchedule(Number(props.item.id), {
      frequency: frequency.value, ends_on: endsOn.value || null,
      auto_post: autoPost.value && canAutoPost.value, ...titleSettings.value,
    })
    if (stale()) return
    schedule.value = created
    scheduleOpen.value = false
    toast.success(t('other_items.plans.schedule_saved'))
  } catch (error: any) {
    if (!stale()) showSaveError(error)
  } finally {
    busy.value = false
  }
}

async function saveTitle() {
  if (!schedule.value || busy.value || !canEditTitle.value) return
  busy.value = true
  const stale = staleGuard()
  try {
    const saved = await otherItemPlansApi.setTitle(schedule.value.id, titleSettings.value)
    if (stale()) return
    schedule.value = saved
    scheduleOpen.value = false
    toast.success(t('other_items.plans.title_saved'))
  } catch (error: any) {
    if (!stale()) showSaveError(error)
  } finally {
    busy.value = false
  }
}

async function generate() {
  if (!schedule.value || busy.value || !sourceActive.value) return
  busy.value = true
  try {
    const result = await otherItemPlansApi.generate(schedule.value.id, through.value)
    schedule.value = result.schedule
    toast.success(t('other_items.plans.generated', { count: result.created_ids.length }))
    if (result.posted_ids.length) toast.success(t('other_items.plans.posted', { count: result.posted_ids.length }))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function toggleSchedule() {
  if (!schedule.value || busy.value || !sourceActive.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.setStatus(schedule.value.id,
      schedule.value.status === 'active' ? 'paused' : 'active')
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function toggleAutoPost() {
  if (!schedule.value || busy.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.setStatus(schedule.value.id, schedule.value.status, !schedule.value.auto_post)
    toast.success(t('other_items.plans.schedule_saved'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function deleteSchedule() {
  if (!schedule.value || busy.value || !window.confirm(t('other_items.plans.confirm_delete_schedule'))) return
  busy.value = true
  try {
    await otherItemPlansApi.deleteSchedule(schedule.value.id)
    schedule.value = null
    scheduleOpen.value = false
    toast.success(t('other_items.plans.schedule_deleted'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

function addMonths(iso: string, months: number): string {
  const [year, month, day] = iso.split('-').map(Number)
  const index = month - 1 + months
  const y = year + Math.floor(index / 12)
  const m = index % 12
  const last = new Date(Date.UTC(y, m + 1, 0)).getUTCDate()
  return `${y}-${String(m + 1).padStart(2, '0')}-${String(Math.min(day, last)).padStart(2, '0')}`
}

const splitCount = ref(12)
const splitStep = ref(1)
const splitFirstDue = ref(addMonths(props.item.issued_on || appIsoDate(), 1))
const canSplit = computed(() => Number.isInteger(splitCount.value) && splitCount.value >= 2 && splitCount.value <= 120
  && /^\d{4}-\d{2}-\d{2}$/.test(splitFirstDue.value))

function splitInstallments() {
  if (!canSplit.value) return
  const count = splitCount.value
  const total = Math.round(Number(props.item.amount) * 100)
  const base = Math.floor(total / count)
  draft.value = Array.from({ length: count }, (_, index) => {
    const amount = (index === count - 1 ? total - base * (count - 1) : base) / 100
    return {
      due_on: addMonths(splitFirstDue.value, index * splitStep.value), amount,
      ...(draftComponents.value ? { principal_amount: amount, interest_amount: 0, fee_amount: 0 } : {}),
    }
  })
}

function addInstallment() {
  const last = draft.value[draft.value.length - 1]
  draft.value = [...draft.value, {
    due_on: last ? addMonths(last.due_on, 1) : props.item.due_on || appIsoDate(), amount: 0,
    ...(draftComponents.value ? { principal_amount: 0, interest_amount: 0, fee_amount: 0 } : {}),
  }]
}

const cents = (value: unknown) => Math.round(Number(value || 0) * 100)

/** Částka rozdělené splátky je součet jistiny, úroku a poplatku. */
function rowAmount(row: OtherItemInstallment, split: boolean): number {
  return split
    ? (cents(row.principal_amount) + cents(row.interest_amount) + cents(row.fee_amount)) / 100
    : Number(row.amount || 0)
}

function toggleComponents() {
  draftComponents.value = !draftComponents.value
  draft.value = draft.value.map(row => draftComponents.value
    ? { ...row, principal_amount: Number(row.amount || 0), interest_amount: 0, fee_amount: 0 }
    : { ...row, amount: Number(row.principal_amount ?? row.amount ?? 0), principal_amount: null, interest_amount: null, fee_amount: null })
}

const savedTotal = computed(() => installments.value.reduce((sum, row) => sum + cents(rowAmount(row, components.value)), 0) / 100)
const draftTotal = computed(() => draft.value.reduce((sum, row) => sum + cents(rowAmount(row, draftComponents.value)), 0) / 100)
const draftPrincipal = computed(() => draft.value.reduce((sum, row) => sum + cents(row.principal_amount), 0) / 100)
const principalMismatch = computed(() => draftComponents.value && draft.value.length > 0
  && cents(draftPrincipal.value) !== cents(props.item.amount))

const loan = ref({ annual_rate: 0, count: 12, frequency: 'monthly' as 'monthly' | 'quarterly', method: 'annuity' as 'annuity' | 'linear',
  first_due_on: splitFirstDue.value, fee: 0 })
const canPlanLoan = computed(() => Number.isInteger(loan.value.count) && loan.value.count >= 2 && loan.value.count <= 120
  && Number(loan.value.annual_rate) >= 0 && Number(loan.value.annual_rate) <= 100 && Number(loan.value.fee) >= 0
  && /^\d{4}-\d{2}-\d{2}$/.test(loan.value.first_due_on))

async function planLoan() {
  if (!canPlanLoan.value || busy.value) return
  busy.value = true
  try {
    draft.value = await otherItemPlansApi.planLoan(Number(props.item.id), {
      annual_rate: Number(loan.value.annual_rate), count: loan.value.count, frequency: loan.value.frequency,
      method: loan.value.method, first_due_on: loan.value.first_due_on, fee: Number(loan.value.fee || 0),
    })
    draftComponents.value = true
    toast.success(t('other_items.plans.loan_planned'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function saveInstallments() {
  if (busy.value || draft.value.length < 2) return
  busy.value = true
  try {
    const saved = await otherItemPlansApi.setInstallments(Number(props.item.id),
      draft.value.map(row => draftComponents.value
        ? { due_on: row.due_on, amount: rowAmount(row, true), principal_amount: Number(row.principal_amount || 0),
            interest_amount: Number(row.interest_amount || 0), fee_amount: Number(row.fee_amount || 0) }
        : { due_on: row.due_on, amount: Number(row.amount) }))
    installments.value = saved
    components.value = hasComponents(saved)
    closeInstallments()
    toast.success(t('other_items.plans.installments_saved'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function clearInstallments() {
  if (busy.value || !window.confirm(t('other_items.plans.confirm_clear'))) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.setInstallments(Number(props.item.id), [])
    components.value = false
    closeInstallments()
    toast.success(t('other_items.plans.installments_cleared'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

const INSTALLMENT_STATUS_CLASS: Record<OtherItemInstallmentStatus, string> = {
  paid: 'bg-success-50 text-success-700',
  partial: 'bg-warning-50 text-warning-700',
  overdue: 'bg-danger-50 text-danger-700',
  unpaid: 'bg-neutral-100 text-neutral-600',
}
const PARTS = ['principal_amount', 'interest_amount', 'fee_amount'] as const

// Jiná položka: rozepsaný kalendář ani dialog k ní nepatří.
watch(() => props.item.id, () => {
  loaded.value = false
  scheduleOpen.value = false
  closeInstallments()
})
// Přiřazená nebo odpojená úhrada mění stav splátek, storno zdroje pozastaví rozvrh: načítá se i při změně
// uhrazené částky a stavu položky.
watch(() => [props.item.id, props.item.paid_amount, props.item.status], () => void load(), { immediate: true })
</script>

<template>
  <div class="space-y-4" :hidden="!(schedule || hasSavedInstallments || failed)" data-test="other-item-plans">
    <!-- Jeden kořen: bez rozvrhu a splátek je skrytý (dialogy se teleportují ven). -->
    <p v-if="failed" role="alert" class="flex flex-wrap items-center gap-2 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600" data-test="plans-failed">
      {{ t('other_items.plans.load_failed') }}
      <button type="button" :class="btnOutlineSm('danger')" @click="load">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('common.retry') }}
      </button>
    </p>
    <!-- Zdroj opakování: přehled rozvrhu. Úprava je dialog z tlačítka v záhlaví, rychlé akce zůstávají v kartě. -->
    <section v-if="schedule" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-test="schedule-card">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('other_items.plans.schedule_title') }}</h3>
          <span class="rounded px-2 py-0.5 text-xs" :class="schedule.status === 'active' ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'" data-test="schedule-status">{{ t(`other_items.plans.${schedule.status}`) }}</span>
        </div>
        <button v-if="canWrite" type="button" :disabled="busy || loading" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="schedule-edit" @click="openScheduleEdit">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('common.edit') }}
        </button>
      </div>
      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <!-- Dvojice v řádku: četnost s koncem, příští termín s jeho názvem; žádný údaj nezůstane sám v levém sloupci. -->
        <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.frequency') }}</dt><dd>{{ t(`other_items.plans.${schedule.frequency}`) }}</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.ends_label') }}</dt><dd class="font-mono">{{ schedule.ends_on ? formatDate(schedule.ends_on) : t('other_items.plans.no_end') }}</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.next_on') }}</dt><dd class="font-mono" data-test="schedule-next">{{ schedule.next_on ? formatDate(schedule.next_on) : '—' }}</dd></div>
        <div v-if="schedule.next_title" class="flex justify-between gap-3"><dt class="shrink-0 text-neutral-500">{{ t('other_items.plans.next_title') }}</dt><dd class="min-w-0 break-words text-right" data-test="schedule-next-title">{{ schedule.next_title }}</dd></div>
        <!-- Kdo smí rozvrh měnit, vidí volbu jako zaškrtávátko níž; ostatním stačí údaj. -->
        <div v-if="!(canWrite && sourceActive)" class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.auto_post_short') }}</dt><dd data-test="schedule-auto-post">{{ t(schedule.auto_post ? 'common.yes' : 'common.no') }}</dd></div>
        <div v-if="schedule.created_at" class="flex justify-between gap-3" :class="{ 'sm:col-span-2': canWrite && sourceActive }"><dt class="text-neutral-500">{{ t('other_items.plans.created_by') }}</dt><dd data-test="schedule-created-by">{{ schedule.created_by_name || t('other_items.plans.unknown_user') }} · {{ formatDate(schedule.created_at) }}</dd></div>
      </dl>
      <p v-if="!sourceActive" class="mt-3 text-sm text-neutral-500">{{ t('other_items.plans.source_inactive') }}</p>
      <label v-if="canWrite && sourceActive" class="mt-3 flex items-center gap-2 text-sm">
        <input type="checkbox" :checked="schedule.auto_post" :disabled="busy || loading || ((!canAutoPost || !canPost) && !schedule.auto_post)" data-test="schedule-auto-post-toggle" @change="toggleAutoPost" />
        {{ t('other_items.plans.auto_post') }}
      </label>
      <p v-if="canWrite && sourceActive" class="mt-1 text-xs text-neutral-500">{{ t(canAutoPost ? 'other_items.plans.auto_post_hint' : 'other_items.plans.auto_post_source') }}</p>
      <div v-if="canWrite && sourceActive" class="mt-3 flex flex-wrap items-end gap-2">
        <label class="text-sm font-medium">{{ t('other_items.plans.generate_through') }}
          <DateInput v-model="through" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
        </label>
        <button type="button" :disabled="busy || loading || schedule.status !== 'active' || (schedule.auto_post && !canPost)" :class="[btnOutline('success'), 'whitespace-nowrap']" data-test="schedule-generate" @click="generate">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.plans.generate') }}
        </button>
      </div>
      <h4 class="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('other_items.plans.created_items') }}</h4>
      <ul v-if="generatedOccurrences.length" class="divide-y divide-neutral-100 text-sm" data-test="schedule-occurrences">
        <li v-for="occurrence in generatedOccurrences" :key="occurrence.occurrence_index" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
          <RouterLink :to="`/other-items/${occurrence.item_id}`" class="text-primary-700 hover:underline">
            <span class="font-mono">{{ occurrence.document_no || `#${occurrence.item_id}` }}</span>
            <span class="ml-2 text-neutral-500">{{ formatDate(occurrence.issued_on) }}</span>
          </RouterLink>
          <span class="rounded px-2 py-0.5 text-xs" :class="otherItemStatusClass(occurrence.status)">{{ t(`other_items.status.${occurrence.status}`) }}</span>
        </li>
      </ul>
      <p v-else class="text-sm text-neutral-500">{{ t('other_items.plans.no_generated') }}</p>
    </section>

    <!-- Splátkový kalendář: přehled uložených splátek se stavem úhrady; úprava je dialog. -->
    <section v-if="hasSavedInstallments" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-test="installments-card">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-neutral-700">{{ t('other_items.plans.installments_title') }}</h3>
        <button v-if="canWrite && sourceActive" type="button" :disabled="busy || loading || !canEditInstallments" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="installments-edit"
          :title="!canEditInstallments ? t('other_items.plans.paid_locked') : undefined"
          :aria-describedby="!canEditInstallments ? 'other-item-installments-locked' : undefined" @click="openInstallmentsEdit">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('common.edit') }}
        </button>
      </div>
      <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <div v-else class="mt-3 overflow-x-auto" data-test="installments-table">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-2 py-1.5 text-left font-medium">#</th>
              <th class="px-2 py-1.5 text-left font-medium">{{ t('other_items.due_on') }}</th>
              <template v-if="components">
                <th v-for="part in PARTS" :key="part" class="px-2 py-1.5 text-right font-medium">{{ t(`other_items.plans.${part}`) }}</th>
              </template>
              <th class="px-2 py-1.5 text-right font-medium">{{ t('other_items.amount') }}</th>
              <th class="px-2 py-1.5 text-left font-medium">{{ t('other_items.status_label') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(row, index) in installments" :key="row.id || index" data-test="installment-row">
              <td class="px-2 py-1.5 tabular-nums text-neutral-500">{{ index + 1 }}</td>
              <td class="whitespace-nowrap px-2 py-1.5">{{ row.due_on ? formatDate(row.due_on) : '–' }}</td>
              <template v-if="components">
                <td v-for="part in PARTS" :key="part" class="whitespace-nowrap px-2 py-1.5 text-right tabular-nums">{{ formatMoney(Number(row[part] ?? 0), item.currency) }}</td>
              </template>
              <td class="whitespace-nowrap px-2 py-1.5 text-right font-medium tabular-nums" data-test="installment-amount">{{ formatMoney(rowAmount(row, components), item.currency) }}</td>
              <td class="px-2 py-1.5">
                <span v-if="row.status" :class="INSTALLMENT_STATUS_CLASS[row.status]" class="whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium" data-test="installment-status">
                  {{ t(`other_items.plans.installment_status.${row.status}`) }}<template v-if="row.status === 'partial' || (row.status === 'overdue' && Number(row.paid_amount || 0) > 0)"> · {{ t('other_items.plans.installment_remaining', { amount: formatMoney(row.remaining_amount ?? 0, item.currency) }) }}</template>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="mt-3 text-sm font-medium">{{ t('other_items.plans.total') }}: {{ formatMoney(savedTotal, item.currency) }}</p>
      </div>
      <p v-if="canWrite && sourceActive && !canEditInstallments" id="other-item-installments-locked" class="mt-3 text-sm text-neutral-500" data-test="installments-locked">{{ t('other_items.plans.paid_locked') }}</p>
    </section>

    <!-- Oba plány se upravují ve stejném typu dialogu. -->
    <Modal v-if="scheduleOpen" :title="t(scheduleMode === 'create' ? 'other_items.plans.create_schedule' : 'other_items.plans.edit_schedule')" width-class="max-w-2xl" @close="!busy && (scheduleOpen = false)">
      <form v-if="scheduleMode === 'create'" id="other-item-schedule" class="space-y-4" data-test="schedule-form" @submit.prevent="createSchedule">
        <p class="text-sm text-neutral-600">{{ t('other_items.plans.schedule_hint') }}</p>
        <label class="block text-sm font-medium">{{ t('other_items.plans.frequency') }}
          <select v-model="frequency" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
            <option value="monthly">{{ t('other_items.plans.monthly') }}</option>
            <option value="quarterly">{{ t('other_items.plans.quarterly') }}</option>
            <option value="yearly">{{ t('other_items.plans.yearly') }}</option>
          </select>
        </label>
        <p v-if="fieldErrorText('frequency')" role="alert" class="-mt-3 text-xs text-danger-600">{{ fieldErrorText('frequency') }}</p>
        <label class="block text-sm font-medium">{{ t('other_items.plans.ends_label') }}
          <DateInput v-model="endsOn" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
        <p v-if="fieldErrorText('ends_on')" role="alert" class="-mt-3 text-xs text-danger-600" data-test="schedule-ends-error">{{ fieldErrorText('ends_on') }}</p>
        <div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="autoPost" type="checkbox" :disabled="!canAutoPost || !canPost || busy" />
            {{ t('other_items.plans.auto_post') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500">{{ t(canAutoPost ? 'other_items.plans.auto_post_hint' : 'other_items.plans.auto_post_source') }}</p>
        </div>
        <OtherItemScheduleTitleFields v-model="titleSettings" :item-id="Number(item.id)" :frequency="frequency" :ends-on="endsOn || null" :submit-error="titleError" />
      </form>
      <div v-else-if="schedule" class="space-y-4 text-sm" data-test="schedule-edit-form">
        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
          <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.frequency') }}</dt><dd>{{ t(`other_items.plans.${schedule.frequency}`) }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.plans.ends_label') }}</dt><dd class="font-mono">{{ schedule.ends_on ? formatDate(schedule.ends_on) : t('other_items.plans.no_end') }}</dd></div>
        </dl>
        <p class="text-neutral-600">{{ t('other_items.plans.schedule_fixed_hint') }}</p>
        <p v-if="!sourceActive" class="text-neutral-500">{{ t('other_items.plans.source_inactive') }}</p>
        <form v-if="canEditTitle" id="other-item-schedule-title" class="space-y-4 border-t border-neutral-200 pt-4" data-test="schedule-title-form" @submit.prevent="saveTitle">
          <p class="text-neutral-600">{{ t('other_items.plans.edit_title_hint') }}</p>
          <p v-if="lastCreatedOn" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-warning-700" data-test="schedule-title-created-until">
            {{ t('other_items.plans.edit_title_created_until', { date: formatDate(lastCreatedOn) }) }}
          </p>
          <OtherItemScheduleTitleFields v-model="titleSettings" :item-id="Number(item.id)" :submit-error="titleError" />
        </form>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <template v-if="scheduleMode === 'create'">
            <button type="button" :disabled="busy" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="scheduleOpen = false">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" form="other-item-schedule" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="schedule-submit">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ t('other_items.plans.create_schedule') }}
            </button>
          </template>
          <template v-else-if="schedule">
            <button type="button" :disabled="busy" :class="[btnOutline('danger'), 'whitespace-nowrap']" data-test="schedule-delete" @click="deleteSchedule">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('other_items.plans.delete_schedule') }}
            </button>
            <button v-if="sourceActive" type="button" :disabled="busy || (schedule.status !== 'active' && schedule.auto_post && !canPost)" :class="[btnOutline('warning'), 'whitespace-nowrap']" data-test="schedule-toggle" @click="toggleSchedule">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="schedule.status === 'active' ? ICONS.pause : ICONS.play" /></svg>
              {{ t(schedule.status === 'active' ? 'other_items.plans.pause' : 'other_items.plans.resume') }}
            </button>
            <template v-if="canEditTitle">
              <button type="button" :disabled="busy" :class="btnOutline('neutral')" class="whitespace-nowrap" data-test="schedule-close" @click="scheduleOpen = false">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('common.close') }}
              </button>
              <button type="submit" form="other-item-schedule-title" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="schedule-title-submit">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('other_items.plans.save_title') }}
              </button>
            </template>
            <button v-else type="button" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="schedule-close" @click="scheduleOpen = false">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.close') }}
            </button>
          </template>
        </div>
      </template>
    </Modal>

    <Modal v-if="installmentsOpen" :title="t(hasSavedInstallments ? 'other_items.plans.edit_installments' : 'other_items.plans.split_action')" width-class="max-w-2xl" @close="closeInstallments">
      <div class="space-y-3" data-test="installments-form">
        <p class="text-sm text-neutral-500">{{ t('other_items.plans.installments_hint') }}</p>
        <div class="rounded-md border border-neutral-200 p-3">
          <div class="flex flex-wrap items-end gap-2">
            <label class="text-sm font-medium">{{ t('other_items.plans.split_count') }}
              <input v-model.number="splitCount" type="number" min="2" max="120" step="1" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_every') }}
              <select v-model.number="splitStep" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option :value="1">{{ t('other_items.plans.split_month') }}</option>
                <option :value="3">{{ t('other_items.plans.split_quarter') }}</option>
                <option :value="12">{{ t('other_items.plans.split_year') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_first_due') }}
              <DateInput v-model="splitFirstDue" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
            </label>
            <button type="button" :disabled="!canSplit" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="installments-split" @click="splitInstallments">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
              {{ t('other_items.plans.split') }}
            </button>
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ t('other_items.plans.split_hint') }}</p>
          <label class="mt-3 flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="draftComponents" data-test="installment-components" @change="toggleComponents" />
            {{ t('other_items.plans.components') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.plans.components_hint') }}</p>
          <div v-if="draftComponents" class="mt-3 flex flex-wrap items-end gap-2 border-t border-neutral-200 pt-3" data-test="loan-plan">
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_rate') }}
              <input v-model.number="loan.annual_rate" type="number" min="0" max="100" step="0.0001" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_count') }}
              <input v-model.number="loan.count" type="number" min="2" max="120" step="1" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.frequency') }}
              <select v-model="loan.frequency" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option value="monthly">{{ t('other_items.plans.monthly') }}</option>
                <option value="quarterly">{{ t('other_items.plans.quarterly') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_method') }}
              <select v-model="loan.method" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option value="annuity">{{ t('other_items.plans.loan_annuity') }}</option>
                <option value="linear">{{ t('other_items.plans.loan_linear') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_first_due') }}
              <DateInput v-model="loan.first_due_on" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_fee') }}
              <input v-model.number="loan.fee" type="number" min="0" step="0.01" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <button type="button" :disabled="!canPlanLoan || busy" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="loan-plan-run" @click="planLoan">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
              {{ t('other_items.plans.loan_plan') }}
            </button>
            <p class="w-full text-xs text-neutral-500">{{ t('other_items.plans.loan_hint') }}</p>
          </div>
        </div>
        <!-- Rozepsané splátky jako tabulka s jedním záhlavím: řádky se nerozjíždějí podle délky popisku. -->
        <div v-if="draft.length" class="overflow-x-auto" data-test="installments-draft-table">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-2 py-1.5 text-left font-medium">#</th>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('other_items.due_on') }}</th>
                <template v-if="draftComponents">
                  <th v-for="part in PARTS" :key="part" class="px-2 py-1.5 text-right font-medium">{{ t(`other_items.plans.${part}`) }}</th>
                </template>
                <th class="px-2 py-1.5 text-right font-medium">{{ t('other_items.amount') }}</th>
                <th class="px-2 py-1.5"><span class="sr-only">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, index) in draft" :key="row.id || index" data-test="installment-draft-row">
                <td class="px-2 py-1.5 tabular-nums text-neutral-500">{{ index + 1 }}</td>
                <td class="px-2 py-1.5">
                  <DateInput v-model="row.due_on" :aria-label="t('other_items.plans.due_number', { number: index + 1 })" class="block h-9 w-32 rounded-md border border-neutral-300 px-2" />
                </td>
                <template v-if="draftComponents">
                  <td v-for="part in PARTS" :key="part" class="px-2 py-1.5">
                    <input v-model.number="row[part]" type="number" min="0" step="0.01" :aria-label="`${t(`other_items.plans.${part}`)} ${index + 1}`" :data-test="`installment-${part}`"
                      class="block h-9 w-20 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
                  </td>
                  <td class="whitespace-nowrap px-2 py-1.5 text-right font-medium tabular-nums" data-test="installment-draft-amount">{{ formatMoney(rowAmount(row, true), item.currency) }}</td>
                </template>
                <td v-else class="px-2 py-1.5">
                  <input v-model.number="row.amount" type="number" min="0.01" step="0.01" :aria-label="`${t('other_items.amount')} ${index + 1}`" data-test="installment-amount-input"
                    class="ml-auto block h-9 w-32 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
                </td>
                <td class="px-2 py-1.5 text-right">
                  <button type="button" :class="btnOutlineSm('danger')" :title="`${t('common.delete')} ${index + 1}`" :aria-label="`${t('common.delete')} ${index + 1}`"
                    data-test="installment-delete" @click="draft.splice(index, 1)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-test="installment-add" @click="addInstallment">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.plans.add_installment') }}
        </button>
        <p v-if="draft.length" class="text-sm font-medium" data-test="installment-draft-total">{{ t('other_items.plans.total') }}: {{ formatMoney(draftTotal, item.currency) }}</p>
        <p v-if="draft.length && draftComponents" class="text-sm" :class="principalMismatch ? 'text-danger-700' : 'text-neutral-600'" data-test="installment-principal-total">
          {{ t('other_items.plans.principal_total', { amount: formatMoney(draftPrincipal, item.currency), item: formatMoney(Number(item.amount), item.currency) }) }}
        </p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button v-if="hasSavedInstallments" type="button" :disabled="busy" :class="btnOutline('danger')" class="whitespace-nowrap" data-test="installments-clear" @click="clearInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('other_items.plans.clear_installments') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-test="installments-cancel" @click="closeInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || draft.length < 2" :class="btnFilled('success')" class="whitespace-nowrap" data-test="installments-save" @click="saveInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
