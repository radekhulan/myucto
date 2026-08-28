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
 */
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmploymentSurchargePolicies,
  type PayrollEmploymentSurchargePolicy,
  type PayrollEmploymentSurchargePolicyPayload,
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

const props = defineProps<{
  employmentId: number
  canWrite: boolean
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

/** Pořadí polí odpovídá payloadu API; klíč je druh příplatku. */
const RATE_FIELDS: { kind: PayrollSurchargeKind, field: keyof PayrollEmploymentSurchargePolicyPayload }[] = [
  { kind: 'overtime', field: 'overtime_rate_bp' },
  { kind: 'holiday', field: 'holiday_rate_bp' },
  { kind: 'night', field: 'night_rate_bp' },
  { kind: 'weekend', field: 'weekend_rate_bp' },
  { kind: 'difficult_environment', field: 'difficult_environment_rate_bp' },
]

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

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

interface PolicyForm {
  valid_from: string
  overtime_mode: PayrollSurchargeCompensationMode
  holiday_mode: Exclude<PayrollSurchargeCompensationMode, 'included_in_wage'>
  difficult_environment_factors: string | number
  /**
   * Sazby se ZADÁVAJÍ v procentech, ukládají v bázových bodech (viz `toBasisPoints`).
   * Typ je unie, protože `v-model` nad `<input type="number">` hodnotu sám převádí
   * na číslo — model tedy nese jednou řetězec (prázdné pole) a jednou číslo.
   */
  rates: Record<PayrollSurchargeKind, string | number>
  agreement_reference: string
  note: string
}

function newForm(): PolicyForm {
  return {
    valid_from: localDate(),
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
    agreement_reference: '',
    note: '',
  }
}

const form = ref<PolicyForm>(newForm())

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

function kindInfo(kind: PayrollSurchargeKind): PayrollSurchargeKindInfo | undefined {
  return kinds.value.find(info => info.kind === kind)
}

function statutoryPercent(kind: PayrollSurchargeKind): string {
  return toPercent(kindInfo(kind)?.statutory_rate_basis_points ?? null)
}

/**
 * Sazby, které jsou pod zákonným minimem u druhu, kde se podlézt NESMÍ.
 * Slouží jen k upozornění — o přijetí rozhoduje server.
 */
const belowStatutory = computed(() => RATE_FIELDS
  .map(entry => entry.kind)
  .filter((kind) => {
    const info = kindInfo(kind)
    if (!info || info.allows_lower_agreed_rate) return false
    const agreed = toBasisPoints(form.value.rates[kind])
    return agreed !== null && agreed < info.statutory_rate_basis_points
  }))

const factorsValid = computed(() => {
  const raw = String(form.value.difficult_environment_factors ?? '').trim()
  if (raw === '') return true
  const value = Number(raw)
  return Number.isInteger(value) && value >= 1 && value <= 255
})

const validFromValid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.value.valid_from))
const valid = computed(() => validFromValid.value && factorsValid.value)

const saveDisabled = computed(() => saving.value || !valid.value)
const saveDisabledReason = computed(() => {
  if (saving.value) return ''
  if (!validFromValid.value) return t('payroll.people.surcharge_policy.valid_from_required')
  if (!factorsValid.value) return t('payroll.people.surcharge_policy.factors_invalid')
  return ''
})

const addDisabledReason = computed(() => (props.canWrite
  ? ''
  : t('payroll.people.surcharge_policy.no_permission')))

/** Verze bez konce platnosti je ta, která se právě používá. */
const currentPolicy = computed(() => policies.value.find(policy => policy.valid_to === null) ?? null)
const historyPolicies = computed(() => policies.value.filter(policy => policy.valid_to !== null))

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

function openNew() {
  form.value = newForm()
  const current = currentPolicy.value
  if (current) {
    // Nová verze vychází z té platné — zásada se nepřepisuje, přibývá vedle ní,
    // takže uživatel typicky mění jednu položku a zbytek chce zachovat.
    form.value.overtime_mode = current.overtime_mode
    form.value.holiday_mode = current.holiday_mode
    form.value.difficult_environment_factors = current.difficult_environment_factors === null
      ? ''
      : String(current.difficult_environment_factors)
    for (const entry of RATE_FIELDS) {
      form.value.rates[entry.kind] = policyRatePercent(current, entry)
    }
    form.value.agreement_reference = current.agreement_reference ?? ''
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

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const data = await payrollApi.employmentSurchargePolicies(props.employmentId)
    policies.value = data.policies
    kinds.value = data.kinds
    statutoryDefault.value = data.statutory_default
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
    const payload: PayrollEmploymentSurchargePolicyPayload = {
      valid_from: form.value.valid_from,
      overtime_mode: form.value.overtime_mode,
      holiday_mode: form.value.holiday_mode,
      difficult_environment_factors: factors === '' ? null : Number(factors),
      overtime_rate_bp: toBasisPoints(form.value.rates.overtime),
      holiday_rate_bp: toBasisPoints(form.value.rates.holiday),
      night_rate_bp: toBasisPoints(form.value.rates.night),
      weekend_rate_bp: toBasisPoints(form.value.rates.weekend),
      difficult_environment_rate_bp: toBasisPoints(form.value.rates.difficult_environment),
      agreement_reference: form.value.agreement_reference.trim() || null,
      note: form.value.note.trim() || null,
    }
    await payrollApi.createEmploymentSurchargePolicy(props.employmentId, payload)
    editorOpen.value = false
    toast.success(t('payroll.people.surcharge_policy.saved'))
    // Předchozí verze dostala uzavřenou platnost na serveru — historii je proto
    // nutné načíst znovu, dopočítat ji z odpovědi by znamenalo hádat.
    await load()
  } catch (error: unknown) {
    const code = apiCode(error)
    saveError.value = code === 'surcharge_policy_exists'
      ? t('payroll.people.surcharge_policy.exists_error')
      : (apiMessage(error) || t('payroll.people.surcharge_policy.save_failed'))
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
        <li v-for="entry in RATE_FIELDS" :key="entry.kind">
          {{ kindLabel(entry.kind) }}:
          <span v-if="policyRatePercent(currentPolicy, entry) !== ''">
            {{ policyRatePercent(currentPolicy, entry) }} %
          </span>
          <span v-else>{{ t('payroll.people.surcharge_policy.rate_statutory_used') }}</span>
        </li>
      </ul>
      <p v-if="currentPolicy.difficult_environment_factors !== null" class="mt-1 text-neutral-600">
        {{ t('payroll.people.surcharge_policy.factors') }}: {{ currentPolicy.difficult_environment_factors }}
      </p>
    </div>

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
        {{ t('payroll.people.surcharge_policy.new_title') }}
      </h5>
      <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.surcharge_policy.new_hint') }}</p>

      <div v-if="saveError" data-test="surcharge-policy-error" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
        {{ saveError }}
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.surcharge_policy.valid_from') }}
          <input
            v-model="form.valid_from"
            data-test="surcharge-policy-valid-from"
            type="date"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
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
        <label v-for="entry in RATE_FIELDS" :key="entry.kind" class="text-xs text-neutral-600">
          {{ kindLabel(entry.kind) }}
          <span class="text-neutral-400">({{ kindInfo(entry.kind)?.section }})</span>
          <input
            v-model="form.rates[entry.kind]"
            :data-test="`surcharge-policy-rate-${entry.kind}`"
            type="number"
            min="0"
            step="0.01"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
          >
          <span class="mt-1 block text-neutral-500">
            {{ t('payroll.people.surcharge_policy.statutory_minimum', { rate: statutoryPercent(entry.kind) }) }}
            <template v-if="kindInfo(entry.kind)?.allows_lower_agreed_rate">
              · {{ t('payroll.people.surcharge_policy.lower_allowed') }}
            </template>
            <template v-else>
              · {{ t('payroll.people.surcharge_policy.lower_forbidden') }}
            </template>
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
