<script setup lang="ts">
import { computed, onActivated, onDeactivated, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { instanceUpdatesApi, type InstanceUpdateStatus } from '@/api/instanceUpdates'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t, locale } = useI18n()
const status = ref<InstanceUpdateStatus | null>(null)
const loading = ref(false)
const error = ref(false)
const busy = ref(false)
const notice = ref('')
const canTrigger = computed(() => status.value?.has_update && status.value.latest && !status.value.cache_stale && !status.value.check_failed && !status.value.upgrade_in_progress && !status.value.managed)
const active = ref(true)
let generation = 0
const state = computed(() => {
  if (status.value?.upgrade_in_progress) return 'running'
  if (!status.value || status.value.check_failed || status.value.latest === null) return 'unknown'
  if (status.value.has_update) return 'available'
  return status.value.cache_stale ? 'unknown' : 'current'
})
async function load(signal?: AbortSignal) {
  const request = ++generation
  loading.value = true
  error.value = false
  try {
    const result = await instanceUpdatesApi.status(signal)
    if (request === generation && !signal?.aborted) status.value = result
  } catch {
    if (request === generation && !signal?.aborted) error.value = true
  } finally { if (request === generation) loading.value = false }
}
async function check() {
  if (busy.value || status.value?.managed) return
  busy.value = true
  error.value = false
  notice.value = ''
  generation++
  try { status.value = await instanceUpdatesApi.check() }
  catch { error.value = true }
  finally { busy.value = false }
}
async function trigger() {
  if (busy.value || !canTrigger.value || !status.value?.latest) return
  const version = status.value.latest
  const contacts = status.value.other_recent_sessions ?? 0
  if (!window.confirm(t(contacts > 0 ? 'instance_updates.confirm_trigger_active' : 'instance_updates.confirm_trigger', { version, n: contacts }))) return
  busy.value = true
  error.value = false
  notice.value = ''
  generation++
  try {
    const result = await instanceUpdatesApi.trigger(version, contacts > 0)
    notice.value = t(result.status === 'queued' ? 'instance_updates.queued' : result.status === 'manual_required' ? 'instance_updates.manual_required' : 'instance_updates.trigger_failed')
    await load()
  } catch (e) {
    const detail = (e as { response?: { data?: { error?: { code?: string; other_recent_sessions?: number } } } }).response?.data?.error
    if (detail?.code === 'active_sessions' && status.value) {
      status.value.other_recent_sessions = detail.other_recent_sessions ?? 1
      notice.value = t('instance_updates.active_changed')
    } else notice.value = t('instance_updates.trigger_failed')
  }
  finally { busy.value = false }
}
onActivated(() => { active.value = true })
onDeactivated(() => { active.value = false; generation++ })
onUnmounted(() => { generation++ })
useSessionAwarePolling(signal => load(signal), 30_000, () => active.value && !busy.value)
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('instance_updates.title') }}</h1><p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('instance_updates.description') }}</p></div>
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="loading || busy" @click="load()"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.refresh') }}</button>
    </header>
    <p v-if="status && status.other_recent_sessions > 0" role="alert" class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700">{{ t('instance_updates.active_warning', { n: status.other_recent_sessions }) }}</p>
    <div v-if="status && !status.managed" class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-check-updates :disabled="busy || loading" @click="check"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('instance_updates.check') }}</button>
      <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" data-trigger-update :disabled="!canTrigger || busy || loading" @click="trigger"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.play" /></svg>{{ t('instance_updates.trigger') }}</button>
    </div>
    <p v-if="notice" role="status" class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm">{{ notice }}</p>
    <p v-if="error" role="alert" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-danger-700">{{ t('instance_updates.load_failed') }}</p>
    <p v-if="loading && !status" role="status" class="text-neutral-500">{{ t('common.loading') }}</p>
    <section v-if="status" class="rounded-lg border border-neutral-200 bg-surface p-4">
      <p class="mb-4 font-medium" :data-update-state="state">{{ t(`instance_updates.state_${state}`) }}</p>
      <dl class="grid gap-4 sm:grid-cols-3">
        <div><dt class="text-sm text-neutral-500">{{ t('instance_updates.current') }}</dt><dd class="font-medium">{{ status.current }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('instance_updates.latest') }}</dt><dd class="font-medium">{{ status.latest ?? t('instance_updates.unknown_version') }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('instance_updates.last_check') }}</dt><dd>{{ status.last_check_at ? new Date(status.last_check_at).toLocaleString(locale) : t('instance_updates.never_checked') }}</dd></div>
      </dl>
      <p v-if="status.upgrade_progress" class="mt-4 text-sm">{{ t('instance_updates.progress', { n: status.upgrade_progress.step_index, total: status.upgrade_progress.step_count }) }}</p>
      <p v-if="status.last_upgrade_result" class="mt-4 text-sm">{{ t(status.last_upgrade_result.status === 'applied' ? 'instance_updates.last_applied' : status.last_upgrade_result.status === 'failed' ? 'instance_updates.last_failed' : 'instance_updates.last_unknown') }} <span v-if="status.last_upgrade_result.target_version">{{ status.last_upgrade_result.target_version }}</span></p>
      <p v-if="status.cache_stale" class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">{{ t('instance_updates.stale') }}</p>
      <p v-if="status.managed" class="mt-4 text-sm text-neutral-500">{{ t('instance_updates.managed') }}</p>
    </section>
  </div>
</template>
