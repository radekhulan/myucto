<script setup lang="ts">
import { computed, onActivated, onDeactivated, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { userSessionsApi, type UserSession } from '@/api/userSessions'
import { useToast } from '@/composables/useToast'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

defineProps<{ standalone?: boolean }>()
const { t, locale } = useI18n()
const toast = useToast()
const pagedRows = ref<UserSession[]>([])
const currentSession = ref<UserSession | null>(null)
const rows = computed(() => currentSession.value ? [currentSession.value, ...pagedRows.value] : pagedRows.value)
const canTerminate = ref(false)
const nextCursor = ref<string | null>(null)
const cursor = ref<string | null>(null)
const previous = ref<Array<string | null>>([])
const loading = ref(false)
const terminating = ref<string | null>(null)
const selected = ref<string[]>([])
const allSelected = computed(() => rows.value.length > 0 && rows.value.every(row => selected.value.includes(row.id)))
const error = ref(false)
const active = ref(true)
let generation = 0

async function load(signal?: AbortSignal) {
  const request = ++generation
  loading.value = true
  error.value = false
  try {
    const page = await userSessionsApi.list(cursor.value, signal)
    if (request !== generation || signal?.aborted) return
    pagedRows.value = page.data
    currentSession.value = page.current_session ?? null
    selected.value = selected.value.filter(id => rows.value.some(row => row.id === id))
    canTerminate.value = page.can_terminate
    nextCursor.value = page.next_cursor
  } catch {
    if (request === generation && !signal?.aborted) error.value = true
  } finally {
    if (request === generation) loading.value = false
  }
}

function nextPage() {
  if (!nextCursor.value || loading.value) return
  selected.value = []
  previous.value.push(cursor.value)
  cursor.value = nextCursor.value
  void load()
}

function previousPage() {
  if (!previous.value.length || loading.value) return
  selected.value = []
  cursor.value = previous.value.pop() ?? null
  void load()
}

async function terminate(row: UserSession) {
  if (terminating.value !== null || !canTerminate.value) return
  if (!window.confirm(t(row.is_current ? 'user_sessions.confirm_current' : 'user_sessions.confirm', { name: row.name }))) return
  terminating.value = row.id
  try {
    const result = await userSessionsApi.terminate(row.id)
    if (result.current_session_revoked) {
      navigateFullPage('/login')
      return
    }
    toast.success(t('user_sessions.terminated'))
    await load()
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) {
      toast.warning(t('user_sessions.already_ended'))
      await load()
    } else toast.error(t('user_sessions.terminate_failed'))
  } finally { terminating.value = null }
}

function toggleAll() {
  selected.value = allSelected.value ? [] : rows.value.map(row => row.id)
}

async function terminateSelected() {
  if (!canTerminate.value || !selected.value.length || terminating.value !== null) return
  const ids = [...selected.value]
  const includesCurrent = rows.value.some(row => row.is_current && ids.includes(row.id))
  if (!window.confirm(t(includesCurrent ? 'user_sessions.confirm_selected_current' : 'user_sessions.confirm_selected', { n: ids.length }))) return
  terminating.value = '__batch__'
  try {
    const result = await userSessionsApi.terminateSelected(ids)
    if (result.current_session_revoked) {
      navigateFullPage('/login')
      return
    }
    selected.value = []
    toast.success(t('user_sessions.terminated_many', { n: result.terminated }))
    await load()
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 409) {
      selected.value = []
      toast.warning(t('user_sessions.selection_stale'))
      await load()
    } else toast.error(t('user_sessions.terminate_failed'))
  } finally { terminating.value = null }
}

function time(value: string) {
  return new Date(value).toLocaleString(locale.value)
}

onActivated(() => { active.value = true })
onDeactivated(() => { active.value = false; generation++ })
onUnmounted(() => { generation++ })
useSessionAwarePolling(signal => load(signal), 30_000, () => active.value && terminating.value === null)
</script>

<template>
  <section>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <component :is="standalone ? 'h1' : 'h2'" class="text-xl font-semibold">{{ t('user_sessions.title') }}</component>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('user_sessions.description') }}</p>
        <p v-if="!canTerminate" class="mt-1 text-sm text-neutral-500">{{ t('user_sessions.read_only') }}</p>
      </div>
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="loading" @click="load()">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('user_sessions.refresh') }}
      </button>
    </header>
    <p v-if="error" role="alert" class="mb-4 rounded-lg border border-danger-200 bg-danger-50 p-3 text-danger-700">{{ t('user_sessions.load_failed') }}</p>
    <p v-if="loading && !rows.length" role="status" class="py-8 text-center text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="!rows.length && !error" class="rounded-lg border border-neutral-200 bg-surface p-6 text-center text-neutral-500">{{ t('user_sessions.empty') }}</p>
    <div v-else class="space-y-3" :aria-busy="loading">
      <div v-if="canTerminate && rows.length" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600" data-select-all :checked="allSelected" :disabled="loading || terminating !== null" @change="toggleAll" />{{ t('user_sessions.select_page') }}</label>
        <button type="button" :class="btnOutline('danger')" class="whitespace-nowrap" data-terminate-selected :disabled="!selected.length || loading || terminating !== null" @click="terminateSelected"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 6H5v12h4m5-14h5v16h-5M9 12h8m-3-3 3 3-3 3" /></svg>{{ t('user_sessions.terminate_selected', { n: selected.length }) }}</button>
      </div>
      <article v-for="row in rows" :key="row.id" class="rounded-lg border border-neutral-200 bg-surface p-4" :data-session="row.id">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2"><input v-if="canTerminate" v-model="selected" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600" :value="row.id" :aria-label="t('user_sessions.select_session', { name: row.name })" :disabled="loading || terminating !== null" :data-select-session="row.id" /><span class="font-medium break-words">{{ row.name }}</span><span v-if="row.is_current" class="rounded bg-primary-100 px-2 py-1 text-xs text-primary-700">{{ t('user_sessions.current') }}</span></div>
            <p v-if="row.email" class="mt-1 break-all text-xs text-neutral-500">{{ row.email }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded px-2 py-1 text-xs" :class="row.locked_at ? 'bg-warning-50 text-warning-700' : row.recent_contact ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'">{{ t(row.locked_at ? 'user_sessions.locked' : row.recent_contact ? 'user_sessions.recent' : 'user_sessions.idle') }}</span>
            <button v-if="canTerminate" type="button" :class="btnOutline('danger')" class="whitespace-nowrap" :disabled="terminating !== null" :data-terminate="row.id" @click="terminate(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 6H5v12h4m5-14h5v16h-5M9 12h8m-3-3 3 3-3 3" /></svg>{{ t('user_sessions.terminate') }}
            </button>
          </div>
        </div>
        <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
          <div><dt class="text-xs text-neutral-500">{{ t('user_sessions.created') }}</dt><dd>{{ time(row.created_at) }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('user_sessions.last_seen') }}</dt><dd>{{ time(row.last_seen) }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('user_sessions.expires') }}</dt><dd>{{ time(row.expires_at) }}</dd></div>
          <div v-if="row.ip"><dt class="text-xs text-neutral-500">{{ t('user_sessions.ip') }}</dt><dd class="break-all font-mono text-xs">{{ row.ip }}</dd></div>
          <div v-if="row.user_agent" class="sm:col-span-2 xl:col-span-2"><dt class="text-xs text-neutral-500">{{ t('user_sessions.device') }}</dt><dd class="break-words text-xs">{{ row.user_agent }}</dd></div>
        </dl>
      </article>
    </div>
    <nav v-if="previous.length || nextCursor" class="mt-4 flex flex-wrap items-center justify-between gap-3" :aria-label="t('user_sessions.pagination')">
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="!previous.length || loading" @click="previousPage"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m14 18-6-6 6-6" /></svg>{{ t('user_sessions.previous') }}</button>
      <span class="text-sm text-neutral-500">{{ t('user_sessions.page', { n: previous.length + 1 }) }}</span>
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="!nextCursor || loading" @click="nextPage">{{ t('user_sessions.next') }}<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m10 6 6 6-6 6" /></svg></button>
    </nav>
  </section>
</template>
