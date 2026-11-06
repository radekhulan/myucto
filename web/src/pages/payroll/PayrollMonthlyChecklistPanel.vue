<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorCode, apiErrorMessage, externalJmhzSubmissionTarget } from '@/api/errors'
import { PAYROLL_JMHZ_LATE_DISCOUNT_CONFIRMATION } from '@/api/payrollTransportCodes'
import {
  PAYROLL_QUEUE_BATCH_SIZE,
  payrollApi,
  type PayrollMonthlyChecklistItem,
  type PayrollMonthlyChecklistResponse,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionDispatchMode,
} from '@/api/payroll'
import {
  dataBoxApi,
  type MobileKeyBatchItemResult,
  type MobileKeyReceiptSession,
  type ReceiptBatchResult,
} from '@/api/dataBox'
import { payrollSicknessCasesApi } from '@/api/payrollSicknessCases'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import IsdsBatchSendMethods from '@/components/submission/IsdsBatchSendMethods.vue'
import PayrollDispatchModeSelect from '@/components/payroll/PayrollDispatchModeSelect.vue'
import MobileKeyReceiptsButton from '@/components/submission/MobileKeyReceiptsButton.vue'
import PayrollLateDiscountConfirm from '@/components/payroll/PayrollLateDiscountConfirm.vue'
import ProductionSendConfirmDialog from '@/components/payroll/ProductionSendConfirmDialog.vue'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatPeriod } from '@/composables/useFormat'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
import { useProductionSendConfirm } from '@/composables/useProductionSendConfirm'
import { useReceiptFollowUp } from '@/composables/useReceiptFollowUp'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { payrollWorkingPeriod } from './payrollComponentsUi'

/*
 * Měsíční přehled říká, CO se za měsíc podává, a umí to rovnou připravit
 * a odeslat — bez přecházení po záložkách agend.
 *
 * Neodesílá ale vlastní logikou: příprava volá `prepareMonthlyChecklistItem`
 * (zmrazí podání ze schválené revize), odeslání jde přes frontu podání
 * (`dispatchSubmissionBatch`) — JMHZ přes VREP, je-li zvolený podpisový
 * certifikát, jinak datovou schránkou; přehledy pojišťovnám do odchozí fronty
 * datové schránky. Zprávy datové schránky se pak odešlou jedním přihlášením
 * zvoleným způsobem (`IsdsBatchSendMethods`, tentýž výběr jako fronta
 * „K odeslání") a po Mobilním klíči se doručenky v téže relaci dotáhnou samy
 * (`useReceiptFollowUp`).
 *
 * Odeslání vždy potvrzuje uživatel: v ostrém prostředí dialog s přehledem
 * co → komu → jakou cestou, u datové schránky navíc Mobilní klíč nebo
 * schválení konceptu v datovce.
 */
const props = defineProps<{
  environment: PayrollRegzelEnvironment
  /**
   * Období řízené zvenčí. Když se předá, panel je VLOŽENÝ do cizí obrazovky
   * (příprava mzdového běhu) a nesmí mít vlastní volbu měsíce ani prostředí —
   * dvě políčka pro tentýž měsíc na jedné stránce jsou past: účetní přepne
   * jedno, druhé zůstane, a čte pak dvě různá období vedle sebe.
   */
  period?: string
  /** Výchozí měsíc samostatného panelu (z adresy stránky podání). */
  initialPeriod?: string
  /**
   * Jen tyto agendy (kód nebo jeho začátek, např. `JMHZ`, `PPZ_2026`) —
   * akční karta nahoře záložky agendy. Bez filtru celý měsíc.
   */
  agendas?: string[]
  /** Akční karta: bez souhrnných dlaždic. */
  compact?: boolean
}>()
const emit = defineEmits<{
  'update:environment': [value: PayrollRegzelEnvironment]
}>()

const { t, te } = useI18n()
const { submissionAgendaLabel, submissionStatusLabel } = usePayrollLabels()
const environmentModel = computed({
  get: () => props.environment,
  set: (value: PayrollRegzelEnvironment) => emit('update:environment', value),
})
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment()
const ownPeriod = ref(props.initialPeriod ?? payrollWorkingPeriod())
/*
 * Výchozí měsíc samostatného panelu: nejstarší s nesplněným hlášením, jinak
 * předchozí (`suggested_period` ze serveru). Přepne se JEN jednou po prvním
 * načtení a jen tehdy, když si měsíc nikdo nevybral (adresa, ruční volba).
 */
let periodChosen = props.initialPeriod !== undefined
const embedded = computed(() => props.period !== undefined)
const period = computed({
  get: () => props.period ?? ownPeriod.value,
  set: (value: string) => {
    periodChosen = true
    ownPeriod.value = value
  },
})
const loading = ref(true)
const error = ref('')
const response = ref<PayrollMonthlyChecklistResponse | null>(null)
/** Klíč položky, jejíž podání se právě zakládá — ať nejde kliknout dvakrát. */
const preparing = ref('')
const prepareError = ref<Record<string, string>>({})
/** Varování kontroly 290 čekající na potvrzení, klíčované položkou. */
const lateDiscount = ref<Record<string, string>>({})
/** Proklik k chybě přípravy (měsíc podaný předchozím programem → přehled převzatých podání). */
const prepareErrorTarget = ref<Record<string, ReturnType<typeof externalJmhzSubmissionTarget>>>({})

/** Běží příprava nebo odeslání (řádek i „vše") — jedna operace naráz. */
const flowBusy = ref(false)
/** Chyba odeslání u konkrétního řádku (klíčováno klíčem položky). */
const sendError = ref<Record<string, string>>({})
/** Shrnutí posledního odeslání pod hlavičkou. */
const sendSummary = ref('')
/** Zprávy zařazené do odchozí fronty datové schránky, čekající na přihlášení. */
const databoxOutboxIds = ref<number[]>([])
/** Doporučení transportu ze zařazení (u jedné zprávy rozhoduje o bráně). */
const databoxTransport = ref<{ automatic: boolean; channel: string; reason: string | null } | null>(null)
/** Volba kanálu u řádku, kde jsou připravené oba (JMHZ: VREP i datovka). */
const modeChoice = ref<Record<string, PayrollSubmissionDispatchMode>>({})

function modeOf(item: PayrollMonthlyChecklistItem): PayrollSubmissionDispatchMode | null {
  return modeChoice.value[item.key] ?? item.dispatch_mode
}

function setMode(item: PayrollMonthlyChecklistItem, mode: PayrollSubmissionDispatchMode | null) {
  if (mode !== null) modeChoice.value = { ...modeChoice.value, [item.key]: mode }
}
/** Kterému řádku patří odchozí zpráva — kvůli chybě u správného řádku. */
const outboxItemKey = new Map<number, string>()
const receiptsBusy = ref('')

const {
  request: productionSendRequest,
  confirmProductionSend,
  settle: settleProductionSend,
} = useProductionSendConfirm()
const receiptFollowUp = useReceiptFollowUp(
  () => props.environment,
  async () => { await load(true) },
)
const followUpActive = receiptFollowUp.active

function matchesAgenda(code: string | null): boolean {
  if (!props.agendas || props.agendas.length === 0) return true
  if (code === null) return false
  return props.agendas.some(agenda => code === agenda || code.startsWith(agenda))
}

const items = computed(() => (response.value?.items ?? []).filter(item => matchesAgenda(item.agenda_code)))

/**
 * Odeslané zprávy datové schránky a kolik z nich má doložené doručení.
 * Bez doručenky přehled pojišťovně nedoběhne do „splněno" — účetní to musí
 * vidět u měsíce, ne až v Datové schránce.
 */
const receiptStats = computed(() => {
  const sent = items.value.filter(item =>
    item.dispatch !== null && item.dispatch !== undefined
    && ['sent', 'delivered'].includes(item.dispatch.dispatch_state),
  )
  const delivered = sent.filter(item => item.dispatch?.delivery_proof !== null).length
  return { total: sent.length, delivered, pending: sent.length - delivered }
})
const summary = computed(() => response.value?.summary ?? {
  total: 0, send: 0, generate: 0, manual: 0, await: 0, done: 0,
})

function isPreparable(item: PayrollMonthlyChecklistItem): boolean {
  return !item.done && item.action.prepare !== null && item.action.prepare !== undefined
}

function isDispatchable(item: PayrollMonthlyChecklistItem): boolean {
  return !item.done && item.dispatchable === true && item.submission_id !== null
}

/** Řádky, které „Připravit a odeslat vše" pokryje. */
const bulkItems = computed(() => items.value.filter(item => isPreparable(item) || isDispatchable(item)))

/** Nepřipravené podání případu dávky; připraví se stejně jako v Nemocenských případech. */
function isSicknessPreparable(item: PayrollMonthlyChecklistItem): boolean {
  return !item.done && item.status === 'not_prepared' && (item.action.sickness ?? null) !== null
}

/** Řádky, které „Jen připravit vše" pokryje: nic se neodesílá. */
const prepareAllItems = computed(() => items.value.filter(item => isPreparable(item) || isSicknessPreparable(item)))
const prepareAllSummary = ref('')

function phaseClass(phase: string): string {
  if (phase === 'fulfilled') return 'bg-success-50 text-success-700'
  if (phase === 'cancelled') return 'bg-neutral-100 text-neutral-600'
  if (['overdue', 'action_required'].includes(phase)) return 'bg-danger-50 text-danger-700'
  if (phase === 'due_today') return 'bg-warning-50 text-warning-700'
  if (phase === 'due_soon') return 'bg-payroll-50 text-payroll-700'
  if (phase === 'awaiting_result') return 'bg-primary-50 text-primary-700'
  return 'bg-neutral-100 text-neutral-700'
}

function phaseLabel(item: PayrollMonthlyChecklistItem): string {
  return t(`payroll.submissions.overview.deadline_phase.${item.phase}`, {
    count: Math.abs(item.days_to_due),
  })
}

/**
 * Backend posílá u `submission` a `checklist` jen surový kód (`agenda_code`
 * = `JMHZ25`/`HOZ_2026`/…, `item_key` = `social_jmhz_change`/…) — účetní
 * s ním nic neudělá, takže lidský název dodává tenhle panel.
 *
 * Dva slovníky podle toho, odkud kód je:
 *   - checklist item_key → `payroll.people.checklist.*` (karta zaměstnance
 *     tenhle slovník už má a je kompletní pro všech 14 klíčů),
 *   - submission agenda_code → sdílené `submissionAgendaLabel()`
 *     z `usePayrollLabels` — TATÁŽ funkce, kterou používá inbox a přehled
 *     podání, ať se lidský název nerozejde mezi panely.
 *
 * Ostatní zdroje (odvod, registrační změna, vyúčtování, nemocenský případ)
 * posílají už čitelný `agenda_label` z backendu — ten se použije beze změny.
 */
function agendaLabel(item: PayrollMonthlyChecklistItem): string {
  const code = item.agenda_code
  if (code === null) return item.agenda_label
  if (item.source === 'checklist') {
    // Neznámý klíč by se vypsal jako `payroll.people.checklist.foo` — účetní
    // by na řádku četla kus našeho zdrojáku. Popisek ze serveru je horší než
    // překlad, ale pořád je to věta o povinnosti.
    const key = `payroll.people.checklist.${code}`
    return te(key) ? t(key) : item.agenda_label
  }
  // `agenda_duty` nese TÝŽ kód agendy jako `submission` (JMHZ25, PPZ_2026) —
  // jen k němu ještě neexistuje podání. Kdyby se překládal jinak, četla by
  // účetní o téže povinnosti dva různé názvy podle toho, jestli už na ni
  // klikla.
  if (!['submission', 'agenda_duty', 'predecessor_jmhz', 'awaiting_run'].includes(item.source)) {
    return item.agenda_label
  }
  return submissionAgendaLabel(code)
}

/*
 * Zdroj `submission` nese SKUTEČNÝ stav podání (draft/ready/accepted/…) —
 * pro ten se použije sdílený slovník, který zná i platformu podání jinde
 * v appce. Ostatní prameny (odvod, checklist, registrace, vyúčtování,
 * nemocenský případ) nemají stav podání vůbec — nesou jen VLASTNÍ čtyři
 * stavy (viz `PayrollMonthlyChecklistService`), takže dostanou svůj malý
 * slovník. Neznámá hodnota z obou padá na poctivé „neznámý stav", ne na
 * tichý pád.
 */
const CUSTOM_STATUS_KEYS: Record<string, string> = {
  open: 'payroll.submissions.monthly_checklist.status.open',
  pending: 'payroll.submissions.monthly_checklist.status.pending',
  not_prepared: 'payroll.submissions.monthly_checklist.status.not_prepared',
  not_supported: 'payroll.submissions.monthly_checklist.status.not_supported',
}

function statusLabel(item: PayrollMonthlyChecklistItem): string {
  if (item.source === 'submission') return submissionStatusLabel(item.status)
  return t(CUSTOM_STATUS_KEYS[item.status] ?? 'payroll.submissions.monthly_checklist.status.unknown')
}

/*
 * Příprava povinnosti je HLAVNÍ krok toho řádku (bez ní se nedá nic dalšího
 * udělat), takže plná primární barva — stejně jako „Odeslat" u povinnosti,
 * která už podání má. Odkazy na cizí obrazovku zůstávají outline.
 */
function actionClass(item: PayrollMonthlyChecklistItem): string {
  if (item.action.prepare) return btnFilledSm('primary')
  if (item.action.kind === 'send') return btnFilledSm('primary')
  if (item.action.kind === 'generate') return btnOutlineSm('accent')
  if (item.action.kind === 'await') return btnOutlineSm('primary')
  return btnOutlineSm('neutral')
}

function actionIcon(item: PayrollMonthlyChecklistItem): string {
  if (item.action.kind === 'send') return ICONS.send
  if (item.action.kind === 'generate') return ICONS.doc
  if (item.action.kind === 'await') return ICONS.eye
  return ICONS.x
}

/** Zpráva odešla datovkou a dodání ještě není doložené — má smysl hledat doručenku. */
function awaitsReceipt(item: PayrollMonthlyChecklistItem): boolean {
  return !item.done
    && item.dispatch !== null
    && item.dispatch !== undefined
    && item.dispatch.delivery_proof === null
    && item.dispatch.dispatch_state === 'sent'
}

function channelLabel(item: PayrollMonthlyChecklistItem): string {
  const mode = modeOf(item)
  if (mode === 'vrep_jmhz' || mode === 'vrep_registration' || mode === 'vrep_cssz_form') {
    return t('payroll.submissions.monthly_checklist.send.channel_vrep')
  }
  if (mode === 'isds_health' || mode === 'isds_payroll') {
    return t('payroll.submissions.monthly_checklist.send.channel_isds')
  }
  return item.channel.label ?? t('payroll.submissions.monthly_checklist.unknown')
}

/**
 * Založí podání pro jednu povinnost a ZŮSTANE na místě — řádek se po
 * načtení změní na „Odeslat". Dřív příprava přesměrovala na záložku agendy
 * a účetní tam hledala, co dál.
 *
 * Když příprava selže (chybí variabilní symbol účtárny, revize se mezitím
 * změnila), zůstane hláška U TÉ POLOŽKY — nesmí spadnout do společného pruhu
 * nahoře, kde by vypadala jako výpadek celého přehledu.
 *
 * @returns ID vzniklých podání, `null` při chybě nebo čekajícím potvrzení
 */
async function prepareOne(
  item: PayrollMonthlyChecklistItem,
  confirmLateDiscount = false,
): Promise<number[] | null> {
  const request = item.action.prepare
  if (!request) return null
  preparing.value = item.key
  prepareError.value = { ...prepareError.value, [item.key]: '' }
  lateDiscount.value = { ...lateDiscount.value, [item.key]: '' }
  prepareErrorTarget.value = { ...prepareErrorTarget.value, [item.key]: null }
  try {
    const result = await payrollApi.prepareMonthlyChecklistItem(
      props.environment,
      confirmLateDiscount ? { ...request, confirm_late_discount: true } : request,
    )
    return result.submission_ids ?? []
  } catch (exception) {
    // Kontrola 290: hlášení po splatnosti se slevou. Nezakazuje, ale chce
    // vědomé potvrzení účetní, a to přímo u položky.
    if (apiErrorCode(exception) === PAYROLL_JMHZ_LATE_DISCOUNT_CONFIRMATION) {
      lateDiscount.value = {
        ...lateDiscount.value,
        [item.key]: apiErrorMessage(exception, t('payroll.transport_delivery.late_discount_title')),
      }
      return null
    }
    prepareErrorTarget.value = { ...prepareErrorTarget.value, [item.key]: externalJmhzSubmissionTarget(exception) }
    prepareError.value = {
      ...prepareError.value,
      [item.key]: apiErrorMessage(
        exception,
        t('payroll.submissions.monthly_checklist.prepare_failed'),
      ),
    }
    return null
  } finally {
    preparing.value = ''
  }
}

/**
 * „Jen připravit vše": připraví každé nepřipravené podání měsíce včetně
 * nemocenských (NEMPRI, HZUPN) a nic neodešle. Chyba jedné položky dávku
 * nezastaví; zůstane u řádku jako u samostatné přípravy.
 */
async function prepareAll() {
  if (flowBusy.value) return
  flowBusy.value = true
  prepareAllSummary.value = ''
  let prepared = 0
  let failed = 0
  try {
    for (const item of [...prepareAllItems.value]) {
      const sickness = item.action.sickness ?? null
      if (sickness === null) {
        if ((await prepareOne(item)) === null) failed++
        else prepared++
        continue
      }
      preparing.value = item.key
      prepareError.value = { ...prepareError.value, [item.key]: '' }
      try {
        await payrollSicknessCasesApi.prepare(props.environment, sickness.case_id, sickness.document)
        prepared++
      } catch (exception) {
        failed++
        prepareError.value = {
          ...prepareError.value,
          [item.key]: apiErrorMessage(exception, t('payroll.submissions.monthly_checklist.prepare_failed')),
        }
      } finally {
        preparing.value = ''
      }
    }
    prepareAllSummary.value = t('payroll.submissions.monthly_checklist.send.prepare_all_summary', { prepared, failed })
    await load(true)
  } finally {
    flowBusy.value = false
  }
}

/** „Jen připravit" a potvrzení kontroly 290: připraví a zůstane na místě. */
async function prepare(item: PayrollMonthlyChecklistItem, confirmLateDiscount = false) {
  if (flowBusy.value) return
  flowBusy.value = true
  try {
    await prepareOne(item, confirmLateDiscount)
    await load(true)
  } finally {
    flowBusy.value = false
  }
}

/**
 * „Připravit a odeslat" (řádek i „vše"): nejdřív na pozadí připraví, co
 * připravené není, pak JEDNO potvrzení se seznamem co → komu → jakou
 * cestou a odeslání frontou podání.
 */
async function prepareAndSend(targets: PayrollMonthlyChecklistItem[]) {
  if (flowBusy.value || targets.length === 0) return
  flowBusy.value = true
  sendSummary.value = ''
  try {
    const submissionIds = new Set<number>()
    for (const item of targets) {
      if (isDispatchable(item)) submissionIds.add(item.submission_id as number)
    }
    let prepared = false
    for (const item of targets.filter(isPreparable)) {
      const ids = await prepareOne(item)
      ids?.forEach(id => submissionIds.add(id))
      prepared = true
    }
    if (prepared) await load(true)
    const sendable = items.value.filter(item =>
      isDispatchable(item) && submissionIds.has(item.submission_id as number),
    )
    if (sendable.length > 0) await sendItems(sendable)
  } finally {
    flowBusy.value = false
  }
}

async function sendItems(rows: PayrollMonthlyChecklistItem[]) {
  const confirmed = await confirmProductionSend(
    props.environment,
    t('payroll.submissions.monthly_checklist.send.confirm', { count: rows.length }),
    rows.map(item => ({
      what: [agendaLabel(item), item.period ? formatPeriod(item.period) : '']
        .filter(part => part !== '').join(' · '),
      to: item.recipient.label ?? item.subject ?? t('payroll.submissions.monthly_checklist.unknown'),
      channel: channelLabel(item),
    })),
  )
  if (!confirmed) return

  const byKey = new Map(rows.map(item => [item.submission_id as number, item.key]))
  sendError.value = {}
  databoxOutboxIds.value = []
  databoxTransport.value = null
  outboxItemKey.clear()
  let delivered = 0
  let failed = 0
  for (let index = 0; index < rows.length; index += PAYROLL_QUEUE_BATCH_SIZE) {
    const part = rows.slice(index, index + PAYROLL_QUEUE_BATCH_SIZE)
    try {
      const result = await payrollApi.dispatchSubmissionBatch(
        props.environment,
        part.map(item => {
          const mode = modeOf(item)
          return {
            submission_id: item.submission_id as number,
            // Klíč je vázaný na JEDNU položku a jedno kliknutí.
            idempotency_key: crypto.randomUUID(),
            ...(mode !== null ? { mode } : {}),
          }
        }),
      )
      for (const entry of result.results) {
        const key = byKey.get(entry.submission_id) ?? ''
        if (!entry.ok) {
          failed += 1
          sendError.value = { ...sendError.value, [key]: entry.message }
          continue
        }
        const outbox = entry.outbox ?? null
        if (outbox === null) {
          delivered += 1
          continue
        }
        outboxItemKey.set(outbox.id, key)
        databoxOutboxIds.value = [...databoxOutboxIds.value, outbox.id]
        databoxTransport.value = outbox.transport
      }
    } catch (exception) {
      // Spadlá PORCE nesmí zastavit zbytek. Její řádky dostanou hlášku.
      const message = apiErrorMessage(exception, t('payroll.submissions.queue.send_failed'))
      for (const item of part) {
        failed += 1
        sendError.value = { ...sendError.value, [item.key]: message }
      }
    }
  }
  modeChoice.value = {}
  sendSummary.value = t('payroll.submissions.monthly_checklist.send.summary', {
    sent: delivered,
    databox: databoxOutboxIds.value.length,
    failed,
  })
  await load(true)
}

/**
 * Jedno přihlášení odeslalo všechny zprávy dávky. Selhání jedné zprávy se
 * ukáže u jejího řádku, ostatní tím neutrpí. Po Mobilním klíči relace
 * zůstala otevřená a doručenky se v ní dotáhnou samy.
 */
async function onDataboxSent(
  results: MobileKeyBatchItemResult[],
  receiptSession: MobileKeyReceiptSession | null,
) {
  databoxOutboxIds.value = []
  let sent = 0
  for (const result of results) {
    if (result.dispatched) {
      sent += 1
      continue
    }
    const key = outboxItemKey.get(result.id)
    if (key) {
      sendError.value = {
        ...sendError.value,
        [key]: result.error_message ?? t('payroll.submissions.queue.send_failed'),
      }
    }
  }
  sendSummary.value = t('payroll.submissions.monthly_checklist.send.databox_sent', {
    sent,
    failed: results.length - sent,
  })
  receiptFollowUp.start(receiptSession)
  await load(true)
}

/** Doručenky stažené jedním přihlášením Mobilním klíčem (bez otevřené relace). */
async function onReceiptsLoaded(result: ReceiptBatchResult) {
  sendSummary.value = t('payroll.submissions.monthly_checklist.send.receipts_loaded', {
    attached: result.attached,
    pending: result.pending,
  })
  await load(true)
}

/**
 * „Načíst doručenky" u odeslaného řádku: v otevřené relaci Mobilního klíče
 * hned, jinak na obrazovku datové schránky (nové přihlášení se tu nespouští).
 */
async function loadReceipts(item: PayrollMonthlyChecklistItem): Promise<boolean> {
  receiptsBusy.value = item.key
  try {
    return await receiptFollowUp.now()
  } finally {
    receiptsBusy.value = ''
  }
}

async function load(silent = false) {
  if (!silent) loading.value = true
  error.value = ''
  try {
    const loaded = await payrollApi.monthlyChecklist(props.environment, period.value)
    const suggested = loaded.suggested_period
    if (!embedded.value && !periodChosen && suggested && suggested !== ownPeriod.value) {
      // Jednou: návrh serveru přebije výchozí měsíc a načte se znovu.
      periodChosen = true
      ownPeriod.value = suggested
      return
    }
    periodChosen = true
    response.value = loaded
  } catch (exception) {
    response.value = null
    error.value = apiErrorMessage(
      exception,
      t('payroll.submissions.monthly_checklist.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

watch([environmentModel, period], () => { void load() })
onMounted(() => { void load() })
// Podání odeslané nebo doručenka stažená v jiném okně mění stav položek měsíce.
useWindowRefresh(() => load(true), () => !loading.value && !flowBusy.value && preparing.value === '' && receiptsBusy.value === ''
  && databoxOutboxIds.value.length === 0)
</script>

<template>
  <section class="space-y-4" data-test="monthly-checklist-panel">
    <div v-if="!embedded" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.monthly_checklist.title') }}
          </h2>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t('payroll.submissions.monthly_checklist.description') }}
          </p>
        </div>
        <button type="button" :class="btnOutlineSm('neutral')" :disabled="loading" @click="load()">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.period') }}
          <input
            v-model="period"
            type="month"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
            data-test="monthly-checklist-period"
          >
        </label>
        <div v-if="submissionTestAllowed" class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.environment') }}
          <div class="mt-1">
            <EnvironmentSwitch
              v-model="environmentModel"
              :aria-label="t('payroll.submissions.overview.environment')"
              data-test="monthly-checklist-environment"
            />
          </div>
        </div>
      </div>
    </div>

    <!--
      Tlačítko patří k hlášce, ne jen do hlavičky panelu. Vložený panel
      (příprava mzdového běhu) hlavičku NEMÁ, takže po výpadku zbývala jen
      červená věta bez jakékoli cesty ven — a jediné, co pak šlo udělat, bylo
      přenačíst celou obrazovku a přijít o rozpracovaný běh.
    -->
    <div
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="monthly-checklist-error"
    >
      <p>{{ error }}</p>
      <button
        type="button"
        :class="[btnOutlineSm('danger'), 'mt-3']"
        :disabled="loading"
        data-test="monthly-checklist-retry"
        @click="load()"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.retry') }}
      </button>
    </div>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <div v-for="index in 5" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="response">
      <div
        v-if="bulkItems.length || prepareAllItems.length || prepareAllSummary || databoxOutboxIds.length || sendSummary || followUpActive || receiptStats.pending"
        class="rounded-xl border border-primary-500/30 bg-primary-50 p-4 text-sm shadow-sm"
        data-test="monthly-checklist-send-bar"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="max-w-2xl text-neutral-700">
            {{ bulkItems.length
              ? t('payroll.submissions.monthly_checklist.send.bar_hint', { count: bulkItems.length })
              : t('payroll.submissions.monthly_checklist.send.bar_nothing') }}
          </p>
          <button
            v-if="bulkItems.length"
            type="button"
            :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
            :disabled="flowBusy"
            data-test="monthly-checklist-send-all"
            @click="prepareAndSend(bulkItems)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ flowBusy
              ? t('payroll.submissions.monthly_checklist.send.working')
              : t('payroll.submissions.monthly_checklist.send.all', { count: bulkItems.length }) }}
          </button>
          <button
            v-if="prepareAllItems.length"
            type="button"
            :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
            :disabled="flowBusy"
            data-test="monthly-checklist-prepare-all"
            @click="prepareAll()"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.doc" />
            </svg>
            {{ t('payroll.submissions.monthly_checklist.send.prepare_all', { count: prepareAllItems.length }) }}
          </button>
        </div>
        <p v-if="prepareAllSummary" class="mt-2 text-neutral-800" data-test="monthly-checklist-prepare-all-summary">
          {{ prepareAllSummary }}
        </p>
        <p v-if="sendSummary" class="mt-2 text-neutral-800" data-test="monthly-checklist-send-summary">
          {{ sendSummary }}
        </p>
        <div
          v-if="databoxOutboxIds.length"
          class="mt-3 rounded-lg border border-primary-200 bg-surface p-3"
          data-test="monthly-checklist-databox"
        >
          <IsdsBatchSendMethods
            :key="databoxOutboxIds.join(',')"
            :outbox-ids="databoxOutboxIds"
            :environment="environment"
            :transport="databoxTransport"
            :gateway-start="dataBoxApi.gatewayStartPayroll"
            @sent="onDataboxSent"
          />
        </div>
        <p v-if="followUpActive" class="mt-3 text-neutral-600" data-test="monthly-checklist-receipts-following">
          {{ t('payroll.submissions.monthly_checklist.send.receipts_following') }}
        </p>
        <div
          v-else-if="receiptStats.pending"
          class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
          data-test="monthly-checklist-receipts-pending"
        >
          <p class="font-medium text-warning-800">
            {{ t('payroll.submissions.monthly_checklist.send.receipts_pending', {
              delivered: receiptStats.delivered,
              total: receiptStats.total,
            }) }}
          </p>
          <p class="mt-1 text-neutral-700">
            {{ t('payroll.submissions.monthly_checklist.send.receipts_pending_hint') }}
          </p>
          <MobileKeyReceiptsButton
            class="mt-2"
            :environment="environment"
            :pending="receiptStats.pending"
            @done="onReceiptsLoaded"
          />
        </div>
      </div>

      <dl v-if="!compact" class="grid grid-cols-2 gap-3 lg:grid-cols-6" data-test="monthly-checklist-summary">
        <div
          v-for="entry in (['total', 'send', 'generate', 'manual', 'await', 'done'] as const)"
          :key="entry"
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        >
          <dt class="text-xs font-medium text-neutral-500">
            {{ t(`payroll.submissions.monthly_checklist.summary.${entry}`) }}
          </dt>
          <dd class="mt-1 text-2xl font-semibold text-neutral-900">
            {{ summary[entry] }}
          </dd>
        </div>
      </dl>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div v-if="items.length === 0" class="p-6 text-sm text-neutral-500" data-test="monthly-checklist-empty">
          {{ t('payroll.submissions.monthly_checklist.empty') }}
        </div>

        <div v-else class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_agenda') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_document') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_recipient') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_channel') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_due') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.monthly_checklist.col_status') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.submissions.monthly_checklist.col_action') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in items" :key="item.key" data-test="monthly-checklist-row">
                <td class="px-4 py-3">
                  <span class="block font-medium text-neutral-900">{{ agendaLabel(item) }}</span>
                  <span v-if="item.subject" class="mt-0.5 block text-xs text-neutral-500">{{ item.subject }}</span>
                  <span v-if="item.period" class="mt-0.5 block text-xs text-neutral-400">{{ formatPeriod(item.period) }}</span>
                  <span
                    v-if="item.outside_period"
                    class="mt-1 inline-flex rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-800"
                    data-test="monthly-checklist-outside-period"
                  >
                    {{ t('payroll.submissions.monthly_checklist.outside_period') }}
                  </span>
                </td>
                <td class="px-4 py-3 text-neutral-700">
                  <span v-if="item.document.format" class="block">{{ item.document.format }}</span>
                  <span v-if="item.document.note" class="mt-0.5 block text-xs text-neutral-500">{{ item.document.note }}</span>
                  <span v-if="!item.document.format && !item.document.note" class="text-neutral-400">—</span>
                </td>
                <td class="px-4 py-3 text-neutral-700" data-test="monthly-checklist-recipient">
                  <span v-if="item.recipient.label" class="block">{{ item.recipient.label }}</span>
                  <span v-if="item.recipient.note" class="mt-0.5 block text-xs text-neutral-500">{{ item.recipient.note }}</span>
                  <span v-if="!item.recipient.label && !item.recipient.note" class="text-neutral-400">
                    {{ item.recipient.applicable
                      ? t('payroll.submissions.monthly_checklist.unknown')
                      : t('payroll.submissions.monthly_checklist.not_applicable') }}
                  </span>
                </td>
                <td class="px-4 py-3 text-neutral-700" data-test="monthly-checklist-channel">
                  <span v-if="item.channel.label" class="block">{{ item.channel.label }}</span>
                  <span v-if="item.channel.note" class="mt-0.5 block text-xs text-neutral-500">{{ item.channel.note }}</span>
                  <span v-if="!item.channel.label && !item.channel.note" class="text-neutral-400">
                    {{ item.channel.applicable
                      ? t('payroll.submissions.monthly_checklist.unknown')
                      : t('payroll.submissions.monthly_checklist.not_applicable') }}
                  </span>
                </td>
                <td class="px-4 py-3 text-neutral-700">
                  <span class="block">{{ formatDate(item.due_on) }}</span>
                  <span
                    class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="phaseClass(item.phase)"
                  >
                    {{ phaseLabel(item) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-neutral-700" data-test="monthly-checklist-status">
                  {{ statusLabel(item) }}
                </td>
                <td class="px-4 py-3 text-right">
                  <span
                    v-if="item.done"
                    class="inline-flex items-center gap-1 rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700"
                    data-test="monthly-checklist-done"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.check" />
                    </svg>
                    {{ item.fulfilled_by_delivery
                      ? t('payroll.submissions.monthly_checklist.done_by_delivery_label')
                      : t('payroll.submissions.monthly_checklist.done_label') }}
                  </span>
                  <p
                    v-if="item.done && item.fulfilled_by_delivery"
                    class="mt-1 max-w-xs text-xs text-neutral-500"
                    data-test="monthly-checklist-done-by-delivery"
                  >
                    {{ t('payroll.submissions.monthly_checklist.done_by_delivery_hint') }}
                  </p>
                  <template v-else-if="!item.done">
                    <div class="flex flex-wrap justify-end gap-2">
                      <button
                        v-if="isPreparable(item)"
                        type="button"
                        :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
                        :disabled="flowBusy"
                        data-test="monthly-checklist-prepare-send"
                        @click="prepareAndSend([item])"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.send" />
                        </svg>
                        {{ preparing === item.key
                          ? t('payroll.submissions.monthly_checklist.preparing')
                          : t('payroll.submissions.monthly_checklist.send.prepare_and_send') }}
                      </button>
                      <button
                        v-if="isPreparable(item)"
                        type="button"
                        :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
                        :disabled="flowBusy"
                        data-test="monthly-checklist-prepare"
                        @click="prepare(item)"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.doc" />
                        </svg>
                        {{ t('payroll.submissions.monthly_checklist.send.prepare_only') }}
                      </button>
                      <PayrollDispatchModeSelect
                        v-if="!isPreparable(item) && isDispatchable(item)"
                        :model-value="modeOf(item)"
                        :modes="item.dispatch_modes ?? []"
                        :primary-mode="null"
                        :disabled="flowBusy"
                        @update:model-value="mode => setMode(item, mode)"
                      />
                      <button
                        v-if="!isPreparable(item) && isDispatchable(item)"
                        type="button"
                        :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
                        :disabled="flowBusy"
                        data-test="monthly-checklist-send"
                        @click="prepareAndSend([item])"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.send" />
                        </svg>
                        {{ t('payroll.submissions.monthly_checklist.send.send') }}
                      </button>
                      <RouterLink
                        v-if="!isPreparable(item) && item.action.path"
                        :to="item.action.path"
                        :class="[isDispatchable(item) ? btnOutlineSm('neutral') : actionClass(item), 'whitespace-nowrap']"
                        data-test="monthly-checklist-action"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="isDispatchable(item) ? ICONS.eye : actionIcon(item)" />
                        </svg>
                        {{ isDispatchable(item) ? t('payroll.submissions.monthly_checklist.send.open') : item.action.label }}
                      </RouterLink>
                      <span v-else-if="!isPreparable(item)" class="text-xs text-neutral-500" data-test="monthly-checklist-action">
                        {{ item.action.label }}
                      </span>
                      <button
                        v-if="awaitsReceipt(item) && followUpActive"
                        type="button"
                        :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
                        :disabled="receiptsBusy === item.key"
                        data-test="monthly-checklist-receipts"
                        @click="loadReceipts(item)"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.download" />
                        </svg>
                        {{ t('payroll.submissions.monthly_checklist.send.load_receipts') }}
                      </button>
                      <span
                        v-else-if="awaitsReceipt(item)"
                        class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-800"
                        data-test="monthly-checklist-receipts"
                      >
                        {{ t('payroll.submissions.monthly_checklist.send.awaiting_receipt') }}
                      </span>
                    </div>
                    <p
                      v-if="item.action.reason"
                      class="mt-1 max-w-xs text-xs text-neutral-500"
                      data-test="monthly-checklist-reason"
                    >
                      {{ item.action.reason }}
                    </p>
                    <p
                      v-if="sendError[item.key]"
                      class="mt-1 max-w-xs text-xs text-danger-600"
                      role="alert"
                      data-test="monthly-checklist-send-error"
                    >
                      {{ sendError[item.key] }}
                    </p>
                    <p
                      v-if="prepareError[item.key]"
                      class="mt-1 max-w-xs text-xs text-danger-600"
                      role="alert"
                      data-test="monthly-checklist-prepare-error"
                    >
                      {{ prepareError[item.key] }}
                      <RouterLink
                        v-if="prepareErrorTarget[item.key]"
                        :to="prepareErrorTarget[item.key]!"
                        class="mt-1 block font-medium text-payroll-600 underline hover:text-payroll-700"
                        data-test="monthly-checklist-external-link"
                      >
                        {{ t('payroll.external_jmhz.open_history') }}
                      </RouterLink>
                    </p>
                    <PayrollLateDiscountConfirm
                      v-if="lateDiscount[item.key]"
                      class="mt-2 max-w-md text-left"
                      :message="lateDiscount[item.key]!"
                      :busy="preparing === item.key"
                      test-id="monthly-checklist-late-discount"
                      @confirm="prepare(item, true)"
                      @cancel="lateDiscount = { ...lateDiscount, [item.key]: '' }"
                    />
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article v-for="item in items" :key="item.key" class="rounded-lg border border-neutral-200 p-4" data-test="monthly-checklist-row">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ agendaLabel(item) }}</h3>
                <p v-if="item.subject" class="mt-1 text-xs text-neutral-500">{{ item.subject }}</p>
              </div>
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="phaseClass(item.phase)"
              >
                {{ phaseLabel(item) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.monthly_checklist.col_document') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ item.document.format ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.monthly_checklist.col_due') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.monthly_checklist.col_status') }}</dt>
                <dd class="mt-0.5 text-neutral-800" data-test="monthly-checklist-status">{{ statusLabel(item) }}</dd>
              </div>
            </dl>
            <div class="mt-4">
              <span
                v-if="item.done"
                class="inline-flex items-center gap-1 rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700"
              >
                {{ item.fulfilled_by_delivery
                  ? t('payroll.submissions.monthly_checklist.done_by_delivery_label')
                  : t('payroll.submissions.monthly_checklist.done_label') }}
              </span>
              <template v-else>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="isPreparable(item)"
                    type="button"
                    :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
                    :disabled="flowBusy"
                    @click="prepareAndSend([item])"
                  >
                    {{ preparing === item.key
                      ? t('payroll.submissions.monthly_checklist.preparing')
                      : t('payroll.submissions.monthly_checklist.send.prepare_and_send') }}
                  </button>
                  <button
                    v-if="isPreparable(item)"
                    type="button"
                    :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
                    :disabled="flowBusy"
                    @click="prepare(item)"
                  >
                    {{ t('payroll.submissions.monthly_checklist.send.prepare_only') }}
                  </button>
                  <PayrollDispatchModeSelect
                    v-if="!isPreparable(item) && isDispatchable(item)"
                    :model-value="modeOf(item)"
                    :modes="item.dispatch_modes ?? []"
                    :primary-mode="null"
                    :disabled="flowBusy"
                    @update:model-value="mode => setMode(item, mode)"
                  />
                  <button
                    v-if="!isPreparable(item) && isDispatchable(item)"
                    type="button"
                    :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
                    :disabled="flowBusy"
                    @click="prepareAndSend([item])"
                  >
                    {{ t('payroll.submissions.monthly_checklist.send.send') }}
                  </button>
                  <RouterLink
                    v-if="!isPreparable(item) && item.action.path"
                    :to="item.action.path"
                    :class="[isDispatchable(item) ? btnOutlineSm('neutral') : actionClass(item), 'whitespace-nowrap']"
                  >
                    {{ isDispatchable(item) ? t('payroll.submissions.monthly_checklist.send.open') : item.action.label }}
                  </RouterLink>
                  <span v-else-if="!isPreparable(item)" class="text-xs text-neutral-500">{{ item.action.label }}</span>
                  <button
                    v-if="awaitsReceipt(item) && followUpActive"
                    type="button"
                    :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
                    :disabled="receiptsBusy === item.key"
                    @click="loadReceipts(item)"
                  >
                    {{ t('payroll.submissions.monthly_checklist.send.load_receipts') }}
                  </button>
                  <span
                    v-else-if="awaitsReceipt(item)"
                    class="inline-flex items-center rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-800"
                  >
                    {{ t('payroll.submissions.monthly_checklist.send.awaiting_receipt') }}
                  </span>
                </div>
                <p v-if="item.action.reason" class="mt-1 text-xs text-neutral-500">{{ item.action.reason }}</p>
                <p v-if="sendError[item.key]" class="mt-1 text-xs text-danger-600" role="alert">{{ sendError[item.key] }}</p>
                <p
                  v-if="prepareError[item.key]"
                  class="mt-1 text-xs text-danger-600"
                  role="alert"
                >
                  {{ prepareError[item.key] }}
                  <RouterLink
                    v-if="prepareErrorTarget[item.key]"
                    :to="prepareErrorTarget[item.key]!"
                    class="mt-1 block font-medium text-payroll-600 underline hover:text-payroll-700"
                  >
                    {{ t('payroll.external_jmhz.open_history') }}
                  </RouterLink>
                </p>
                <PayrollLateDiscountConfirm
                  v-if="lateDiscount[item.key]"
                  class="mt-2"
                  :message="lateDiscount[item.key]!"
                  :busy="preparing === item.key"
                  test-id="monthly-checklist-late-discount-mobile"
                  @confirm="prepare(item, true)"
                  @cancel="lateDiscount = { ...lateDiscount, [item.key]: '' }"
                />
              </template>
            </div>
          </article>
        </div>
      </section>
    </template>
    <ProductionSendConfirmDialog
      v-if="productionSendRequest"
      :message="productionSendRequest.message"
      :items="productionSendRequest.items"
      @confirm="settleProductionSend(true)"
      @cancel="settleProductionSend(false)"
    />
  </section>
</template>
