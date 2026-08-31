<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollMonthlyChecklistItem,
  type PayrollMonthlyChecklistResponse,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
import { localPayrollPeriod } from './payrollComponentsUi'

/*
 * Tenhle panel NEODESÍLÁ ani negeneruje sám — je to skladač nad existujícími
 * záložkami (viz `PayrollMonthlyChecklistService` na backendu). Tlačítko
 * u položky proto vždy vede na místo, kde se úkon reálně udělá; duplikovat
 * tam JMHZ náhledy, zdravotní karty nebo Mobilní klíč by znamenalo druhou
 * kopii pěti set řádků logiky, která se dřív nebo později rozejde s první.
 */
const props = defineProps<{
  environment: PayrollRegzelEnvironment
}>()
const emit = defineEmits<{
  'update:environment': [value: PayrollRegzelEnvironment]
}>()

const { t } = useI18n()
const { submissionAgendaLabel, submissionStatusLabel } = usePayrollLabels()
const environmentModel = computed({
  get: () => props.environment,
  set: (value: PayrollRegzelEnvironment) => emit('update:environment', value),
})
const period = ref(localPayrollPeriod())
const loading = ref(true)
const error = ref('')
const response = ref<PayrollMonthlyChecklistResponse | null>(null)

const environmentOptions = computed(() => [
  { value: 'production' as const, label: t('payroll.regzel.environment.production') },
  { value: 'test' as const, label: t('payroll.regzel.environment.test') },
])

const items = computed(() => response.value?.items ?? [])
const summary = computed(() => response.value?.summary ?? {
  total: 0, send: 0, generate: 0, manual: 0, done: 0,
})

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
  if (item.source === 'checklist') return t(`payroll.people.checklist.${code}`)
  if (item.source !== 'submission') return item.agenda_label
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

function actionClass(kind: string): string {
  if (kind === 'send') return btnFilledSm('primary')
  if (kind === 'generate') return btnOutlineSm('accent')
  return btnOutlineSm('neutral')
}

function actionIcon(kind: string): string {
  if (kind === 'send') return ICONS.send
  if (kind === 'generate') return ICONS.doc
  return ICONS.x
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    response.value = await payrollApi.monthlyChecklist(props.environment, period.value)
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

watch([environmentModel, period], load)
onMounted(load)
</script>

<template>
  <section class="space-y-4" data-test="monthly-checklist-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.monthly_checklist.title') }}
          </h2>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t('payroll.submissions.monthly_checklist.description') }}
          </p>
        </div>
        <button type="button" :class="btnOutlineSm('neutral')" :disabled="loading" @click="load">
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
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.environment') }}
          <SearchableSelect
            v-model="environmentModel"
            class="mt-1"
            :options="environmentOptions"
            :clearable="false"
            accent="payroll"
            data-test="monthly-checklist-environment"
          />
        </label>
      </div>
    </div>

    <p
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="monthly-checklist-error"
    >
      {{ error }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <div v-for="index in 5" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="response">
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div
          v-for="entry in (['total', 'send', 'generate', 'manual', 'done'] as const)"
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
                  <span v-if="item.period" class="mt-0.5 block text-xs text-neutral-400">{{ item.period }}</span>
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
                    {{ t('payroll.submissions.monthly_checklist.done_label') }}
                  </span>
                  <template v-else>
                    <RouterLink
                      v-if="item.action.path"
                      :to="item.action.path"
                      :class="actionClass(item.action.kind)"
                      data-test="monthly-checklist-action"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="actionIcon(item.action.kind)" />
                      </svg>
                      {{ item.action.label }}
                    </RouterLink>
                    <span v-else class="text-xs text-neutral-500" data-test="monthly-checklist-action">
                      {{ item.action.label }}
                    </span>
                    <p
                      v-if="item.action.reason"
                      class="mt-1 max-w-xs text-xs text-neutral-500"
                      data-test="monthly-checklist-reason"
                    >
                      {{ item.action.reason }}
                    </p>
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
                {{ t('payroll.submissions.monthly_checklist.done_label') }}
              </span>
              <template v-else>
                <RouterLink
                  v-if="item.action.path"
                  :to="item.action.path"
                  :class="actionClass(item.action.kind)"
                >
                  {{ item.action.label }}
                </RouterLink>
                <span v-else class="text-xs text-neutral-500">{{ item.action.label }}</span>
                <p v-if="item.action.reason" class="mt-1 text-xs text-neutral-500">{{ item.action.reason }}</p>
              </template>
            </div>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>
