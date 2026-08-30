<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionInboxItem,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatDateTime } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const emit = defineEmits<{
  /** `null` = počet se nepodařilo zjistit; rodič pak odznak nevykreslí vůbec. */
  (e: 'update:open-count', count: number | null): void
}>()

const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const loading = ref(true)
const error = ref('')
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
// Vyřešené položky odfiltrovává SERVER (výchozí `status=unresolved`), takže
// `total` popisuje právě ty řádky, které tabulka ukáže. Dokud se filtrovalo
// tady, pager počítal i vyřešené: stránka měla míň řádků, než sliboval, a
// poslední mohla vyjít prázdná.
const items = ref<PayrollSubmissionInboxItem[]>([])
const summary = ref({ total: 0, open: 0, acknowledged: 0, snoozed: 0 })
const acknowledgingId = ref<number | null>(null)
const actionError = ref('')

const COLUMNS: ColumnDef[] = [
  { key: 'agenda', labelKey: 'payroll.submissions.inbox.agenda', required: true },
  { key: 'due_on', labelKey: 'payroll.submissions.inbox.due_on' },
  { key: 'problem', labelKey: 'payroll.submissions.inbox.problem_label' },
  { key: 'status', labelKey: 'payroll.submissions.inbox.status_label' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-submission-inbox', COLUMNS)

const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

const snoozeTarget = ref<PayrollSubmissionInboxItem | null>(null)
const snoozeUntilInput = ref('')
const snoozeReason = ref('')
const snoozeError = ref('')
const snoozing = ref(false)

const environmentOptions = computed(() => [
  { value: 'production' as const, label: t('payroll.regzel.environment.production') },
  { value: 'test' as const, label: t('payroll.regzel.environment.test') },
])

function escalationClass(item: PayrollSubmissionInboxItem): string {
  if (item.escalation_level === 'overdue') return 'bg-danger-50 text-danger-700'
  if (item.escalation_level === 'due_today') return 'bg-warning-50 text-warning-700'
  return 'bg-payroll-50 text-payroll-700'
}

function problemLabel(item: PayrollSubmissionInboxItem): string {
  return t(`payroll.submissions.inbox.problem.${item.problem_kind}`)
}

function statusClass(status: string): string {
  if (status === 'acknowledged') return 'bg-success-50 text-success-700'
  if (status === 'snoozed') return 'bg-neutral-100 text-neutral-700'
  return 'bg-payroll-50 text-payroll-700'
}

function statusLabel(status: string): string {
  return t(`payroll.submissions.inbox.status.${status}`)
}

async function load() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  try {
    const response = await payrollApi.submissionInbox(environment.value, {
      limit: pageSize,
      offset: offset.value,
    })
    items.value = response.items
    total.value = response.total
    // Souhrn počítá server nad CELÝM inboxem — dopočítat ho ze stránky by
    // znamenalo hlásit odznakem jen tolik, kolik se zrovna vešlo na obrazovku.
    summary.value = response.summary
    emit('update:open-count', response.summary.total)
  } catch (exception) {
    items.value = []
    total.value = 0
    summary.value = { total: 0, open: 0, acknowledged: 0, snoozed: 0 }
    // Ať rodič odznak schová — zastaralý počet je horší než žádný.
    emit('update:open-count', null)
    error.value = apiErrorMessage(exception, t('payroll.submissions.inbox.load_failed'))
  } finally {
    loading.value = false
  }
}

async function acknowledge(item: PayrollSubmissionInboxItem) {
  if (acknowledgingId.value !== null) return
  actionError.value = ''
  acknowledgingId.value = item.id
  try {
    await payrollApi.acknowledgeSubmissionInboxItem(item.id, item.row_version)
    await load()
  } catch (exception) {
    actionError.value = apiErrorMessage(exception, t('payroll.submissions.inbox.acknowledge_failed'))
  } finally {
    acknowledgingId.value = null
  }
}

function openSnooze(item: PayrollSubmissionInboxItem) {
  snoozeTarget.value = item
  snoozeError.value = ''
  snoozeReason.value = ''
  const inHours = new Date(Date.now() + 24 * 60 * 60 * 1000)
  inHours.setSeconds(0, 0)
  snoozeUntilInput.value = new Date(inHours.getTime() - inHours.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16)
}

function closeSnooze() {
  if (snoozing.value) return
  snoozeTarget.value = null
}

async function confirmSnooze() {
  if (!snoozeTarget.value) return
  snoozeError.value = ''
  if (!snoozeUntilInput.value) {
    snoozeError.value = t('payroll.submissions.inbox.snooze_until_required')
    return
  }
  if (!snoozeReason.value.trim()) {
    snoozeError.value = t('payroll.submissions.inbox.snooze_reason_required')
    return
  }
  snoozing.value = true
  try {
    await payrollApi.snoozeSubmissionInboxItem(
      snoozeTarget.value.id,
      snoozeTarget.value.row_version,
      new Date(snoozeUntilInput.value).toISOString(),
      snoozeReason.value.trim(),
    )
    snoozeTarget.value = null
    await load()
  } catch (exception) {
    snoozeError.value = apiErrorMessage(exception, t('payroll.submissions.inbox.snooze_failed'))
  } finally {
    snoozing.value = false
  }
}

// Jiné prostředí = jiný obsah seznamu, takže stránka musí zpět na začátek.
watch(environment, () => {
  offset.value = 0
  void load()
})
onMounted(load)

defineExpose({ reload: load })
</script>

<template>
  <section class="space-y-4">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.inbox.title') }}
          </h2>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t('payroll.submissions.inbox.subtitle') }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <label class="mt-5 block max-w-xs text-sm font-medium text-neutral-700">
        {{ t('payroll.submissions.overview.environment') }}
        <SearchableSelect
          v-model="environment"
          class="mt-1"
          :options="environmentOptions"
          :clearable="false"
          accent="payroll"
          data-test="inbox-environment"
        />
      </label>
    </div>

    <p
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="inbox-error"
    >
      {{ error }}
    </p>
    <p
      v-if="actionError"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="inbox-action-error"
    >
      {{ actionError }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="index in 4" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else>
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div
          v-for="entry in (['total', 'open', 'acknowledged', 'snoozed'] as const)"
          :key="entry"
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        >
          <dt class="text-xs font-medium text-neutral-500">
            {{ t(`payroll.submissions.inbox.summary.${entry}`) }}
          </dt>
          <dd class="mt-1 text-2xl font-semibold text-neutral-900">
            {{ summary[entry] }}
          </dd>
        </div>
      </dl>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div v-if="items.length === 0" class="p-6 text-sm text-neutral-500" data-test="inbox-empty">
          {{ t('payroll.submissions.inbox.empty') }}
        </div>

        <template v-else>
          <div class="hidden items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex">
            <ColumnPicker :ctrl="tbl" />
            <DensityToggle :ctrl="tbl" />
          </div>
          <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th v-if="tbl.isVisible('agenda')" class="px-4 py-3">{{ t('payroll.submissions.inbox.agenda') }}</th>
                  <th v-if="tbl.isVisible('due_on')" class="px-4 py-3">{{ t('payroll.submissions.inbox.due_on') }}</th>
                  <th v-if="tbl.isVisible('problem')" class="px-4 py-3">{{ t('payroll.submissions.inbox.problem_label') }}</th>
                  <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.submissions.inbox.status_label') }}</th>
                  <th v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in items" :key="item.id" data-test="inbox-row">
                  <td v-if="tbl.isVisible('agenda')" class="px-4 py-3">
                    <span class="block font-medium text-neutral-900">{{ item.agenda_code }}</span>
                    <span class="block text-xs text-neutral-500">{{ item.subject_reference }}</span>
                  </td>
                  <td v-if="tbl.isVisible('due_on')" class="px-4 py-3 text-neutral-700">{{ formatDate(item.due_on) }}</td>
                  <td v-if="tbl.isVisible('problem')" class="px-4 py-3">
                    <span
                      class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="escalationClass(item)"
                      data-test="inbox-problem"
                    >
                      {{ problemLabel(item) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                      {{ statusLabel(item.status) }}
                    </span>
                    <span v-if="item.status === 'snoozed' && item.snoozed_until" class="mt-1 block text-xs text-neutral-500">
                      {{ t('payroll.submissions.inbox.snoozed_until_label', { at: formatDateTime(item.snoozed_until) }) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('actions')" class="px-4 py-3">
                    <div v-if="canWrite" class="flex flex-wrap justify-end gap-2">
                      <button
                        type="button"
                        :class="btnOutlineSm('success')"
                        :disabled="acknowledgingId !== null || item.status === 'acknowledged'"
                        data-test="inbox-acknowledge"
                        @click="acknowledge(item)"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.checkCircle" />
                        </svg>
                        {{ acknowledgingId === item.id
                          ? t('payroll.submissions.inbox.acknowledging')
                          : t('payroll.submissions.inbox.acknowledge') }}
                      </button>
                      <button
                        type="button"
                        :class="btnOutlineSm('warning')"
                        data-test="inbox-snooze"
                        @click="openSnooze(item)"
                      >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="ICONS.pause" />
                        </svg>
                        {{ t('payroll.submissions.inbox.snooze') }}
                      </button>
                    </div>
                    <span v-else class="text-xs text-neutral-400">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-lg border border-neutral-200 p-4" data-test="inbox-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ item.agenda_code }}</h3>
                <p class="mt-1 text-xs text-neutral-500">{{ item.subject_reference }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                {{ statusLabel(item.status) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.inbox.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.inbox.problem_label') }}</dt>
                <dd class="mt-1">
                  <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="escalationClass(item)">
                    {{ problemLabel(item) }}
                  </span>
                </dd>
              </div>
            </dl>
            <p v-if="item.status === 'snoozed' && item.snoozed_until" class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.submissions.inbox.snoozed_until_label', { at: formatDateTime(item.snoozed_until) }) }}
            </p>
            <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2">
              <button
                type="button"
                class="cursor-pointer flex-1"
                :class="btnOutline('success')"
                :disabled="acknowledgingId !== null || item.status === 'acknowledged'"
                @click="acknowledge(item)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.checkCircle" />
                </svg>
                {{ acknowledgingId === item.id
                  ? t('payroll.submissions.inbox.acknowledging')
                  : t('payroll.submissions.inbox.acknowledge') }}
              </button>
              <button type="button" class="cursor-pointer flex-1" :class="btnOutline('warning')" @click="openSnooze(item)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.pause" />
                </svg>
                {{ t('payroll.submissions.inbox.snooze') }}
              </button>
            </div>
          </article>
        </div>

        <PaginationBar
          embedded
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </section>
    </template>

    <Modal
      v-if="snoozeTarget"
      :title="t('payroll.submissions.inbox.snooze_modal_title')"
      width-class="max-w-md"
      @close="closeSnooze"
    >
      <div class="space-y-4">
        <p class="text-sm text-neutral-600">
          {{ snoozeTarget.agenda_code }} · {{ snoozeTarget.subject_reference }}
        </p>
        <p v-if="snoozeError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
          {{ snoozeError }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.inbox.snooze_until') }}
          <input
            v-model="snoozeUntilInput"
            type="datetime-local"
            data-test="snooze-until-input"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.inbox.snooze_reason') }}
          <textarea
            v-model="snoozeReason"
            data-test="snooze-reason-input"
            rows="3"
            :placeholder="t('payroll.submissions.inbox.snooze_reason_placeholder')"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          />
        </label>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" :class="btnOutline('neutral')" :disabled="snoozing" @click="closeSnooze">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnOutline('warning')" :disabled="snoozing" data-test="snooze-confirm" @click="confirmSnooze">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.pause" />
            </svg>
            {{ snoozing ? t('payroll.submissions.inbox.snoozing') : t('payroll.submissions.inbox.snooze_confirm') }}
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>
