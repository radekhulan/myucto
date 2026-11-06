<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import McpConnectionGuide from '@/components/mcp/McpConnectionGuide.vue'
import McpGrantList from '@/components/mcp/McpGrantList.vue'
import McpServerAddress from '@/components/mcp/McpServerAddress.vue'

interface Grant {
  id: number
  client_name: string
  resource: string
  scope: string
  is_active: boolean
  last_used_at: string | null
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const endpoint = ref('')
const enabled = ref(false)
const grants = ref<Grant[]>([])
const loading = ref(false)
const error = ref('')
const revoking = ref<number | null>(null)
const settingsLoaded = ref(false)
const mainDomain = computed(() => auth.domainContext?.locked !== true)
const canConnect = computed(() => !mainDomain.value
  && (auth.canRead('profile.mcp') || (!auth.isClientRole && auth.canRead('profile.tokens'))))
let mounted = false

async function load() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const [settings, connections] = await Promise.all([
      api.get<{ enabled: boolean; node_available: boolean; endpoint: string }>('/mcp/settings'),
      api.get<{ grants: Grant[] }>('/mcp/grants'),
    ])
    endpoint.value = settings.data.endpoint
    enabled.value = settings.data.enabled && settings.data.node_available
    grants.value = connections.data.grants
    settingsLoaded.value = true
  } catch {
    error.value = t('client_mcp.load_error')
  } finally {
    loading.value = false
  }
}

async function copyEndpoint(address: string) {
  try {
    await navigator.clipboard.writeText(address)
    toast.success(t('client_mcp.copied'))
  } catch {
    toast.error(t('client_mcp.copy_error'))
  }
}

async function revoke(grant: Grant) {
  if (revoking.value !== null || !window.confirm(t('client_mcp.revoke_confirm', { name: grant.client_name }))) return
  revoking.value = grant.id
  try {
    await api.delete(`/mcp/grants/${grant.id}`)
    toast.success(t('client_mcp.revoked'))
    await load()
  } catch {
    toast.error(t('client_mcp.revoke_error'))
  } finally {
    revoking.value = null
  }
}

onMounted(async () => { await load(); mounted = true })
onActivated(() => { if (mounted) void load() })
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('client_mcp.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t(mainDomain ? 'client_mcp.description_main_domain' : 'client_mcp.description') }}</p>
    </div>

    <div v-if="settingsLoaded" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-1">{{ t('client_mcp.connect') }}</h2>
      <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium"
        :class="enabled ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">
        {{ t(enabled ? 'mcp_server_page.connection.status_on' : 'mcp_server_page.connection.status_off') }}
      </span>
      <p v-if="!enabled" class="mt-3 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-neutral-700">{{ t('client_mcp.disabled') }}</p>
      <p v-else-if="!canConnect" class="mt-3 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-neutral-700">{{ t('client_mcp.permission_required') }}</p>
      <p v-else class="mt-3 text-sm text-neutral-700">{{ t('client_mcp.instructions') }}</p>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <h2 class="text-lg font-semibold">{{ t('client_mcp.connections') }}</h2>
        <button type="button" :disabled="loading" :class="btnOutline('neutral')" @click="load">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
          </svg>
          {{ t('client_mcp.refresh') }}
        </button>
      </div>
      <p v-if="loading" role="status" class="text-sm text-neutral-500">{{ t('client_mcp.loading') }}</p>
      <template v-else>
        <EmptyState v-if="error" dense variant="failed" role="alert" :title="error" @action="load" />
        <EmptyState v-else-if="grants.length === 0" dense accent="neutral" icon="link"
          :title="t(mainDomain ? 'client_mcp.empty_main_domain' : 'client_mcp.empty')" />
        <McpGrantList v-if="grants.length > 0" :grants="grants" :revoking-id="revoking"
          :revoke-label="t('client_mcp.revoke')" @revoke="revoke">
          <template #details="{ grant }">
            <div class="break-all text-xs text-neutral-500">{{ grant.resource }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
              <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium"
                :class="grant.scope === 'read_write' ? 'bg-primary-100 text-primary-700' : 'bg-neutral-100 text-neutral-600'">
                {{ t(grant.scope === 'read_write' ? 'client_mcp.read_write' : 'client_mcp.read') }}
              </span>
              <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium"
                :class="grant.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                {{ t(grant.is_active ? 'client_mcp.active' : 'client_mcp.inactive') }}
              </span>
            </div>
            <div class="mt-1 text-xs text-neutral-500">{{ t('client_mcp.last_used', { date: grant.last_used_at ? formatDateTime(grant.last_used_at) : t('client_mcp.never') }) }}</div>
          </template>
        </McpGrantList>
      </template>
    </div>

    <McpServerAddress v-if="endpoint" class="mb-4" :endpoint="endpoint"
      :hint="mainDomain ? t('client_mcp.canonical_endpoint_hint') : undefined"
      @copy="copyEndpoint" />
    <McpConnectionGuide class="mb-4" client-domain :main-domain="mainDomain" />
  </div>
</template>
