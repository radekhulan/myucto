<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { pohodaScheduleApi, type PohodaScheduleConfig, type PohodaScheduleProblem, type PohodaScheduleRun } from '@/api/reports'

const { t } = useI18n()
const auth = useAuthStore()
// Stejné právo jako na serveru (PUT /reports/pohoda-schedule): příjemce exportu mění jen správce firmy.
const canSave = computed(() => auth.isDemo || auth.canWrite('settings.company.write'))

const config = ref<PohodaScheduleConfig>({ enabled: false, run_day: 5, include_credit_notes: true, recipient_email: '' })
const runs = ref<PohodaScheduleRun[]>([])
const problem = ref<PohodaScheduleProblem | null>(null)
const busy = ref(false)
const message = ref('')
const error = ref('')

// Výchozí měsíc pro „spustit teď" = uplynulý měsíc.
const prev = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1)
const lastClosedMonth = `${prev.getFullYear()}-${String(prev.getMonth() + 1).padStart(2, '0')}`
const month = ref(lastClosedMonth)

function apiError(e: unknown): string {
  return (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message || t('pohodaSchedule.failed')
}

async function load() {
  const r = await pohodaScheduleApi.get()
  config.value = r.config
  runs.value = r.runs
  problem.value = r.problem
}

async function act(fn: () => Promise<string>) {
  busy.value = true
  message.value = ''
  error.value = ''
  try {
    message.value = await fn()
    await load()
  } catch (e) {
    error.value = apiError(e)
  } finally {
    busy.value = false
  }
}

const save = () => act(async () => {
  await pohodaScheduleApi.save(config.value)
  return t('pohodaSchedule.saved')
})

const runNow = () => act(async () => {
  try {
    const r = await pohodaScheduleApi.run(month.value)
    if (r.status === 'error') throw new Error(r.error ?? '')
    return t('pohodaSchedule.run_done', { count: r.doc_count })
  } catch (e) {
    if ((e as { response?: { status?: number } })?.response?.status !== 409 || !window.confirm(t('pohodaSchedule.run_confirm'))) throw e
    const r = await pohodaScheduleApi.run(month.value, true)
    return t('pohodaSchedule.run_done', { count: r.doc_count })
  }
})

onMounted(() => { load().catch(e => { error.value = apiError(e) }) })
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
    <div>
      <h3 class="text-base font-semibold text-neutral-800">{{ t('pohodaSchedule.title') }}</h3>
      <p class="text-sm text-neutral-500">{{ t('pohodaSchedule.intro') }}</p>
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-800">
      <input v-model="config.enabled" :disabled="!canSave" type="checkbox" class="w-4 h-4 rounded border-neutral-300" />
      {{ t('pohodaSchedule.enabled') }}
    </label>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <label class="block text-sm">
        <span class="block font-medium text-neutral-700 mb-1">{{ t('pohodaSchedule.recipient') }}</span>
        <input v-model="config.recipient_email" :disabled="!canSave" type="email" class="w-full rounded-md border border-neutral-300 px-3 py-2" />
      </label>
      <label class="block text-sm">
        <span class="block font-medium text-neutral-700 mb-1">{{ t('pohodaSchedule.run_day') }}</span>
        <input v-model.number="config.run_day" :disabled="!canSave" type="number" min="1" max="28" class="w-full rounded-md border border-neutral-300 px-3 py-2" />
        <span class="block text-xs text-neutral-500 mt-1">{{ t('pohodaSchedule.run_day_hint') }}</span>
      </label>
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-800">
      <input v-model="config.include_credit_notes" :disabled="!canSave" type="checkbox" class="w-4 h-4 rounded border-neutral-300" />
      {{ t('pohodaSchedule.credit_notes') }}
    </label>
    <p class="text-xs text-neutral-500">{{ t('pohodaSchedule.scope_hint') }}</p>
    <p v-if="!canSave" class="text-xs text-warning-700">{{ t('pohodaSchedule.no_rights') }}</p>

    <div v-if="problem" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ t('pohodaSchedule.problem', { period: problem.period_label, count: problem.attempts }) }}
      <span v-if="problem.error">{{ problem.error }}</span>
    </div>
    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
    <div v-if="message" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">{{ message }}</div>

    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <input v-model="month" type="month" :max="lastClosedMonth" class="rounded-md border border-neutral-300 px-3 py-2 text-sm" />
        <button type="button" :disabled="busy || !month || month > lastClosedMonth" :class="btnOutline('primary')" @click="runNow">{{ t('pohodaSchedule.run_now') }}</button>
      </div>
      <button type="button" :disabled="busy || !canSave" :title="canSave ? undefined : t('pohodaSchedule.no_rights')" :class="btnFilled('primary')" @click="save">{{ t('pohodaSchedule.save') }}</button>
    </div>

    <div>
      <h4 class="text-sm font-medium text-neutral-700 mb-2">{{ t('pohodaSchedule.history') }}</h4>
      <p v-if="!runs.length" class="text-sm text-neutral-500">{{ t('pohodaSchedule.none') }}</p>
      <table v-else class="w-full text-sm">
        <thead class="text-left text-neutral-500">
          <tr>
            <th class="py-1 pr-3">{{ t('pohodaSchedule.col_period') }}</th>
            <th class="py-1 pr-3">{{ t('pohodaSchedule.col_when') }}</th>
            <th class="py-1 pr-3">{{ t('pohodaSchedule.col_count') }}</th>
            <th class="py-1">{{ t('pohodaSchedule.col_state') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in runs" :key="r.id" class="border-t border-neutral-100 align-top">
            <td class="py-1 pr-3">{{ r.period_label }}</td>
            <td class="py-1 pr-3">{{ r.created_at }} ({{ t(`pohodaSchedule.${r.trigger_by}`) }})</td>
            <td class="py-1 pr-3">{{ r.doc_count }}</td>
            <td class="py-1">
              <span :class="r.status === 'error' ? 'text-danger-500' : 'text-primary-700'">{{ t(`pohodaSchedule.${r.status}`) }}</span>
              <span v-if="r.attempts > 1" class="text-xs text-neutral-500"> ({{ t('pohodaSchedule.attempts', { count: r.attempts }) }})</span>
              <span v-if="r.error" class="block text-xs text-neutral-500">{{ r.error }}</span>
              <span v-if="r.changed_after" class="block text-xs text-warning-700">{{ t('pohodaSchedule.changed', { count: r.changed_after }) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
