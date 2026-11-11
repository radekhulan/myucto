<script setup lang="ts">
/**
 * Zásady zákonných příplatků § 114 až § 118 ZP na pracovním vztahu.
 *
 * ── Proč tenhle panel vzniká a proč to nebyla kosmetika ─────────────────────
 *
 * Tabulka `payroll_employment_surcharge_policies` je od migrace 1624 a výpočet
 * i materializace příplatků na ni od té doby spoléhají — jenže zapsat do ní
 * nešlo nic. Výsledek nebyl „chybějící featura", ale ZASEKNUTÝ MODUL:
 * materializace běží v téže transakci jako schválení docházky a je fail-closed,
 * takže měsíc s prací o svátku spadl na `holiday_arrangement_missing` a měsíc
 * ve ztíženém prostředí na `difficulty_factors_missing`. Uživatel s tím neměl
 * co dělat, protože obojí se sjednává právě tady. Tenhle panel je ta oprava.
 *
 * ── Co panel musí ukázat, i když zásada neexistuje ──────────────────────────
 *
 * Zákonný výchozí stav (`statutory_default`) se zobrazuje vždycky. U svátku je
 * to totiž NÁHRADNÍ VOLNO (§ 115 odst. 1), ne příplatek — a právě ten rozdíl je
 * to podstatné: bez sjednání podle odst. 2 nárok na příplatek nevzniká a modul
 * evidenci „za tenhle svátek bylo poskytnuto volno" nemá, takže bez zásady
 * měsíc neprojde. Kdyby panel výchozí stav neukazoval, uživatel by nevěděl,
 * co si vlastně sjednáním mění.
 *
 * ── Kogentní podlaha ────────────────────────────────────────────────────────
 *
 * Nižší než zákonnou sazbu smí sjednat jen § 116 (noc) a § 118 (víkend) — jen
 * ty mají větu „Je možné sjednat jinou minimální výši a způsob určení
 * příplatku". U § 114, § 115 a § 117 je „nejméně" kogentní. Klient na to
 * upozorní hned při psaní (`kinds[].allows_lower_agreed_rate`), ale ROZHODUJE
 * SERVER: tlačítko Uložit se kvůli tomu neblokuje a hláška ze serveru se
 * nenahrazuje vlastní — jinak by tu byly dvě autority a jedna z nich by
 * dřív nebo později lhala.
 *
 */
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollHourlyPolicyTerms,
  type PayrollRelationType,
  type PayrollEmploymentSurchargePolicies,
  type PayrollEmploymentSurchargePolicy,
  type PayrollEmploymentSurchargePolicyPayload,
  type PayrollEmploymentSurchargePolicyUpdatePayload,
  type PayrollSurchargeCompensationMode,
  type PayrollSurchargeKind,
  type PayrollSurchargeKindInfo,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import {
  BTN_DISABLED_NOTE,
  btnFilled,
  btnOutlineSm,
  disabledTitle,
  ICONS,
} from '@/components/ui/buttonStyles'
import PayrollHourlyPolicyTermsEditor from '@/components/payroll/PayrollHourlyPolicyTermsEditor.vue'
import PayrollHourlyPolicyTermsSummary from '@/components/payroll/PayrollHourlyPolicyTermsSummary.vue'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{
  employmentId: number
  canWrite: boolean
  hasHourlyTerms?: boolean
  relationType?: PayrollRelationType
}>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const editorOpen = ref(false)
const showValidation = ref(false)

const policies = ref<PayrollEmploymentSurchargePolicy[]>([])
const kinds = ref<PayrollSurchargeKindInfo[]>([])
const statutoryDefault = ref<PayrollEmploymentSurchargePolicies['statutory_default'] | null>(null)
const standbyInfo = ref<PayrollEmploymentSurchargePolicies['standby'] | null>(null)

/** Pořadí polí odpovídá payloadu API; klíč je druh příplatku. */
/*
 * Klíče jsou úzké literály, ne `keyof` payloadu: payload dědí z
 * `Record<string, unknown>`, takže `keyof` je prostě `string` a indexovat jím
 * konkrétní objekt sazeb nejde.
 */
type SurchargeRateField =
  | 'overtime_rate_bp'
  | 'holiday_rate_bp'
  | 'night_rate_bp'
  | 'weekend_rate_bp'
  | 'difficult_environment_rate_bp'

type SurchargeFixedField =
  | 'overtime_fixed_hourly_minor'
  | 'holiday_fixed_hourly_minor'
  | 'night_fixed_hourly_minor'
  | 'weekend_fixed_hourly_minor'
  | 'difficult_environment_fixed_hourly_minor'

const RATE_FIELDS: {
  kind: PayrollSurchargeKind
  field: SurchargeRateField
  fixedField: SurchargeFixedField
}[] = [
  { kind: 'overtime', field: 'overtime_rate_bp', fixedField: 'overtime_fixed_hourly_minor' },
  { kind: 'holiday', field: 'holiday_rate_bp', fixedField: 'holiday_fixed_hourly_minor' },
  { kind: 'night', field: 'night_rate_bp', fixedField: 'night_fixed_hourly_minor' },
  { kind: 'weekend', field: 'weekend_rate_bp', fixedField: 'weekend_fixed_hourly_minor' },
  {
    kind: 'difficult_environment',
    field: 'difficult_environment_rate_bp',
    fixedField: 'difficult_environment_fixed_hourly_minor',
  },
]

/** Nejvýše 1 000 Kč za hodinu — táž mez jako CHECK v migraci 1845. */
const FIXED_HOURLY_MAXIMUM_MINOR = 100_000

/** Způsob sjednání JEDNOHO druhu: procentem, nebo pevnou částkou za hodinu. */
type SurchargeAgreementForm = 'percent' | 'fixed'

const OVERTIME_MODES: PayrollSurchargeCompensationMode[] = [
  'surcharge',
  'compensatory_time_off',
  'included_in_wage',
]

/*
 * Svátek `included_in_wage` NENABÍZÍ. Mzda sjednaná s přihlédnutím k práci ve
 * svátek neexistuje — § 114 odst. 3 se týká výhradně práce přesčas. Server to
 * odmítne, takže nabídnout to by znamenalo poslat uživatele do slepé uličky.
 */
const HOLIDAY_MODES: Exclude<PayrollSurchargeCompensationMode, 'included_in_wage'>[] = [
  'compensatory_time_off',
  'surcharge',
]

interface PolicyForm {
  hourly_terms: PayrollHourlyPolicyTerms | null
  valid_from: string
  /**
   * Konec platnosti se zadává jen při OPRAVĚ a jen se doplňuje. Prázdno u
   * otevřené verze znamená „platí dál"; vyplněné datum ji uzavře přes vlastní
   * operaci, protože ukončení má jiná pravidla než změna obsahu.
   */
  valid_to: string
  overtime_mode: PayrollSurchargeCompensationMode
  holiday_mode: Exclude<PayrollSurchargeCompensationMode, 'included_in_wage'>
  difficult_environment_factors: string | number
  /**
   * Sazby se ZADÁVAJÍ v procentech, ukládají v bázových bodech (viz `toBasisPoints`).
   * Typ je unie, protože `v-model` nad `<input type="number">` hodnotu sám převádí
   * na číslo — model tedy nese jednou řetězec (prázdné pole) a jednou číslo.
   */
  rates: Record<PayrollSurchargeKind, string | number>
  /** Pevná částka se ZADÁVÁ v korunách, ukládá v haléřích (viz `toMinor`). */
  fixed: Record<PayrollSurchargeKind, string | number>
  /** Který ze dvou způsobů se u druhu sjednává. Obojí naráz server odmítne. */
  forms: Record<PayrollSurchargeKind, SurchargeAgreementForm>
  /** Sjednaná sazba odměny za pracovní pohotovost § 140 ZP v procentech; prázdno = zákonné minimum. */
  standby_rate: string | number
  agreement_reference: string
  note: string
}

function newForm(): PolicyForm {
  return {
    hourly_terms: null,
    valid_from: '',
    valid_to: '',
    overtime_mode: 'surcharge',
    holiday_mode: 'compensatory_time_off',
    difficult_environment_factors: '',
    rates: {
      overtime: '',
      holiday: '',
      night: '',
      weekend: '',
      difficult_environment: '',
    },
    fixed: {
      overtime: '',
      holiday: '',
      night: '',
      weekend: '',
      difficult_environment: '',
    },
    forms: {
      overtime: 'percent',
      holiday: 'percent',
      night: 'percent',
      weekend: 'percent',
      difficult_environment: 'percent',
    },
    standby_rate: '',
    agreement_reference: '',
    note: '',
  }
}

const form = ref<PolicyForm>(newForm())
const hourlyTermsValid = ref(true)
/** `null` = zakládá se nová verze; číslo = opravuje se otevřená verze s tímhle id. */
const editingId = ref<number | null>(null)
const editingVersion = ref(0)

/**
 * Procenta z formuláře na bázové body (25 % = 2500).
 *
 * Na drátě jde celé číslo desetitisícin, ne desetinné číslo: `DecimalRate` na
 * serveru je citlivá na kanonický tvar a řetězec z JSONu ani z ovladače databáze
 * kanonický zaručeně není. Celé číslo je jednoznačné a bezztrátové.
 */
function toBasisPoints(percent: string | number): number | null {
  const normalized = String(percent ?? '').trim().replace(',', '.')
  if (normalized === '') return null
  const value = Number(normalized)
  if (!Number.isFinite(value)) return null
  return Math.round(value * 100)
}

/** Opačný převod pro zobrazení: 2500 → „25". */
function toPercent(basisPoints: number | null | undefined): string {
  if (basisPoints === null || basisPoints === undefined) return ''
  return String(basisPoints / 100)
}

/**
 * Koruny z formuláře na haléře (75 Kč/h = 7500).
 *
 * Týž důvod jako u bázových bodů: na drátě jde celé číslo, protože desetinná
 * částka by se přes JSON a ovladač databáze vracela v tvaru, na který je
 * serverová aritmetika citlivá.
 */
function toMinor(amount: string | number): number | null {
  const normalized = String(amount ?? '').trim().replace(',', '.')
  if (normalized === '') return null
  const value = Number(normalized)
  if (!Number.isFinite(value)) return null
  return Math.round(value * 100)
}

/** Opačný převod pro zobrazení: 7500 → „75". */
function toMajor(minor: number | null | undefined): string {
  if (minor === null || minor === undefined) return ''
  return String(minor / 100)
}

function kindInfo(kind: PayrollSurchargeKind): PayrollSurchargeKindInfo | undefined {
  return kinds.value.find(info => info.kind === kind)
}

function statutoryPercent(kind: PayrollSurchargeKind): string {
  return toPercent(kindInfo(kind)?.statutory_rate_basis_points ?? null)
}

/**
 * Sazby, které jsou pod zákonným minimem u druhu, kde se podlézt NESMÍ.
 * Slouží jen k upozornění — o přijetí rozhoduje server.
 *
 * Týká se JEN sjednání procentem. U pevné částky se zákonné minimum odvíjí od
 * průměrného výdělku konkrétního člověka, který prohlížeč nezná — táž částka je
 * u jednoho nad minimem a u druhého pod ním, takže varovat tady by znamenalo
 * hádat. Posoudí to výpočet a rozdíl vykáže na výplatní pásce.
 */
const belowStatutory = computed(() => RATE_FIELDS
  .map(entry => entry.kind)
  .filter((kind) => {
    const info = kindInfo(kind)
    if (!info || info.allows_lower_agreed_rate) return false
    if (form.value.forms[kind] !== 'percent') return false
    const agreed = toBasisPoints(form.value.rates[kind])
    return agreed !== null && agreed < info.statutory_rate_basis_points
  }))

/** Pevná částka musí být kladná a v rozsahu, který server přijme. */
const fixedValid = computed(() => RATE_FIELDS.every((entry) => {
  if (form.value.forms[entry.kind] !== 'fixed') return true
  const raw = String(form.value.fixed[entry.kind] ?? '').trim()
  if (raw === '') return true
  const minor = toMinor(raw)
  return minor !== null && minor > 0 && minor <= FIXED_HOURLY_MAXIMUM_MINOR
}))

const factorsValid = computed(() => {
  const raw = String(form.value.difficult_environment_factors ?? '').trim()
  if (raw === '') return true
  const value = Number(raw)
  return Number.isInteger(value) && value >= 1 && value <= 255
})

/** Zákonné minimum odměny za pohotovost v bázových bodech (§ 140 ZP, 10 %). */
const standbyStatutoryBasisPoints = computed(() => standbyInfo.value?.statutory_rate_basis_points ?? 1000)

/** § 140 ZP nedovoluje sjednat méně než zákonné minimum; prázdno = minimum. */
const standbyValid = computed(() => {
  const raw = String(form.value.standby_rate ?? '').trim()
  if (raw === '') return true
  const agreed = toBasisPoints(raw)
  return agreed !== null && agreed >= standbyStatutoryBasisPoints.value && agreed <= 50_000
})

const validFromValid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.value.valid_from))
/**
 * Konec platnosti nesmí předcházet začátku. Server to hlídá taky (a hlídá navíc
 * návaznost na další verzi, kterou prohlížeč neuvidí), tohle je jen včasná věta
 * místo kolečka přes síť.
 */
const validToValid = computed(() => {
  const raw = form.value.valid_to.trim()
  if (raw === '') return true
  return /^\d{4}-\d{2}-\d{2}$/.test(raw) && raw >= form.value.valid_from
})
const valid = computed(() => validFromValid.value
  && factorsValid.value
  && validToValid.value
  && fixedValid.value
  && standbyValid.value
  && hourlyTermsValid.value)

const saveDisabled = computed(() => saving.value || !valid.value)
const saveDisabledReason = computed(() => {
  if (saving.value) return ''
  if (!hourlyTermsValid.value) return t('payroll.hourly_policy.invalid')
  if (!validFromValid.value) return t('payroll.people.surcharge_policy.valid_from_required')
  if (!factorsValid.value) return t('payroll.people.surcharge_policy.factors_invalid')
  if (!validToValid.value) return t('payroll.people.surcharge_policy.valid_to_invalid')
  if (!fixedValid.value) return t('payroll.people.surcharge_policy.fixed_invalid')
  if (!standbyValid.value) {
    return t('payroll.people.surcharge_policy.standby.invalid', {
      rate: toPercent(standbyStatutoryBasisPoints.value),
    })
  }
  return ''
})

const addDisabledReason = computed(() => (props.canWrite
  ? ''
  : t('payroll.people.surcharge_policy.no_permission')))

/** Verze bez konce platnosti je ta, která se právě používá. */
const currentPolicy = computed(() => policies.value.find(policy => policy.valid_to === null) ?? null)
const historyPolicies = computed(() => policies.value.filter(policy => policy.valid_to !== null))

const editDisabledReason = computed(() => {
  if (!props.canWrite) return t('payroll.people.surcharge_policy.no_permission')
  if (!currentPolicy.value) return t('payroll.people.surcharge_policy.edit_blocked_no_open')
  return ''
})

function modeLabel(mode: PayrollSurchargeCompensationMode): string {
  return t(`payroll.people.surcharge_policy.modes.${mode}`)
}

function kindLabel(kind: PayrollSurchargeKind): string {
  return t(`payroll.people.surcharge_policy.kinds.${kind}`)
}

function policyRatePercent(
  policy: PayrollEmploymentSurchargePolicy,
  entry: { kind: PayrollSurchargeKind, field: keyof PayrollEmploymentSurchargePolicyPayload },
): string {
  return toPercent(policy[entry.field] as number | null)
}

function policyFixedMajor(
  policy: PayrollEmploymentSurchargePolicy,
  entry: { fixedField: keyof PayrollEmploymentSurchargePolicyPayload },
): string {
  return toMajor(policy[entry.fixedField] as number | null)
}

/** Obsah sjednání z uložené verze do formuláře; účinnost si volající řeší sám. */
function fillFrom(policy: PayrollEmploymentSurchargePolicy) {
  form.value.hourly_terms = policy.hourly_terms ?? null
  form.value.overtime_mode = policy.overtime_mode
  form.value.holiday_mode = policy.holiday_mode
  form.value.difficult_environment_factors = policy.difficult_environment_factors === null
    ? ''
    : String(policy.difficult_environment_factors)
  for (const entry of RATE_FIELDS) {
    const fixed = policyFixedMajor(policy, entry)
    form.value.rates[entry.kind] = policyRatePercent(policy, entry)
    form.value.fixed[entry.kind] = fixed
    // Způsob se ODVOZUJE z toho, které pole je vyplněné — obojí naráz uložené
    // být nemůže. Prázdná verze (zákonné minimum) zůstává na procentu, protože
    // to je tvar, ve kterém zákon sazbu udává.
    form.value.forms[entry.kind] = fixed === '' ? 'percent' : 'fixed'
  }
  form.value.standby_rate = toPercent(policy.standby_rate_bp ?? null)
  form.value.agreement_reference = policy.agreement_reference ?? ''
  form.value.note = policy.note ?? ''
}

function openNew() {
  hourlyTermsValid.value = true
  form.value = newForm()
  editingId.value = null
  editingVersion.value = 0
  const current = currentPolicy.value
  if (current) {
    // Nová verze vychází z té platné — zásada se nepřepisuje, přibývá vedle ní,
    // takže uživatel typicky mění jednu položku a zbytek chce zachovat.
    fillFrom(current)
    form.value.note = ''
  } else if (statutoryDefault.value) {
    form.value.overtime_mode = statutoryDefault.value.overtime_mode
    if (statutoryDefault.value.holiday_mode !== 'included_in_wage') {
      form.value.holiday_mode = statutoryDefault.value.holiday_mode
    }
  }
  saveError.value = ''
  showValidation.value = false
  editorOpen.value = true
}

function openEdit(policy?: PayrollEmploymentSurchargePolicy) {
  const current = policy ?? currentPolicy.value
  if (!current) return
  hourlyTermsValid.value = true
  form.value = newForm()
  form.value.valid_from = current.valid_from
  form.value.valid_to = ''
  fillFrom(current)
  editingId.value = current.id
  editingVersion.value = current.row_version
  saveError.value = ''
  showValidation.value = false
  editorOpen.value = true
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const data = await payrollApi.employmentSurchargePolicies(props.employmentId)
    policies.value = data.policies
    kinds.value = data.kinds
    statutoryDefault.value = data.statutory_default
    standbyInfo.value = data.standby ?? null
  } catch (error: unknown) {
    loadError.value = apiMessage(error) || t('payroll.people.surcharge_policy.load_failed')
  } finally {
    loading.value = false
  }
}

async function save() {
  showValidation.value = true
  saveError.value = ''
  if (!props.canWrite || !valid.value) return

  saving.value = true
  try {
    const factors = String(form.value.difficult_environment_factors ?? '').trim()
    // Z dvojice se posílá VŽDY jen jedno; to druhé jde jako null, aby přepnutí
    // způsobu u opravy skutečně zrušilo předchozí sjednání. Ponechaná stará
    // hodnota by znamenala vyplněné obojí, což server i databáze odmítnou.
    // Klíče se vypisují ručně. Odvodit je z RATE_FIELDS přes Pick/Omit nejde:
    // payload dědí z `Record<string, unknown>`, takže odvozený typ má klíče
    // jen v unii a při rozbalení z něj kontrole nezbude nic.
    const rates = {
      overtime_rate_bp: null as number | null,
      holiday_rate_bp: null as number | null,
      night_rate_bp: null as number | null,
      weekend_rate_bp: null as number | null,
      difficult_environment_rate_bp: null as number | null,
      overtime_fixed_hourly_minor: null as number | null,
      holiday_fixed_hourly_minor: null as number | null,
      night_fixed_hourly_minor: null as number | null,
      weekend_fixed_hourly_minor: null as number | null,
      difficult_environment_fixed_hourly_minor: null as number | null,
    }
    for (const entry of RATE_FIELDS) {
      const usesFixed = form.value.forms[entry.kind] === 'fixed'
      rates[entry.field] = usesFixed ? null : toBasisPoints(form.value.rates[entry.kind])
      rates[entry.fixedField] = usesFixed ? toMinor(form.value.fixed[entry.kind]) : null
    }
    // Sjednání se skládá přímo do payloadu, ne přes mezitímní objekt: payload
    // dědí z `Record<string, unknown>`, takže `Omit`/`Pick` nad ním ztrácejí
    // známé klíče a rozbalení mezikroku by pro typovou kontrolu bylo prázdné.
    const agreed = {
      overtime_mode: form.value.overtime_mode,
      holiday_mode: form.value.holiday_mode,
      difficult_environment_factors: factors === '' ? null : Number(factors),
      standby_rate_bp: toBasisPoints(form.value.standby_rate),
      agreement_reference: form.value.agreement_reference.trim() || null,
      note: form.value.note.trim() || null,
      ...((props.hasHourlyTerms || form.value.hourly_terms !== null) ? { hourly_terms: form.value.hourly_terms } : {}),
    }

    const policyId = editingId.value
    if (policyId === null) {
      const payload: PayrollEmploymentSurchargePolicyPayload = {
        valid_from: form.value.valid_from,
        ...agreed,
        ...rates,
      }
      await payrollApi.createEmploymentSurchargePolicy(props.employmentId, payload)
    } else {
      const payload: PayrollEmploymentSurchargePolicyUpdatePayload = {
        valid_from: form.value.valid_from,
        ...agreed,
        ...rates,
        row_version: editingVersion.value,
      }
      const updated = await payrollApi.updateEmploymentSurchargePolicy(
        props.employmentId,
        policyId,
        payload,
      )
      const validTo = form.value.valid_to.trim()
      if (validTo !== '') {
        // Ukončení je vlastní operace s vlastními pravidly (díra, překryv), ale
        // pro uživatele je to jedno Uložit. Verze se bere z odpovědi opravy —
        // ta ji právě zvedla, takže původní hodnota by narazila na zámek.
        await payrollApi.closeEmploymentSurchargePolicy(props.employmentId, policyId, {
          valid_to: validTo,
          row_version: updated.row_version,
        })
      }
    }

    editorOpen.value = false
    toast.success(t('payroll.people.surcharge_policy.saved'))
    // Předchozí verze dostala uzavřenou platnost na serveru — historii je proto
    // nutné načíst znovu, dopočítat ji z odpovědi by znamenalo hádat.
    await load()
  } catch (error: unknown) {
    const code = apiCode(error)
    saveError.value = code === 'surcharge_policy_exists'
      ? t('payroll.people.surcharge_policy.exists_error')
      : code === 'surcharge_policy_history_locked'
        ? t('payroll.people.surcharge_policy.history_locked_error')
        : code === 'row_version_conflict'
          ? t('payroll.people.surcharge_policy.conflict_error')
          : (apiMessage(error) || t('payroll.people.surcharge_policy.save_failed'))
    // Po konfliktu i po odmítnutí kvůli historii je stav na obrazovce zastaralý;
    // bez přenačtení by druhý pokus narazil na tentýž zámek.
    if (code === 'row_version_conflict' || code === 'surcharge_policy_history_locked') {
      await load()
      const current = currentPolicy.value
      editingVersion.value = current && current.id === editingId.value ? current.row_version : 0
    }
  } finally {
    saving.value = false
  }
}

function apiMessage(error: unknown): string {
  if (!isAxiosError<{ error?: { message?: string } }>(error)) return ''
  return error.response?.data?.error?.message ?? ''
}

function apiCode(error: unknown): string {
  if (!isAxiosError<{ error?: { code?: string } }>(error)) return ''
  return error.response?.data?.error?.code ?? ''
}

onMounted(load)
</script>

<template>
  <section class="mt-4 border-t border-neutral-200 pt-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.people.surcharge_policy.title') }}
      </h4>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          data-test="surcharge-policy-edit"
          :class="btnOutlineSm('neutral')"
          :disabled="editDisabledReason !== ''"
          :title="disabledTitle(editDisabledReason !== '', editDisabledReason)"
          @click="openEdit()"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.edit" />
          </svg>
          {{ t('payroll.people.surcharge_policy.edit') }}
        </button>
        <button
          type="button"
          data-test="surcharge-policy-add"
          :class="btnOutlineSm('accent')"
          :disabled="!canWrite"
          :title="disabledTitle(!canWrite, addDisabledReason)"
          @click="openNew"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.people.surcharge_policy.add') }}
        </button>
      </div>
    </div>
    <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.surcharge_policy.hint') }}</p>
    <p v-if="addDisabledReason" :class="[BTN_DISABLED_NOTE, 'mt-1']">{{ addDisabledReason }}</p>

    <div v-if="loadError" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
      {{ loadError }}
    </div>

    <!--
      Zákonný výchozí stav se ukazuje i tehdy, když zásada existuje — bez něj
      uživatel nevidí, co si sjednáním mění. U svátku je to náhradní volno.
    -->
    <div
      v-if="statutoryDefault"
      data-test="surcharge-policy-statutory"
      class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
    >
      <p class="font-medium text-neutral-800">
        {{ t('payroll.people.surcharge_policy.statutory_title') }}
      </p>
      <p class="mt-1">
        {{ t('payroll.people.surcharge_policy.overtime_mode') }}:
        <span data-test="surcharge-policy-statutory-overtime">{{ modeLabel(statutoryDefault.overtime_mode) }}</span>
      </p>
      <p>
        {{ t('payroll.people.surcharge_policy.holiday_mode') }}:
        <span data-test="surcharge-policy-statutory-holiday">{{ modeLabel(statutoryDefault.holiday_mode) }}</span>
      </p>
      <p class="mt-1">{{ t('payroll.people.surcharge_policy.statutory_holiday_hint') }}</p>
    </div>

    <p
      v-if="!loading && policies.length === 0"
      data-test="surcharge-policy-empty"
      class="mt-2 text-xs text-neutral-500"
    >
      {{ t('payroll.people.surcharge_policy.empty') }}
    </p>

    <div v-if="currentPolicy" data-test="surcharge-policy-current" class="mt-2 rounded-md bg-payroll-50 px-3 py-2 text-xs">
      <p class="font-medium text-neutral-800">
        {{ t('payroll.people.surcharge_policy.current_title') }}
        · {{ currentPolicy.valid_from }} – {{ t('payroll.people.surcharge_policy.open_ended') }}
      </p>
      <p class="mt-1 text-neutral-600">
        {{ t('payroll.people.surcharge_policy.overtime_mode') }}: {{ modeLabel(currentPolicy.overtime_mode) }}
        · {{ t('payroll.people.surcharge_policy.holiday_mode') }}: {{ modeLabel(currentPolicy.holiday_mode) }}
      </p>
      <ul class="mt-1 space-y-0.5 text-neutral-600">
        <!--
          Pevná částka se ukazuje v korunách za hodinu, ne v procentech — jinak
          by u takto sjednaného druhu zůstalo prázdno a vypadalo by to, že
          sjednáno není nic.
        -->
        <li v-for="entry in RATE_FIELDS" :key="entry.kind">
          {{ kindLabel(entry.kind) }}:
          <span
            v-if="policyFixedMajor(currentPolicy, entry) !== ''"
            :data-test="`surcharge-policy-current-fixed-${entry.kind}`"
          >
            {{ policyFixedMajor(currentPolicy, entry) }}
            {{ t('payroll.people.surcharge_policy.fixed_unit') }}
          </span>
          <span v-else-if="policyRatePercent(currentPolicy, entry) !== ''">
            {{ policyRatePercent(currentPolicy, entry) }} %
          </span>
          <span v-else>{{ t('payroll.people.surcharge_policy.rate_statutory_used') }}</span>
        </li>
      </ul>
      <p v-if="currentPolicy.difficult_environment_factors !== null" class="mt-1 text-neutral-600">
        {{ t('payroll.people.surcharge_policy.factors') }}: {{ currentPolicy.difficult_environment_factors }}
      </p>
      <p class="mt-1 text-neutral-600" data-test="surcharge-policy-current-standby">
        {{ t('payroll.people.surcharge_policy.standby.label') }}:
        <template v-if="currentPolicy.standby_rate_bp !== null && currentPolicy.standby_rate_bp !== undefined">
          {{ toPercent(currentPolicy.standby_rate_bp) }} %
        </template>
        <template v-else>{{ t('payroll.people.surcharge_policy.rate_statutory_used') }}</template>
      </p>
    </div>

    <PayrollHourlyPolicyTermsSummary v-if="currentPolicy?.hourly_terms" :value="currentPolicy.hourly_terms" />

    <div v-if="historyPolicies.length > 0" class="mt-2">
      <p class="text-xs font-medium text-neutral-700">
        {{ t('payroll.people.surcharge_policy.history_title') }}
      </p>
      <ul class="mt-1 space-y-1">
        <li
          v-for="policy in historyPolicies"
          :key="policy.id"
          class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
        >
          <span class="font-medium text-neutral-800">{{ policy.valid_from }} – {{ policy.valid_to }}</span>
          · {{ t('payroll.people.surcharge_policy.overtime_mode') }}: {{ modeLabel(policy.overtime_mode) }}
          · {{ t('payroll.people.surcharge_policy.holiday_mode') }}: {{ modeLabel(policy.holiday_mode) }}
          <PayrollHourlyPolicyTermsSummary v-if="policy.hourly_terms" :value="policy.hourly_terms" />
          <button
            v-if="canWrite"
            type="button"
            :data-test="`surcharge-policy-edit-${policy.id}`"
            :class="btnOutlineSm('neutral')"
            @click="openEdit(policy)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
            {{ t('payroll.people.surcharge_policy.edit') }}
          </button>
        </li>
      </ul>
    </div>

    <form
      v-if="editorOpen"
      data-test="surcharge-policy-form"
      class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
      @submit.prevent="save"
    >
      <h5 class="text-xs font-semibold text-neutral-900">
        {{ editingId === null
          ? t('payroll.people.surcharge_policy.new_title')
          : t('payroll.people.surcharge_policy.edit_title') }}
      </h5>
      <p class="mt-1 text-xs text-neutral-500">
        {{ editingId === null
          ? t('payroll.people.surcharge_policy.new_hint')
          : t('payroll.people.surcharge_policy.edit_hint') }}
      </p>

      <div v-if="saveError" data-test="surcharge-policy-error" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
        {{ saveError }}
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.valid_from') }}
          <DateInput
            v-model="form.valid_from"
            data-test="surcharge-policy-valid-from"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100" />
          <span v-if="editingId !== null" class="mt-1 block text-neutral-500">
            {{ t('payroll.people.surcharge_policy.valid_from_locked') }}
          </span>
        </label>
        <!--
          Konec platnosti se jen DOPLŇUJE, a jen u opravy. Zásada se nemaže;
          uzavřít ji je jediný způsob, jak sjednání ukončit.
        -->
        <label v-if="editingId !== null && policies.find(policy => policy.id === editingId)?.valid_to === null" class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.valid_to') }}
          <DateInput
            v-model="form.valid_to"
            data-test="surcharge-policy-valid-to"
            :min="form.valid_from"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.surcharge_policy.valid_to_hint') }}</span>
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.factors') }}
          <input
            v-model="form.difficult_environment_factors"
            data-test="surcharge-policy-factors"
            type="number"
            min="1"
            max="255"
            step="1"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.surcharge_policy.factors_hint') }}</span>
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.overtime_mode') }}
          <select
            v-model="form.overtime_mode"
            data-test="surcharge-policy-overtime-mode"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
            <option v-for="mode in OVERTIME_MODES" :key="mode" :value="mode">{{ modeLabel(mode) }}</option>
          </select>
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.holiday_mode') }}
          <select
            v-model="form.holiday_mode"
            data-test="surcharge-policy-holiday-mode"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
            <option v-for="mode in HOLIDAY_MODES" :key="mode" :value="mode">{{ modeLabel(mode) }}</option>
          </select>
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.surcharge_policy.holiday_mode_hint') }}</span>
        </label>
      </div>

      <p class="mt-3 text-xs font-medium text-neutral-700">
        {{ t('payroll.people.surcharge_policy.rates_title') }}
      </p>
      <p class="text-xs text-neutral-500">{{ t('payroll.people.surcharge_policy.rates_hint') }}</p>

      <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div v-for="entry in RATE_FIELDS" :key="entry.kind" class="text-xs text-neutral-600">
          <span class="block">
            {{ kindLabel(entry.kind) }}
            <span class="text-neutral-400">({{ kindInfo(entry.kind)?.section }})</span>
          </span>
          <!--
            Přepínač je u KAŽDÉHO druhu zvlášť, ne jeden pro celou zásadu:
            přesčas procentem a víkend pevnou částkou je běžná kombinace.
          -->
          <select
            v-model="form.forms[entry.kind]"
            :data-test="`surcharge-policy-form-${entry.kind}`"
            :aria-label="t('payroll.people.surcharge_policy.form_label')"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
            <option value="percent">{{ t('payroll.people.surcharge_policy.form_percent') }}</option>
            <option value="fixed">{{ t('payroll.people.surcharge_policy.form_fixed') }}</option>
          </select>
          <input
            v-if="form.forms[entry.kind] === 'percent'"
            v-model="form.rates[entry.kind]"
            :data-test="`surcharge-policy-rate-${entry.kind}`"
            type="number"
            min="0"
            step="0.01"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
          <div v-else class="mt-1 flex items-center gap-2">
            <input
              v-model="form.fixed[entry.kind]"
              :data-test="`surcharge-policy-fixed-${entry.kind}`"
              type="number"
              min="0"
              step="0.01"
              :disabled="!canWrite"
              class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            >
            <span class="shrink-0 text-neutral-500">
              {{ t('payroll.people.surcharge_policy.fixed_unit') }}
            </span>
          </div>
          <span v-if="form.forms[entry.kind] === 'percent'" class="mt-1 block text-neutral-500">
            {{ t('payroll.people.surcharge_policy.statutory_minimum', { rate: statutoryPercent(entry.kind) }) }}
            <template v-if="kindInfo(entry.kind)?.allows_lower_agreed_rate">
              · {{ t('payroll.people.surcharge_policy.lower_allowed') }}
            </template>
            <template v-else>
              · {{ t('payroll.people.surcharge_policy.lower_forbidden') }}
            </template>
          </span>
          <!--
            U pevné částky se zákonné minimum ukázat NEDÁ: odvíjí se od průměrného
            výdělku konkrétního člověka, takže táž částka je u jednoho nad minimem
            a u druhého pod ním. Posoudí to výpočet.
          -->
          <span v-else class="mt-1 block text-neutral-500">
            {{ t('payroll.people.surcharge_policy.fixed_statutory_note') }}
          </span>
        </div>
      </div>
      <p class="mt-2 text-xs text-neutral-500">
        {{ t('payroll.people.surcharge_policy.fixed_hint') }}
      </p>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.standby.label') }}
          <span class="text-neutral-400">({{ standbyInfo?.section ?? '§ 140' }})</span>
          <input
            v-model="form.standby_rate"
            data-test="surcharge-policy-standby-rate"
            type="number"
            :min="toPercent(standbyStatutoryBasisPoints)"
            max="500"
            step="0.01"
            :disabled="!canWrite"
            :placeholder="toPercent(standbyStatutoryBasisPoints)"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
          <span class="mt-1 block text-neutral-500">
            {{ t('payroll.people.surcharge_policy.standby.hint', { rate: toPercent(standbyStatutoryBasisPoints) }) }}
          </span>
          <span
            v-if="showValidation && !standbyValid"
            data-test="surcharge-policy-standby-invalid"
            class="mt-1 block text-danger-700"
          >
            {{ t('payroll.people.surcharge_policy.standby.invalid', { rate: toPercent(standbyStatutoryBasisPoints) }) }}
          </span>
        </label>
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.agreement_reference') }}
          <input
            v-model="form.agreement_reference"
            type="text"
            maxlength="191"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.note') }}
          <input
            v-model="form.note"
            type="text"
            maxlength="500"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
        </label>
      </div>

      <PayrollHourlyPolicyTermsEditor v-if="hasHourlyTerms || form.hourly_terms" v-model="form.hourly_terms" :disabled="!canWrite || saving" :allow-monthly-assessment="relationType === 'employment' || relationType === 'small_scale_employment'" :allow-overtime="relationType === 'employment' || relationType === 'small_scale_employment'" :allow-premiums="relationType === 'dpp' || relationType === 'dpc'" @valid="hourlyTermsValid = $event" />

      <!--
        Varování, ne blokace: podlahu vyhodnocuje server a jeho rozhodnutí se tu
        nesmí ani potlačit, ani zdvojit. Tohle je jen včasná informace.
      -->
      <div
        v-if="belowStatutory.length > 0"
        data-test="surcharge-policy-below-statutory"
        class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-700"
        role="status"
      >
        {{ t('payroll.people.surcharge_policy.below_statutory_warning') }}
        <span>{{ belowStatutory.map(kindLabel).join(', ') }}</span>
      </div>

      <p v-if="showValidation && saveDisabledReason" :class="[BTN_DISABLED_NOTE, 'mt-3']">
        {{ saveDisabledReason }}
      </p>

      <!-- Jedno společné Uložit dole, žádné tlačítko u jednotlivých polí. -->
      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutlineSm('neutral')" @click="editorOpen = false">
          {{ t('common.cancel') }}
        </button>
        <button
          type="submit"
          data-test="surcharge-policy-save"
          :class="btnFilled('primary')"
          :disabled="saveDisabled"
          :title="disabledTitle(saveDisabled, saveDisabledReason)"
        >
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
      <p v-if="saveDisabled && saveDisabledReason && !showValidation" :class="[BTN_DISABLED_NOTE, 'mt-1 text-right']">
        {{ saveDisabledReason }}
      </p>
    </form>
  </section>
</template>
