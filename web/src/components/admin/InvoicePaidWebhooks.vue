<script setup lang="ts">
import { ref, onMounted, onActivated, onDeactivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ templateId?: number }>()
const { t } = useI18n()
const base = '/admin/invoice-webhooks'
type Hook = { id: number; url: string; include_advance: number }
type Delivery = { id: number; event_id: string; attempts: number; status: string; http_status: number | null }
const hooks = ref<Hook[]>([])
const url = ref('')
const advance = ref(false)
const secret = ref('')
const error = ref('')
const busy = ref(false)
const selected = ref<number | null>(null)
const deliveries = ref<Delivery[]>([])
let active = true
async function load() {
  const { data } = await api.get<Hook[]>(base, { params: { template_id: props.templateId } })
  if (active) hooks.value = data
}
async function run(action: () => Promise<void>) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try { await action() }
  catch (e) { if (active) error.value = apiErrorMessage(e) }
  finally { busy.value = false }
}
async function register() {
  const { data } = await api.post<{ secret: string }>(base, { template_id: props.templateId, url: url.value, include_advance: advance.value })
  if (!active) return
  secret.value = data.secret
  url.value = ''
  await load()
}
async function history(id: number, more = false) {
  const before = more ? deliveries.value.at(-1)?.id : undefined
  const { data } = await api.get<Delivery[]>(base + '/' + id + '/deliveries', { params: { before } })
  if (!active) return
  selected.value = id
  deliveries.value = more ? deliveries.value.concat(data) : data
  hasMore.value = data.length === 25
}
const hasMore = ref(false)
async function rotate(id: number) {
  if (!window.confirm(t('integrations.webhooks.rotateConfirm'))) return
  const { data } = await api.post<{ secret: string }>(base + '/' + id + '/rotate')
  if (active) secret.value = data.secret
}
async function remove(id: number) {
  if (!window.confirm(t('integrations.webhooks.deleteConfirm'))) return
  await api.delete(base + '/' + id)
  secret.value = ''
  selected.value = null
  await load()
}
async function retry(id: number, delivery: number) {
  await api.post(base + '/' + id + '/deliveries/' + delivery + '/retry')
  await history(id)
}
function hide() { active = false; secret.value = ''; selected.value = null }
onDeactivated(hide)
onBeforeUnmount(hide)
onMounted(() => run(load))
onActivated(() => { active = true; void run(load) })
</script>
<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-5">
    <h2 class="font-medium">{{ t('integrations.webhooks.title') }}</h2>
    <p class="text-sm text-neutral-600">{{ t(templateId ? 'integrations.webhooks.template' : 'integrations.webhooks.company') }}</p>
    <form class="space-y-2" @submit.prevent="run(register)">
      <label class="block">{{ t('integrations.webhooks.url') }}
        <input v-model="url" type="url" required maxlength="2048" class="block w-full rounded border p-2">
      </label>
      <label class="block"><input v-model="advance" type="checkbox"> {{ t('integrations.webhooks.advance') }}</label>
      <button :class="btnFilled('primary')" :disabled="busy || !url">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.plus"/></svg>
        {{ t('integrations.webhooks.register') }}
      </button>
    </form>
    <div v-if="secret" role="status" class="rounded border p-3">
      {{ t('integrations.webhooks.secret') }} <code class="break-all select-all">{{ secret }}</code>
    </div>
    <p v-if="error" role="alert" class="text-danger-700">{{ error }}</p>
    <div v-for="hook in hooks" :key="hook.id" class="space-y-2 border-t pt-2">
      <p class="break-all">{{ hook.url }}</p>
      <p v-if="hook.include_advance" class="text-sm">{{ t('integrations.webhooks.advance') }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" :disabled="busy" :class="btnOutline()" @click="run(() => history(hook.id))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.eye"/></svg>{{ t('integrations.webhooks.history') }}
        </button>
        <button type="button" :disabled="busy" :class="btnOutline('warning')" @click="run(() => rotate(hook.id))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.cycle"/></svg>{{ t('integrations.webhooks.rotate') }}
        </button>
        <button type="button" :disabled="busy" :class="btnOutline('danger')" @click="run(() => remove(hook.id))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.trash"/></svg>{{ t('integrations.webhooks.remove') }}
        </button>
      </div>
      <template v-if="selected === hook.id">
        <p v-if="!deliveries.length">{{ t('integrations.webhooks.empty') }}</p>
        <div v-for="d in deliveries" :key="d.id" class="flex flex-wrap items-center gap-2 text-sm">
          <code>{{ d.event_id }}</code> {{ t('integrations.webhooks.states.' + d.status) }}
          {{ t('integrations.webhooks.attempts', { n: d.attempts }) }} <span v-if="d.http_status">HTTP {{ d.http_status }}</span>
          <button v-if="d.status === 'failed'" type="button" :disabled="busy" :class="btnOutline('warning')" @click="run(() => retry(hook.id, d.id))">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.cycle"/></svg>{{ t('integrations.webhooks.retry') }}
          </button>
        </div>
        <button v-if="hasMore" type="button" :disabled="busy" :class="btnOutline()" @click="run(() => history(hook.id, true))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path :d="ICONS.chevron"/></svg>{{ t('integrations.webhooks.more') }}
        </button>
      </template>
    </div>
  </section>
</template>
