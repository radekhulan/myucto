<script setup lang="ts">
import { journalAmount } from '@/utils/journalAmount'
/**
 * Přeúčtování už zaúčtovaného dokladu — oprava kontace, která v deníku je.
 *
 * Do zavedení tohohle dialogu se chybná kontace opravovala ručně přes účetní deník
 * (najdi zápis → smaž nebo stornuj → vrať se na doklad → zaúčtuj znovu) a účetní
 * musela sama uhodnout, KTERÝ z těch dvou postupů období dovolí. Špatný odhad se
 * projevil až v posledním kroku, kdy už byl původní zápis pryč.
 *
 * Rozhodnutí přepsat × stornovat × odmítnout NEDĚLÁ tenhle popup: přichází ze
 * serveru z `repost-plan` a provede ho tatáž služba, takže se náhled s výsledkem
 * nemůže rozejít. Popup jen ukáže, co se stane, a nechá upravit řádky.
 *
 * Dimenze dokladu (Firma → Dimenze) se tu upravují taky. Jsou jen analytika, takže
 * jejich změna přeúčtování nepotřebuje: „Uložit jen dimenze" přerazítkuje řádky
 * zápisu bez storna i v uzavřeném období, kde samotné přeúčtování zůstává
 * zablokované. S přeúčtováním se dimenze uloží v téže transakci.
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi, postingErrorMessage,
  type ChartAccount, type JournalPostingSource, type RepostPlan,
} from '@/api/accounting'
import {
  dimensionsApi, compactDimensions,
  type DimensionMap, type DocumentDimensionsPreview,
} from '@/api/dimensions'
import Modal from '../ui/Modal.vue'
import JournalLinesEditor, { type EditorLine } from './JournalLinesEditor.vue'
import PostingOriginRow from './PostingOriginRow.vue'
import JournalEntryNotes from './JournalEntryNotes.vue'
import DimensionFields from '../dimensions/DimensionFields.vue'
import DimensionChips from '../dimensions/DimensionChips.vue'
import { btnOutline, btnFilled, ICONS } from '../ui/buttonStyles'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  open: boolean
  /** `bank-transactions` = bankovní pohyb; bankovní invarianty (221 = částka výpisu) hlídá server. */
  source: JournalPostingSource
  docId: number
  /** Popisek dokladu do hlavičky (číslo faktury, u banky popis pohybu). */
  docLabel?: string | null
  /**
   * Navržená kontace MD/D (pravidlo založené z už zaúčtovaného pohybu). Přepíše jen
   * jednoduchý dvouřádkový zápis a jen jeho ne-bankovní stranu; rozúčtování nechá být.
   */
  proposedAccounts?: { debit: string; credit: string } | null
}>()

const emit = defineEmits<{ close: []; reposted: []; dimensionsSaved: [] }>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()

const dimHeader = ref<DimensionMap>({})
const dimSaved = ref('{}')
const dimPreview = ref<DocumentDimensionsPreview | null>(null)
const dimPreviewLoading = ref(false)
const dimSaving = ref(false)
let previewTimer: ReturnType<typeof setTimeout> | null = null
let previewSeq = 0

const dimsShown = computed(() => dims.enabled.value && dims.documentTypes.value.length > 0)
const dimsDirty = computed(() => JSON.stringify(compactDimensions(dimHeader.value)) !== dimSaved.value)
const dimsRefused = computed(() => dimsDirty.value && dimPreview.value?.refused === true)
const canSaveDimensions = computed(() =>
  dimsShown.value && dims.canEdit.value && dimsDirty.value && !dimsRefused.value
  && !dimPreviewLoading.value && !dimSaving.value && !saving.value)

async function loadDimensions(): Promise<void> {
  dimHeader.value = {}
  dimSaved.value = '{}'
  dimPreview.value = null
  if (!dims.enabled.value) return
  try {
    await dims.load()
    const data = await dimensionsApi.getDocument(props.source, props.docId)
    dimHeader.value = { ...data.header }
    dimSaved.value = JSON.stringify(compactDimensions(data.header))
  } catch {
    dimHeader.value = {}
  }
}

/** Náhled, co ponesou řádky zápisu — počítá ho server stejnou cestou jako uložení. */
function schedulePreview(): void {
  if (previewTimer) clearTimeout(previewTimer)
  if (!dimsDirty.value) {
    dimPreview.value = null
    return
  }
  dimPreviewLoading.value = true
  previewTimer = setTimeout(async () => {
    const seq = ++previewSeq
    try {
      const result = await dimensionsApi.previewDocument(props.source, props.docId, { header: dimHeader.value })
      if (seq === previewSeq) dimPreview.value = result
    } catch {
      if (seq === previewSeq) dimPreview.value = null
    } finally {
      if (seq === previewSeq) dimPreviewLoading.value = false
    }
  }, 400)
}

watch(dimHeader, schedulePreview, { deep: true })
onBeforeUnmount(() => { if (previewTimer) clearTimeout(previewTimer) })

async function saveDimensionsOnly(): Promise<void> {
  if (!canSaveDimensions.value) return
  dimSaving.value = true
  error.value = ''
  try {
    const result = await dimensionsApi.saveDocument(props.source, props.docId, { header: dimHeader.value })
    toast.success(result.restamp.lines > 0
      ? t('dimensions.saved_restamped', { count: result.restamp.lines })
      : t('dimensions.saved'))
    emit('dimensionsSaved')
    emit('close')
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    dimSaving.value = false
  }
}

const plan = ref<RepostPlan | null>(null)
const lines = ref<EditorLine[]>([])
const accounts = ref<ChartAccount[]>([])
const description = ref('')
const confirmShift = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editorRef = ref<InstanceType<typeof JournalLinesEditor> | null>(null)

/**
 * Rozhodnutí nad UPRAVENÝMI řádky. V zamčeném datu záleží na tom, co se mění:
 * přesun mezi nákladovými účty se přepíše na místě, změna DPH nebo závazku jde
 * stornem. Rozhoduje server touž funkcí jako při uložení, dialog jen ukáže výsledek.
 */
const linesPlan = ref<RepostPlan | null>(null)
const linesPlanLoading = ref(false)
let linesPlanTimer: ReturnType<typeof setTimeout> | null = null
let linesPlanSeq = 0

const effectivePlan = computed<RepostPlan | null>(() => linesPlan.value ?? plan.value)
const blocked = computed(() => plan.value?.strategy === 'blocked')
const inPlaceLocked = computed(() => effectivePlan.value?.strategy === 'replace'
  && effectivePlan.value?.reason_code === 'tax_neutral_rewrite')
/** Zamčené datum, o přepisu na místě ale rozhodnou až upravené řádky. */
const awaitingLinesDecision = computed(() => !!plan.value && plan.value.tax_neutral_available
  && plan.value.strategy !== 'replace' && linesPlan.value === null)
const needsShiftConfirm = computed(() => !!effectivePlan.value && effectivePlan.value.date_shifted
  && !awaitingLinesDecision.value)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  plan.value = null
  linesPlan.value = null
  lines.value = []
  confirmShift.value = false
  try {
    const [p, acc] = await Promise.all([
      accountingApi.repostPlan(props.source, props.docId),
      accounts.value.length > 0 ? Promise.resolve(accounts.value) : accountingApi.listAccounts(),
    ])
    plan.value = p
    accounts.value = acc
    description.value = p.description ?? ''
    // Předvyplní se stávající zaúčtování. Předpis z aktuálního dokladu (u skupiny)
    // se jen nabídne: převzatý doklad může mít kontace, které návrh z dokladu nezná
    // (pokladna místo 311, analytiky zdroje), a potvrzení beze změn je nesmí přepsat.
    lines.value = editableLines(p.lines)
    applyProposal()
  } catch (e: any) {
    error.value = postingErrorMessage(t, e)
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.docId], ([open]) => {
  if (open) {
    load()
    void loadDimensions()
  }
}, { immediate: true })

const proposalApplied = ref(false)

function editableLines(source: Array<{ account_code: string | null; side: 'debit' | 'credit'; amount: number | string | null; is_red_storno?: boolean }>) {
  return source.map(l => ({
    account_code: l.account_code ?? '',
    side: l.side,
    amount: l.amount === null ? null : Number(l.amount),
    is_red_storno: l.is_red_storno,
  }))
}

const suggestionDiffers = computed(() => {
  const suggested = plan.value?.suggested_lines
  return !!suggested && suggested.length > 0 && linesKeyOf(suggested) !== linesKeyOf(plan.value?.lines ?? [])
})

const usingSuggestion = computed(() => {
  const suggested = plan.value?.suggested_lines
  return !!suggested && linesKeyOf(lines.value) === linesKeyOf(suggested)
})

function useSuggestedLines(): void {
  if (plan.value?.suggested_lines) lines.value = editableLines(plan.value.suggested_lines)
}

function useCurrentLines(): void {
  if (plan.value) lines.value = editableLines(plan.value.lines)
}

function linesKeyOf(ls: Array<{ account_code: string | null; side: string; amount: number | string | null; is_red_storno?: boolean }>): string {
  return ls.map(l => `${l.side}|${(l.account_code ?? '').trim()}|${Math.round(Number(l.amount ?? 0) * 100)}|${l.is_red_storno ? 1 : 0}`).sort().join(';')
}

function applyProposal(): void {
  proposalApplied.value = false
  const proposal = props.proposedAccounts
  if (!proposal || props.source !== 'bank-transactions' || lines.value.length !== 2) return
  const debit = lines.value.find(l => l.side === 'debit')
  const credit = lines.value.find(l => l.side === 'credit')
  if (!debit || !credit) return
  // Bankovní strana (221 s analytikou účtu výpisu) zůstává, mění se jen protiúčet.
  if (credit.account_code.startsWith('221') && !proposal.debit.startsWith('221')) {
    debit.account_code = proposal.debit
  } else if (debit.account_code.startsWith('221') && !proposal.credit.startsWith('221')) {
    credit.account_code = proposal.credit
  } else {
    return
  }
  proposalApplied.value = true
}

/** Změnila se kontace nebo popis zápisu? Beze změny není co přeúčtovat. */
const postingChanged = computed(() => {
  if (!plan.value) return false
  if (description.value.trim() !== (plan.value.description ?? '').trim()) return true
  const key = (ls: Array<{ account_code: string | null; side: string; amount: number | string | null; is_red_storno?: boolean }>) =>
    ls.map(l => `${l.side}|${(l.account_code ?? '').trim()}|${Math.round(Number(l.amount ?? 0) * 100)}|${l.is_red_storno ? 1 : 0}`).sort().join(';')
  return key(lines.value) !== key(plan.value.lines)
})

function scheduleLinesPlan(): void {
  if (linesPlanTimer) clearTimeout(linesPlanTimer)
  const base = plan.value
  const linesKey = (ls: Array<{ account_code: string | null; side: string; amount: number | string | null; is_red_storno?: boolean }>) =>
    ls.map(l => `${l.side}|${(l.account_code ?? '').trim()}|${Math.round(Number(l.amount ?? 0) * 100)}|${l.is_red_storno ? 1 : 0}`).sort().join(';')
  if (!base || !base.tax_neutral_available || base.strategy === 'replace'
    || linesKey(lines.value) === linesKey(base.lines)
    || lines.value.some(l => !l.account_code.trim() || !(Number(l.amount ?? 0) > 0))) {
    linesPlanSeq++
    linesPlan.value = null
    linesPlanLoading.value = false
    return
  }
  linesPlanLoading.value = true
  linesPlanTimer = setTimeout(async () => {
    const seq = ++linesPlanSeq
    try {
      const result = await accountingApi.repostPlanForLines(props.source, props.docId, lines.value.map(l => ({
        account_code: l.account_code,
        side: l.side,
        amount: l.amount ?? 0,
        is_red_storno: l.is_red_storno,
      })))
      if (seq === linesPlanSeq) linesPlan.value = result
    } catch {
      if (seq === linesPlanSeq) linesPlan.value = null
    } finally {
      if (seq === linesPlanSeq) linesPlanLoading.value = false
    }
  }, 400)
}

watch(lines, () => {
  confirmShift.value = false
  scheduleLinesPlan()
}, { deep: true })
onBeforeUnmount(() => { if (linesPlanTimer) clearTimeout(linesPlanTimer) })

const notesRef = ref<InstanceType<typeof JournalEntryNotes> | null>(null)
const notesPending = computed(() => notesRef.value?.hasPending ?? false)
/**
 * Mění se jen poznámka: hlavní tlačítko ji uloží bez přeúčtování. Poznámka je analytika
 * mimo zápis, proto jde vždy — i v uzavřeném roce, kde je samotné přeúčtování zablokované.
 */
const notesOnly = computed(() => notesPending.value && !postingChanged.value && !dimsDirty.value)

const canSubmit = computed(() => notesOnly.value
  ? !loading.value && !saving.value
  : !!plan.value && !blocked.value && !loading.value && !saving.value && !linesPlanLoading.value
    && (editorRef.value?.valid ?? false)
    && (!needsShiftConfirm.value || confirmShift.value))

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  if (notesOnly.value) {
    saving.value = true
    try {
      await notesRef.value?.savePending()
      emit('close')
    } finally {
      saving.value = false
    }
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (notesPending.value) await notesRef.value?.savePending()
    await accountingApi.repost(props.source, props.docId, {
      lines: lines.value.map(l => ({
        account_code: l.account_code,
        side: l.side,
        amount: l.amount ?? 0,
        is_red_storno: l.is_red_storno,
      })),
      description: description.value.trim() || null,
      confirm_date_shift: confirmShift.value,
      ...(plan.value?.posting_entries ? { expected_posting_entries: plan.value.posting_entries.map(({ id, row_version }) => ({ id, row_version })) } : {}),
      ...(dimsShown.value && dimsDirty.value ? { dimensions: { header: compactDimensions(dimHeader.value) } } : {}),
    })
    emit('reposted')
    emit('close')
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'split_in_locked_period' || code === 'invalid_dimension'
      ? (e?.response?.data?.error?.message || t('common.error'))
      : postingErrorMessage(t, e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal v-if="open" :title="t('accounting.repost.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4">
      <p v-if="docLabel" class="text-sm text-neutral-600">
        {{ t('accounting.repost.document') }}: <span class="font-mono">{{ docLabel }}</span>
      </p>

      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

      <div v-if="error" class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
        {{ error }}
      </div>

      <template v-if="plan && effectivePlan && !loading">
        <div v-if="plan.reason_code === 'posting_group' || (plan.posting_entries?.length ?? 0) > 1"
          class="px-3 py-2 rounded-md border border-warning-500/30 bg-warning-50 text-warning-700 text-sm"
          data-test="repost-posting-group">
          <p>{{ t('accounting.repost.posting_group', { count: plan.posting_entries?.length ?? 0 }) }}</p>
          <ul class="mt-1 list-disc list-inside font-mono">
            <li v-for="entry in plan.posting_entries" :key="entry.id">#{{ entry.id }} · {{ formatDate(entry.entry_date) }}</li>
          </ul>
        </div>
        <div v-if="suggestionDiffers"
          class="px-3 py-2 rounded-md border border-primary-200 bg-primary-50 text-primary-700 text-sm flex flex-wrap items-center gap-2"
          data-test="repost-suggestion">
          <p class="flex-1 min-w-[12rem]">{{ t(usingSuggestion ? 'accounting.repost.suggestion_used' : 'accounting.repost.suggestion_available') }}</p>
          <button v-if="!usingSuggestion" type="button" :class="btnOutline('primary')" class="whitespace-nowrap"
            data-test="repost-use-suggested" @click="useSuggestedLines">
            {{ t('accounting.repost.use_suggested') }}
          </button>
          <button v-else type="button" :class="btnOutline('neutral')" class="whitespace-nowrap"
            data-test="repost-use-current" @click="useCurrentLines">
            {{ t('accounting.repost.use_current') }}
          </button>
        </div>
        <!-- Co se stane. Bez téhle věty by uživatel nepoznal rozdíl mezi „přepíše se"
             a „vznikne protizápis" — a přitom je to ten rozdíl, který zůstane v deníku.
             V zamčeném datu o tom rozhodují upravené řádky (server, linesPlan). -->
        <div v-if="awaitingLinesDecision && !blocked"
          class="px-3 py-2 rounded-md text-sm border bg-neutral-50 border-neutral-200 text-neutral-700"
          data-test="repost-tax-neutral-pending">
          <p class="font-medium">{{ t('accounting.repost.strategy_pending') }}</p>
          <p class="mt-1">
            {{ t('accounting.repost.tax_neutral_available', {
              date: formatDate(plan.entry_date),
              lock: plan.locked_until ? formatDate(plan.locked_until) : '',
            }) }}
          </p>
          <p v-if="linesPlanLoading" class="mt-1 text-neutral-500">{{ t('accounting.repost.checking') }}</p>
        </div>
        <div v-else class="px-3 py-2 rounded-md text-sm border"
          :data-test="inPlaceLocked ? 'repost-in-place' : 'repost-strategy'"
          :class="blocked
            ? 'bg-danger-50 border-danger-500/30 text-danger-600'
            : (effectivePlan.strategy === 'reverse'
              ? 'bg-warning-50 border-warning-500/30 text-warning-700'
              : 'bg-neutral-50 border-neutral-200 text-neutral-700')">
          <p class="font-medium">
            {{ inPlaceLocked
              ? t('accounting.repost.strategy_replace_in_place', { date: formatDate(effectivePlan.entry_date) })
              : t(`accounting.repost.strategy_${effectivePlan.strategy}`) }}
          </p>
          <p v-if="blocked" class="mt-1">
            {{ t(`accounting.repost.blocked_${plan.reason_code === 'date_locked' ? 'date_locked' : 'period_not_open'}`, {
              date: plan.locked_until ? formatDate(plan.locked_until) : '',
              status: plan.period_status ?? '',
            }) }}
          </p>
          <p v-else-if="effectivePlan.strategy === 'reverse' && effectivePlan.tax_neutral_violation" class="mt-1"
            data-test="repost-violation">
            {{ t('accounting.repost.reverse_because', {
              reason: t(`accounting.repost.violation_${effectivePlan.tax_neutral_violation}`),
            }) }}
          </p>
          <p v-else-if="effectivePlan.strategy === 'reverse'" class="mt-1">
            {{ t(`accounting.repost.reason_${effectivePlan.reason_code ?? 'period_not_open'}`, {
              status: effectivePlan.period_status ?? '',
              date: effectivePlan.locked_until ? formatDate(effectivePlan.locked_until) : '',
            }) }}
          </p>
          <p v-if="linesPlanLoading" class="mt-1 text-neutral-500">{{ t('accounting.repost.checking') }}</p>
        </div>
        <p v-if="proposalApplied" class="text-sm text-primary-700" data-test="repost-proposal-hint">
          {{ t('accounting.repost.proposal_from_rule') }}
        </p>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-2 text-sm">
          <div class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.entry') }}</dt>
            <dd class="font-mono">{{ plan.document_no || `#${plan.entry_id}` }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.entry_date') }}</dt>
            <dd class="font-mono">{{ formatDate(plan.entry_date) }}</dd>
          </div>
          <div v-if="effectivePlan.target_date && !awaitingLinesDecision" class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.target_date') }}</dt>
            <dd class="font-mono" :class="effectivePlan.date_shifted ? 'text-warning-700 font-semibold' : ''">
              {{ formatDate(effectivePlan.target_date) }}
            </dd>
          </div>
        </dl>

        <!-- Podle čeho kontace vznikla. Právě tady to má cenu: než účetní kontaci
             přepíše ručně, má vidět, jestli se nedá opravit rovnou šablona — jinak
             se týž zásah bude opakovat u každého dalšího dokladu. U bankovního
             pohybu je to zároveň jediné místo, kde se šablona ukazuje: řádek výpisu
             na ni místo nemá. -->
        <PostingOriginRow :source="source" :doc-id="docId" />

        <!-- Dimenze jsou jen analytika: samotná změna přeúčtování nepotřebuje a jde
             i tam, kde je přeúčtování zablokované (uzavřené období). -->
        <section v-if="dimsShown" class="space-y-2 rounded-md border border-neutral-200 p-3" data-test="repost-dimensions">
          <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.repost.dimensions_title') }}</h4>
          <DimensionFields v-model="dimHeader" :disabled="!dims.canEdit.value || saving || dimSaving" teleport />
          <p class="text-xs text-neutral-500">{{ t('accounting.repost.dimensions_hint') }}</p>
          <div v-if="dimsDirty && dimPreviewLoading" class="text-xs text-neutral-500">{{ t('common.loading') }}</div>
          <p v-else-if="dimsRefused" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-800"
             data-test="repost-dimensions-refused">
            {{ t('accounting.repost.dimensions_refused') }}
          </p>
          <div v-else-if="dimsDirty && dimPreview && dimPreview.lines.length > 0" class="text-sm" data-test="repost-dimensions-preview">
            <p class="text-xs text-neutral-500 mb-1">{{ t('accounting.repost.dimensions_preview') }}</p>
            <ul class="divide-y divide-neutral-100">
              <li v-for="line in dimPreview.lines" :key="line.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1">
                <span class="font-mono font-medium">{{ line.account_code }}</span>
                <span class="text-xs text-neutral-500">{{ line.side === 'debit' ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit') }}</span>
                <span class="font-mono">{{ formatMoney(journalAmount(line)) }}</span>
                <DimensionChips :dimensions="line.dimensions" class="ml-auto" />
              </li>
            </ul>
          </div>
        </section>

        <template v-if="!blocked">
          <label class="block text-sm">
            <span class="block text-neutral-500 mb-1">{{ t('accounting.repost.description') }}</span>
            <input v-model="description" type="text"
              class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>

          <JournalLinesEditor ref="editorRef" v-model="lines" :accounts="accounts" list-id="repost-coa" />

          <!-- Posun data se NIKDY nedělá potichu: zamčené období nedovolí zapsat
               k původnímu datu, ale rozdíl v deníku uvidí až účetní závěrka. -->
          <label v-if="needsShiftConfirm" class="flex items-start gap-2 text-sm" data-test="repost-confirm-shift">
            <input v-model="confirmShift" type="checkbox" class="mt-0.5" />
            <span>
              {{ t('accounting.repost.confirm_date_shift', {
                from: formatDate(effectivePlan.entry_date),
                to: effectivePlan.target_date ? formatDate(effectivePlan.target_date) : '',
              }) }}
            </span>
          </label>

          <p class="text-xs text-neutral-500">{{ t('accounting.repost.hint') }}</p>
        </template>

        <!-- Poznámky zápisu — tatáž komponenta jako v deníku a u bankovního pohybu.
             Ukládají se hned a nezávisle na přeúčtování (jdou psát i u zablokovaného);
             přeúčtování stornem je přenese na nový zápis. -->
        <JournalEntryNotes ref="notesRef" :entry-id="plan.entry_id" data-test="repost-notes" />
      </template>

      <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-neutral-200">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.cancel') }}
        </button>
        <button v-if="dimsShown && dims.canEdit.value && dimsDirty" type="button" :class="btnOutline('primary')"
          class="whitespace-nowrap" :disabled="!canSaveDimensions" data-test="repost-save-dimensions" @click="saveDimensionsOnly">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" /></svg>
          {{ dimSaving ? t('common.saving') : t('accounting.repost.save_dimensions_only') }}
        </button>
        <button type="button" :class="btnFilled(notesOnly ? 'primary' : 'warning')" :disabled="!canSubmit" data-test="repost-submit" @click="submit">
          {{ saving ? t('common.saving') : (notesOnly ? t('accounting.repost.save_note') : t('accounting.repost.confirm')) }}
        </button>
      </div>
    </div>
  </Modal>
</template>
