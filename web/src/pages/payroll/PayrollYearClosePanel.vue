<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollYearCloseBlocker,
  type PayrollYearCloseStatusResponse,
  type PayrollYearCloseWarning,
  type PayrollYearCloseWarningItem,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDateTime, formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import { useRoute } from 'vue-router'
import type { RouteLocationRaw } from 'vue-router'

const props = defineProps<{ initialYear: number }>()
const { t, te } = useI18n()
const auth = useAuthStore()
const toast = useToast()
/*
 * Panel se montuje i mimo router (testy, náhledy), kde `useRoute()` vrací
 * `undefined`; rok z adresy je pohodlí navíc, ne podmínka funkčnosti.
 */
const route = useRoute() as ReturnType<typeof useRoute> | undefined

/**
 * Rok z prokliku „rok je uzavřený".
 *
 * Uzavřený rok blokuje zápis napříč agendami (nepřítomnosti, exekuce,
 * závazky, podání, běhy) a hláška o tom uměla jen říct „rok nejprve znovu
 * otevřete". Odkud přijde proklik, ví jen server — proto rok cestuje v adrese
 * a panel se na něj rovnou přepne, aby účetní nemusela hledat, který rok to
 * byl. Nesmyslná hodnota se ignoruje: dotaz v adrese může přepsat kdokoli.
 */
function yearFromRoute(): number | null {
  const raw = route?.query.yearCloseYear
  const value = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isInteger(value) && value >= 2000 && value <= 2200 ? value : null
}

const year = ref(yearFromRoute() ?? props.initialYear)
const data = ref<PayrollYearCloseStatusResponse | null>(null)
const loading = ref(false)
const saving = ref(false)
const reason = ref('')
const loadError = ref('')
let loadSequence = 0

const canApprove = computed(() => auth.canWrite('payroll.approve'))
const canReopen = computed(() => auth.canWrite('payroll.reopen'))
const closed = computed(() => data.value?.closure.status === 'closed')
const blockers = computed(() => data.value?.blockers ?? [])
/*
 * Nálezy, které rok NEDRŽÍ. Nedoložený odvod není chyba účetnictví: příkaz
 * odešel do banky v den výplaty, ABO výpis dorazí o týdny později a spáruje
 * se až při importu. Jako blokátor to znamenalo, že se rok nedal zavřít kvůli
 * papíru, který ještě nedošel. Účetní to má vidět i s jmenným seznamem
 * a rozhodnout se sama.
 */
const warnings = computed<PayrollYearCloseWarning[]>(
  () => data.value?.warnings ?? [],
)

function warningText(warning: PayrollYearCloseWarning): string {
  const key = `payroll.year_close.warning.${warning.code}`
  return te(key)
    ? t(key, { count: warning.count })
    : t('payroll.year_close.warning.unknown', { count: warning.count })
}

function warningItemText(item: PayrollYearCloseWarningItem): string {
  const kindKey = `payroll.payments.kind.${item.liability_kind}`
  const kind = te(kindKey) ? t(kindKey) : item.liability_kind
  const who = item.employee_name === null ? '' : ` — ${item.employee_name}`
  return `${formatPeriod(item.period)}: ${kind}${who} · ${formatMoneyMinor(item.uncovered_minor, item.currency_code)}`
}

function blockerText(blocker: PayrollYearCloseBlocker): string {
  if (blocker.code === 'missing_months') {
    // „2026-03" vedle „01.03.2026" ve zbytku appky vypadá jako useknuté datum.
    return t('payroll.year_close.blocker.missing_months', {
      months: (blocker.months ?? []).map(formatPeriod).join(', '),
    })
  }
  if (blocker.code === 'schema_unavailable') {
    return t('payroll.year_close.blocker.schema_unavailable')
  }
  const key = `payroll.year_close.blocker.${blocker.code}`
  // Nový kód překážky ze serveru se nesmí vypsat jako překladový klíč —
  // účetní by pod „Co brání uzavření" četla `payroll.year_close.blocker.foo`.
  return te(key) ? t(key, { count: blocker.count ?? 0 }) : t('payroll.year_close.blocker.unknown')
}

/**
 * Kam se jde překážka vyřešit.
 *
 * Seznam říkal „Čeká 5 neukončených zákonných podání" a končil tečkou —
 * účetní pak hledala v deseti záložkách, které to je. Uzávěrka se přitom
 * dělá jednou za rok, takže si tu cestu nikdo nepamatuje.
 */
const BLOCKER_TARGETS: Record<string, RouteLocationRaw> = {
  missing_months: { name: 'payroll-runs' },
  open_corrections: { name: 'payroll-runs' },
  open_submissions: { name: 'payroll-submissions-tab', params: { tab: 'monthly' } },
  open_liabilities: { name: 'payroll-payments' },
  open_leave: { name: 'payroll-absences' },
  open_enforcement: { name: 'payroll-enforcement' },
  reconciliation_differences: { name: 'payroll-posting-reconciliation' },
}

function blockerTarget(blocker: PayrollYearCloseBlocker): RouteLocationRaw | null {
  return BLOCKER_TARGETS[blocker.code] ?? null
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  loading.value = true
  loadError.value = ''
  try {
    const response = await payrollApi.yearCloseStatus(year.value)
    if (sequence === loadSequence) data.value = response
  } catch {
    if (sequence === loadSequence) {
      data.value = null
      loadError.value = t('payroll.year_close.load_failed')
    }
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function close(): Promise<void> {
  if (!data.value || blockers.value.length > 0 || !window.confirm(t('payroll.year_close.close_confirm', { year: year.value }))) return
  saving.value = true
  try {
    data.value.closure = await payrollApi.closeYear(year.value, data.value.closure.row_version)
    data.value.blockers = []
    data.value.warnings = []
    toast.success(t('payroll.year_close.closed'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'year_close_blocked') {
      data.value.blockers = error.response.data.error.blockers ?? []
    }
    toast.error(error?.response?.data?.error?.message || t('payroll.year_close.save_failed'))
    await load()
  } finally {
    saving.value = false
  }
}

async function reopen(): Promise<void> {
  if (!data.value || reason.value.trim().length < 10) return
  saving.value = true
  try {
    data.value.closure = await payrollApi.reopenYear(year.value, data.value.closure.row_version, reason.value.trim())
    reason.value = ''
    toast.success(t('payroll.year_close.reopened'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.year_close.save_failed'))
    await load()
  } finally {
    saving.value = false
  }
}

watch(year, () => void load())

/*
 * Proklik z jiné agendy míří na TUTÉŽ stránku (uzávěrka žije na mzdovém
 * rozcestníku), takže se panel nepřemontuje a rok z adresy by se jinak
 * projevil až po ručním obnovení. Ručně přepsaný rok se nepřepisuje zpátky —
 * reaguje se jen na změnu dotazu.
 */
watch(() => route?.query.yearCloseYear, () => {
  const requested = yearFromRoute()
  if (requested !== null) {
    year.value = requested
  }
})

onMounted(load)
</script>

<template>
  <section
    id="payroll-year-close"
    class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    data-test="payroll-year-close"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.year_close.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.year_close.description') }}</p>
      </div>
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('payroll.year_close.year') }}</span>
        <input v-model.number="year" type="number" min="2000" max="2200" class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900">
      </label>
    </div>

    <div v-if="loading" class="mt-4 h-16 animate-pulse rounded-lg bg-neutral-100" />
    <p v-else-if="loadError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ loadError }}</p>
    <template v-else-if="data">
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="closed ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-800'">
          {{ t(`payroll.year_close.status.${data.closure.status}`) }}
        </span>
        <span v-if="closed && data.closure.closed_at" class="text-xs text-neutral-500">
          {{ t('payroll.year_close.closed_at', { date: formatDateTime(data.closure.closed_at) }) }}
        </span>
      </div>

      <div v-if="!closed && blockers.length > 0" class="mt-4 rounded-lg border border-warning-500/40 bg-warning-50 p-3">
        <p class="text-sm font-medium text-warning-900">{{ t('payroll.year_close.blockers_title') }}</p>
        <ul class="mt-2 space-y-2 text-sm text-warning-800">
          <li
            v-for="blocker in blockers"
            :key="blocker.code"
            class="flex flex-wrap items-center gap-2"
            :data-test="`year-close-blocker-${blocker.code}`"
          >
            <span>{{ blockerText(blocker) }}</span>
            <RouterLink
              v-if="blockerTarget(blocker)"
              :to="blockerTarget(blocker)!"
              :class="btnOutlineSm('warning')"
              :data-test="`year-close-blocker-link-${blocker.code}`"
            >
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.edit" />
              </svg>
              {{ t('payroll.year_close.blocker_open') }}
            </RouterLink>
          </li>
        </ul>
      </div>

      <!--
        Varování, ne závora. Odvod bez doloženého výpisu rok nedrží — účetní
        vidí, o co jde, a rozhodne se sama.
      -->
      <div
        v-if="!closed && warnings.length > 0"
        class="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3"
        data-test="year-close-warnings"
      >
        <p class="text-sm font-medium text-neutral-800">{{ t('payroll.year_close.warnings_title') }}</p>
        <ul class="mt-2 space-y-2 text-sm text-neutral-700">
          <li
            v-for="warning in warnings"
            :key="warning.code"
            :data-test="`year-close-warning-${warning.code}`"
          >
            <div class="flex flex-wrap items-center gap-2">
              <span>{{ warningText(warning) }}</span>
              <RouterLink
                :to="{ name: 'payroll-payments' }"
                :class="btnOutlineSm('neutral')"
                :data-test="`year-close-warning-link-${warning.code}`"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.year_close.blocker_open') }}
              </RouterLink>
            </div>
            <ul class="mt-1 space-y-0.5 pl-4 text-xs text-neutral-600">
              <li v-for="item in warning.items" :key="item.liability_id">{{ warningItemText(item) }}</li>
              <li v-if="warning.truncated">{{ t('payroll.year_close.warning_truncated', { count: warning.count - warning.items.length }) }}</li>
            </ul>
          </li>
        </ul>
      </div>

      <div v-if="(!closed && canApprove) || (closed && canReopen)" class="mt-4 flex flex-wrap items-end gap-3">
        <template v-if="!closed">
          <button :class="btnFilled('success')" :disabled="saving || blockers.length > 0" @click="close">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
            {{ saving ? t('common.saving') : t('payroll.year_close.close') }}
          </button>
          <span v-if="blockers.length > 0" class="text-xs text-neutral-500">{{ t('payroll.year_close.blocked_hint') }}</span>
        </template>
        <template v-else>
          <label class="min-w-64 flex-1 text-sm text-neutral-700">
            <span class="mb-1 block text-xs font-medium">{{ t('payroll.year_close.reopen_reason') }}</span>
            <input v-model="reason" type="text" :placeholder="t('payroll.year_close.reopen_reason_placeholder')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" maxlength="1000">
          </label>
          <button :class="btnOutline('warning')" :disabled="saving || reason.trim().length < 10" @click="reopen">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
            {{ saving ? t('common.saving') : t('payroll.year_close.reopen') }}
          </button>
          <!--
            Délku důvodu vynucuje `PayrollYearCloseService` (min. 10 znaků) —
            znovuotevření uzavřeného roku je auditní stopa, ne poznámka. Než
            se to napsalo pod tlačítko, vypadalo zhasnuté tlačítko po vyplnění
            „ok" jako porucha.
          -->
          <span v-if="reason.trim().length < 10" class="text-xs text-neutral-500" data-test="year-close-reopen-hint">
            {{ t('payroll.year_close.reopen_reason_hint') }}
          </span>
        </template>
      </div>
      <!--
        Čtenář bez práva schvalovat viděl prázdno pod stavem a nevěděl, jestli
        se uzávěrka dělá jinde, nebo jestli na ni nemá právo.
      -->
      <p
        v-else
        class="mt-4 text-sm text-neutral-500"
        data-test="year-close-read-only"
      >
        {{ t(closed ? 'payroll.year_close.reopen_read_only' : 'payroll.year_close.close_read_only') }}
      </p>
    </template>
  </section>
</template>
