<script setup lang="ts">
/*
 * Fronta odchozích mzdových podání.
 *
 * ─── Co to řeší ─────────────────────────────────────────────────────────────
 * „Co mám připravené a ještě to neodešlo?" — napříč VŠEMI agendami a všemi
 * zaměstnanci, bez ohledu na období. Období je důvod, proč to není další režim
 * přehledu podání: podání po lhůtě je typicky ze STARŠÍHO měsíce, než jaký má
 * účetní nastavený, takže by ho v přehledu nenašla.
 *
 * ─── Proč je jádrem HROMADNÉ odeslání ───────────────────────────────────────
 * Při stovce zaměstnanců a změnách skoro každý den je odesílání po jednom
 * nepoužitelné — účetní by za každou událostí musela projít kartu vztahu.
 * Fronta proto umí vybrat víc položek (i všechny připravené na stránce)
 * a odeslat je jedním úkonem.
 *
 * ─── Proč se dávka posílá po PORCÍCH ────────────────────────────────────────
 * Sto podání v jednom požadavku by běželo minuty a spadlo by na timeoutu
 * serveru nebo proxy — a nikdo by nevěděl, co z toho odešlo. Klient proto
 * posílá porce po `PAYROLL_QUEUE_BATCH_SIZE` a mezi nimi překresluje průběh:
 * prohlížeč zůstane responzivní, výsledek je vidět postupně a výpadek uprostřed
 * nechá už odeslané odeslané. Fronta na pozadí by uměla totéž, ale výsledek by
 * přestal být synchronní — a „nevím, jestli to odešlo" je přesně ten problém,
 * který tahle obrazovka řeší.
 *
 * ─── Proč se ukazuje i to, co odeslat nejde ─────────────────────────────────
 * Mlčky vynechaná položka je horší než položka s důvodem: uživatel neví,
 * jestli na ni zapomněl, nebo ji aplikace neumí.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { apiErrorMessage } from '@/api/errors'
import {
  PAYROLL_QUEUE_BATCH_SIZE,
  payrollApi,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionQueueBatchItemResult,
  type PayrollSubmissionQueueItem,
  type PayrollSubmissionQueueSort,
} from '@/api/payroll'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatDateTime, formatPeriod, formatUtcDateTime } from '@/composables/useFormat'

const props = defineProps<{ environment: PayrollRegzelEnvironment }>()
const emit = defineEmits<{ 'update:environment': [PayrollRegzelEnvironment] }>()

const { t } = useI18n()
const { submissionAgendaLabel, submissionStatusLabel, submissionKindLabel } = usePayrollLabels()

/*
 * Stránka je velká schválně: při stovce zaměstnanců je „vybrat vše" nad
 * padesátkou k ničemu. Strop drží server (200), takže se sem nedá vyžádat
 * neomezený seznam.
 */
const PAGE_SIZE = 100

const items = ref<PayrollSubmissionQueueItem[]>([])
const total = ref(0)
const offset = ref(0)
const agendaFilter = ref<string | null>(null)
const sort = ref<PayrollSubmissionQueueSort>('due')
const agendas = ref<{ agenda_code: string; count: number }[]>([])
const summary = ref({ total: 0, ready: 0, blocked: 0, overdue: 0 })
const loading = ref(true)
const error = ref('')
const selected = ref<Set<number>>(new Set())

/** Průběh dávky. `null` = žádná neběží. */
const batch = ref<{ done: number; totalCount: number } | null>(null)
/*
 * Výsledek poslední dávky zůstává na obrazovce, dokud uživatel nespustí
 * další. Kdyby zmizel s reloadem seznamu, účetní by po odeslání viděla jen to,
 * že řádky zmizely — a přesně to je situace „nevím, jestli to odešlo".
 */
const batchResult = ref<{
  sent: number
  failed: number
  failures: PayrollSubmissionQueueBatchItemResult[]
} | null>(null)
const sweepResult = ref<string | null>(null)
const sweeping = ref(false)

const page = computed(() => Math.floor(offset.value / PAGE_SIZE) + 1)
const dispatchable = computed(() => items.value.filter(item => item.dispatch.dispatchable))
const selectedCount = computed(() => selected.value.size)
const allDispatchableSelected = computed(() =>
  dispatchable.value.length > 0
  && dispatchable.value.every(item => selected.value.has(item.submission_id)))

const environmentModel = computed({
  get: () => props.environment,
  set: (value: PayrollRegzelEnvironment) => emit('update:environment', value),
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const response = await payrollApi.submissionQueue(props.environment, {
      limit: PAGE_SIZE,
      offset: offset.value,
      agenda_code: agendaFilter.value,
      sort: sort.value,
    })
    items.value = response.items
    total.value = response.total
    summary.value = response.summary
    agendas.value = response.agendas
    // Výběr se ořízne na to, co je po načtení pořád ve frontě a pořád
    // odeslatelné — jinak by dávka nesla ID, která už odešla.
    const stillSelectable = new Set(
      response.items.filter(item => item.dispatch.dispatchable)
        .map(item => item.submission_id),
    )
    selected.value = new Set(
      [...selected.value].filter(id => stillSelectable.has(id)),
    )
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.submissions.queue.load_failed'))
    items.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function toggle(item: PayrollSubmissionQueueItem): void {
  const next = new Set(selected.value)
  if (next.has(item.submission_id)) {
    next.delete(item.submission_id)
  } else if (item.dispatch.dispatchable) {
    next.add(item.submission_id)
  }
  selected.value = next
}

function toggleAll(): void {
  selected.value = allDispatchableSelected.value
    ? new Set()
    : new Set(dispatchable.value.map(item => item.submission_id))
}

function chunk<T>(list: T[], size: number): T[][] {
  const chunks: T[][] = []
  for (let index = 0; index < list.length; index += size) {
    chunks.push(list.slice(index, index + size))
  }
  return chunks
}

/** Odeslání jedné položky je dávka o velikosti jedna — jedna cesta, ne dvě. */
async function sendOne(item: PayrollSubmissionQueueItem): Promise<void> {
  await sendMany([item.submission_id])
}

async function sendSelected(): Promise<void> {
  await sendMany([...selected.value])
}

async function sendMany(ids: number[]): Promise<void> {
  if (ids.length === 0 || batch.value !== null) {
    return
  }
  batchResult.value = null
  batch.value = { done: 0, totalCount: ids.length }
  let sent = 0
  let failed = 0
  const failures: PayrollSubmissionQueueBatchItemResult[] = []

  try {
    for (const part of chunk(ids, PAYROLL_QUEUE_BATCH_SIZE)) {
      try {
        const response = await payrollApi.dispatchSubmissionBatch(
          props.environment,
          part.map(id => ({
            submission_id: id,
            // Klíč je vázaný na JEDNU položku a jedno kliknutí. Kdyby ho
            // sdílela celá dávka, druhá položka by se transportu jevila jako
            // opakování první a tiše by se neodeslala.
            idempotency_key: crypto.randomUUID(),
          })),
        )
        sent += response.summary.sent
        failed += response.summary.failed
        failures.push(...response.results.filter(result => !result.ok))
      } catch (exception) {
        // Spadlá PORCE nesmí zastavit zbytek dávky. Její položky se počítají
        // jako neúspěšné a zůstanou ve frontě k dalšímu pokusu.
        failed += part.length
        const message = apiErrorMessage(
          exception,
          t('payroll.submissions.queue.send_failed'),
        )
        failures.push(...part.map(id => ({
          ok: false,
          submission_id: id,
          dispatched: false,
          message,
        })))
      }
      batch.value = { done: batch.value.done + part.length, totalCount: ids.length }
    }
  } finally {
    batch.value = null
    batchResult.value = { sent, failed, failures }
    selected.value = new Set()
    // Fronta se načte znovu vždy, i po chybě: neúspěšný pokus se zapíše do
    // evidence a mění to, co u řádku stojí.
    await load()
  }
}

async function detectChanges(): Promise<void> {
  sweeping.value = true
  sweepResult.value = null
  try {
    const result = await payrollApi.detectPayrollChangesForCompany(props.environment)
    sweepResult.value = result.has_more
      ? t('payroll.submissions.queue.sweep_partial', {
        scanned: result.scanned,
        changed: result.changed,
      })
      : t('payroll.submissions.queue.sweep_done', {
        scanned: result.scanned,
        changed: result.changed,
      })
    await load()
  } catch (exception) {
    sweepResult.value = apiErrorMessage(
      exception,
      t('payroll.submissions.queue.sweep_failed'),
    )
  } finally {
    sweeping.value = false
  }
}

function deadlineClass(item: PayrollSubmissionQueueItem): string {
  if (item.deadline.is_overdue) {
    return 'bg-rose-100 text-rose-800'
  }
  if (item.deadline.phase === 'due_today' || item.deadline.phase === 'due_soon') {
    return 'bg-amber-100 text-amber-800'
  }
  return 'bg-neutral-100 text-neutral-700'
}

function dueLabel(item: PayrollSubmissionQueueItem): string {
  const days = item.deadline.days_to_due
  if (item.deadline.is_overdue) {
    return t('payroll.submissions.queue.overdue_by', { days: Math.abs(days) })
  }
  if (days === 0) {
    return t('payroll.submissions.queue.due_today')
  }
  return t('payroll.submissions.queue.due_in', { days })
}

watch(() => props.environment, () => {
  offset.value = 0
  selected.value = new Set()
  void load()
})

watch([agendaFilter, sort], () => {
  offset.value = 0
  void load()
})

void load()
</script>

<template>
  <section class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.submissions.queue.title') }}
        </h2>
        <p class="mt-1 max-w-2xl text-sm text-neutral-600">
          {{ t('payroll.submissions.queue.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <EnvironmentSwitch v-model="environmentModel" />
        <!--
          Bez tohohle běhu se hlásitelná změna zjistí jen tehdy, když někdo
          otevře kartu konkrétního zaměstnance — a osmidenní lhůta mezitím
          tiše uteče.
        -->
        <button
          type="button"
          :class="btnOutlineSm('neutral')"
          :disabled="sweeping || loading"
          data-test="queue-detect-changes"
          @click="detectChanges"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.search" />
          </svg>
          <span class="whitespace-nowrap">
            {{ sweeping
              ? t('payroll.submissions.queue.sweep_running')
              : t('payroll.submissions.queue.sweep') }}
          </span>
        </button>
        <button type="button" :class="btnOutlineSm('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          <span class="whitespace-nowrap">{{ t('common.refresh') }}</span>
        </button>
      </div>
    </header>

    <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
      {{ error }}
    </div>

    <div
      v-if="sweepResult"
      class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-800"
      data-test="queue-sweep-result"
    >
      {{ sweepResult }}
    </div>

    <!-- Průběh dávky. Mrtvý spinner bez čísel je při stovce položek k ničemu. -->
    <div
      v-if="batch"
      class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
      data-test="queue-batch-progress"
    >
      {{ t('payroll.submissions.queue.batch_progress', {
        done: batch.done,
        total: batch.totalCount,
      }) }}
      <div class="mt-2 h-1.5 w-full overflow-hidden rounded bg-primary-100">
        <div
          class="h-full bg-primary-600 transition-all"
          :style="{ width: `${Math.round((batch.done / batch.totalCount) * 100)}%` }"
        />
      </div>
    </div>

    <div
      v-if="batchResult && !batch"
      :class="[
        'rounded-lg border p-3 text-sm',
        batchResult.failed === 0
          ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
          : 'border-amber-200 bg-amber-50 text-amber-900',
      ]"
      data-test="queue-batch-result"
    >
      <p class="font-medium">
        {{ t('payroll.submissions.queue.batch_summary', {
          sent: batchResult.sent,
          failed: batchResult.failed,
        }) }}
      </p>
      <!--
        Co selhalo, se vypisuje jmenovitě i s důvodem. Souhrn „3 selhala" bez
        seznamu by účetní nechal hledat, která tři to byla.
      -->
      <ul v-if="batchResult.failures.length > 0" class="mt-2 space-y-1">
        <li v-for="failure in batchResult.failures" :key="failure.submission_id" class="text-xs">
          <span class="font-medium">#{{ failure.submission_id }}</span>
          — {{ failure.message }}
        </li>
      </ul>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="rounded-xl border border-neutral-200 bg-white p-3">
        <div class="text-xs uppercase text-neutral-500">
          {{ t('payroll.submissions.queue.summary_total') }}
        </div>
        <div class="mt-1 text-2xl font-semibold text-neutral-900">{{ summary.total }}</div>
      </div>
      <div class="rounded-xl border border-neutral-200 bg-white p-3">
        <div class="text-xs uppercase text-neutral-500">
          {{ t('payroll.submissions.queue.summary_ready') }}
        </div>
        <div class="mt-1 text-2xl font-semibold text-emerald-700">{{ summary.ready }}</div>
      </div>
      <div class="rounded-xl border border-neutral-200 bg-white p-3">
        <div class="text-xs uppercase text-neutral-500">
          {{ t('payroll.submissions.queue.summary_blocked') }}
        </div>
        <div class="mt-1 text-2xl font-semibold text-neutral-700">{{ summary.blocked }}</div>
      </div>
      <div class="rounded-xl border border-neutral-200 bg-white p-3">
        <div class="text-xs uppercase text-neutral-500">
          {{ t('payroll.submissions.queue.summary_overdue') }}
        </div>
        <div class="mt-1 text-2xl font-semibold text-rose-700">{{ summary.overdue }}</div>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 bg-white p-3">
      <label class="flex items-center gap-2 text-sm">
        <span class="text-neutral-600">{{ t('payroll.submissions.queue.filter_agenda') }}</span>
        <select v-model="agendaFilter" class="rounded border-neutral-300 text-sm" data-test="queue-filter-agenda">
          <option :value="null">{{ t('payroll.submissions.queue.filter_all') }}</option>
          <option v-for="facet in agendas" :key="facet.agenda_code" :value="facet.agenda_code">
            {{ submissionAgendaLabel(facet.agenda_code) }} ({{ facet.count }})
          </option>
        </select>
      </label>
      <label class="flex items-center gap-2 text-sm">
        <span class="text-neutral-600">{{ t('payroll.submissions.queue.sort') }}</span>
        <select v-model="sort" class="rounded border-neutral-300 text-sm" data-test="queue-sort">
          <option value="due">{{ t('payroll.submissions.queue.sort_due') }}</option>
          <option value="agenda">{{ t('payroll.submissions.queue.sort_agenda') }}</option>
        </select>
      </label>

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <span v-if="selectedCount > 0" class="text-sm text-neutral-600">
          {{ t('payroll.submissions.queue.selected', { count: selectedCount }) }}
        </span>
        <button
          type="button"
          :class="btnFilled('primary')"
          :disabled="selectedCount === 0 || batch !== null"
          data-test="queue-send-selected"
          @click="sendSelected"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.send" />
          </svg>
          <span class="whitespace-nowrap">
            {{ t('payroll.submissions.queue.send_selected', { count: selectedCount }) }}
          </span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="space-y-2">
      <div class="h-16 animate-pulse rounded-xl bg-neutral-100" />
      <div class="h-16 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="items.length === 0"
      :title="t('payroll.submissions.queue.empty_title')"
      :message="t('payroll.submissions.queue.empty_description')"
    />

    <template v-else>
      <div class="hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500">
            <tr>
              <th class="px-3 py-2">
                <input
                  type="checkbox"
                  :checked="allDispatchableSelected"
                  :disabled="dispatchable.length === 0 || batch !== null"
                  :aria-label="t('payroll.submissions.queue.select_all')"
                  data-test="queue-select-all"
                  @change="toggleAll"
                >
              </th>
              <th class="px-3 py-2">{{ t('payroll.submissions.queue.col_agenda') }}</th>
              <th class="px-3 py-2">{{ t('payroll.submissions.queue.col_subject') }}</th>
              <th class="px-3 py-2">{{ t('payroll.submissions.queue.col_period') }}</th>
              <th class="px-3 py-2">{{ t('payroll.submissions.queue.col_due') }}</th>
              <th class="px-3 py-2">{{ t('payroll.submissions.queue.col_status') }}</th>
              <th class="px-3 py-2 text-right">{{ t('payroll.submissions.queue.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="item in items"
              :key="item.submission_id"
              :class="item.deadline.is_overdue ? 'bg-rose-50/50' : ''"
              data-test="queue-row"
            >
              <td class="px-3 py-2 align-top">
                <input
                  type="checkbox"
                  :checked="selected.has(item.submission_id)"
                  :disabled="!item.dispatch.dispatchable || batch !== null"
                  :title="item.dispatch.blocked_reason ?? undefined"
                  :aria-label="t('payroll.submissions.queue.select_row')"
                  data-test="queue-select-row"
                  @change="toggle(item)"
                >
              </td>
              <td class="px-3 py-2 align-top">
                <div class="font-medium text-neutral-900">
                  {{ submissionAgendaLabel(item.agenda_code) }}
                </div>
                <div v-if="item.submission_kind !== 'regular'" class="text-xs text-neutral-500">
                  {{ submissionKindLabel(item.submission_kind) }}
                </div>
              </td>
              <td class="px-3 py-2 align-top text-neutral-700">
                {{ item.subject_label ?? '—' }}
              </td>
              <td class="px-3 py-2 align-top whitespace-nowrap text-neutral-700">
                {{ formatPeriod(item.period_start.slice(0, 7)) }}
              </td>
              <td class="px-3 py-2 align-top whitespace-nowrap">
                <div>{{ formatDate(item.due_on) }}</div>
                <span
                  :class="['mt-1 inline-block rounded px-1.5 py-0.5 text-xs', deadlineClass(item)]"
                >
                  {{ dueLabel(item) }}
                </span>
              </td>
              <td class="px-3 py-2 align-top">
                <div class="text-neutral-800">
                  {{ submissionStatusLabel(item.submission_status) }}
                </div>
                <!--
                  Důvod, proč to nejde odeslat, stojí přímo u řádku — ne za
                  ikonkou a ne v tooltipu. Je to ta informace, kvůli které se
                  účetní na frontu dívá.
                -->
                <p
                  v-if="item.dispatch.blocked_reason"
                  class="mt-1 max-w-md text-xs text-neutral-600"
                  data-test="queue-blocked-reason"
                >
                  {{ item.dispatch.blocked_reason }}
                </p>
                <p
                  v-if="item.attempt?.error_message"
                  class="mt-1 max-w-md text-xs text-rose-700"
                  data-test="queue-attempt-error"
                >
                  {{ t('payroll.submissions.queue.last_attempt_failed', {
                    when: item.attempt.sent_at
                      ? formatUtcDateTime(item.attempt.sent_at)
                      : formatDateTime(item.created_at),
                    reason: item.attempt.error_message,
                  }) }}
                </p>
              </td>
              <td class="px-3 py-2 align-top text-right">
                <button
                  type="button"
                  :class="btnFilledSm('primary')"
                  :disabled="!item.dispatch.dispatchable || batch !== null"
                  :title="item.dispatch.blocked_reason ?? undefined"
                  data-test="queue-send"
                  @click="sendOne(item)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.send" />
                  </svg>
                  <span class="whitespace-nowrap">{{ t('payroll.submissions.queue.send') }}</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid gap-3 md:hidden">
        <article
          v-for="item in items"
          :key="item.submission_id"
          class="rounded-xl border border-neutral-200 bg-white p-3"
        >
          <div class="flex items-start justify-between gap-2">
            <label class="flex items-start gap-2">
              <input
                type="checkbox"
                class="mt-1"
                :checked="selected.has(item.submission_id)"
                :disabled="!item.dispatch.dispatchable || batch !== null"
                @change="toggle(item)"
              >
              <span class="font-medium text-neutral-900">
                {{ submissionAgendaLabel(item.agenda_code) }}
              </span>
            </label>
            <span :class="['rounded px-1.5 py-0.5 text-xs', deadlineClass(item)]">
              {{ dueLabel(item) }}
            </span>
          </div>
          <dl class="mt-2 space-y-1 text-sm text-neutral-700">
            <div v-if="item.subject_label">
              <dt class="inline text-neutral-500">
                {{ t('payroll.submissions.queue.col_subject') }}:
              </dt>
              <dd class="inline"> {{ item.subject_label }}</dd>
            </div>
            <div>
              <dt class="inline text-neutral-500">
                {{ t('payroll.submissions.queue.col_due') }}:
              </dt>
              <dd class="inline"> {{ formatDate(item.due_on) }}</dd>
            </div>
            <div>
              <dt class="inline text-neutral-500">
                {{ t('payroll.submissions.queue.col_status') }}:
              </dt>
              <dd class="inline"> {{ submissionStatusLabel(item.submission_status) }}</dd>
            </div>
          </dl>
          <p v-if="item.dispatch.blocked_reason" class="mt-2 text-xs text-neutral-600">
            {{ item.dispatch.blocked_reason }}
          </p>
          <button
            type="button"
            :class="[btnFilledSm('primary'), 'mt-3 w-full justify-center']"
            :disabled="!item.dispatch.dispatchable || batch !== null"
            @click="sendOne(item)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            <span class="whitespace-nowrap">{{ t('payroll.submissions.queue.send') }}</span>
          </button>
        </article>
      </div>

      <PaginationBar
        embedded
        :page="page"
        :per-page="PAGE_SIZE"
        :total="total"
        @update:page="(value: number) => { offset = (value - 1) * PAGE_SIZE; void load() }"
      />
    </template>
  </section>
</template>
