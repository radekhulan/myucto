<script setup lang="ts">
/** Správa uživatelů → propojené identity účtu a pozvánka k přihlášení přes každého zapnutého poskytovatele. */
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SsoProvider } from '@/api/auth'
import { oidcApi, type ExternalIdentity } from '@/api/oidc'
import { useToast } from '@/composables/useToast'
import { ICONS, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import SsoProviderLogo from '@/components/security/SsoProviderLogo.vue'

const props = defineProps<{ userId: number; email: string }>()

const { t } = useI18n()
const toast = useToast()
const identities = ref<ExternalIdentity[]>([])
const providers = ref<SsoProvider[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')

function stamp(value: string | null): string {
  return value?.replace('T', ' ').slice(0, 16) || '—'
}

function providerLabel(id: string): string {
  return providers.value.find(p => p.id === id)?.label ?? id
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await oidcApi.adminIdentities(props.userId)
    identities.value = result.identities
    providers.value = result.providers
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('sso.identities_load_failed')
  } finally {
    loading.value = false
  }
}

async function invite(provider: SsoProvider) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await oidcApi.adminInvite(props.userId, provider.id)
    toast.success(t('sso.admin_invite_sent', { email: props.email }))
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'mail_failed'
      ? t('sso.admin_invite_mail_failed')
      : code === 'user_inactive'
        ? t('sso.admin_invite_inactive')
        : code === 'sso_disabled'
          ? t('sso.errors.sso_disabled')
          : e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
  }
}

async function unlink(identity: ExternalIdentity) {
  if (busy.value) return
  if (!confirm(t('sso.admin_unlink_confirm', { provider: providerLabel(identity.provider), email: props.email }))) return
  busy.value = true
  error.value = ''
  try {
    await oidcApi.adminUnlink(props.userId, identity.id)
    toast.success(t('sso.unlinked_toast'))
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
  }
}

onMounted(load)
watch(() => props.userId, load)
</script>

<template>
  <section class="border-t border-neutral-200 pt-4 space-y-3" data-testid="user-sso-identities">
    <div>
      <h3 class="font-medium">{{ t('sso.admin_title') }}</h3>
      <p class="text-xs text-neutral-500">{{ t('sso.admin_hint') }}</p>
    </div>
    <p v-if="loading" class="text-xs text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else>
      <p v-if="identities.length === 0" class="text-sm text-neutral-500">{{ t('sso.identities_empty') }}</p>
      <div v-for="identity in identities" :key="identity.id"
           class="border border-neutral-200 rounded-md p-3 flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-40">
          <div class="font-medium inline-flex items-center gap-2">
            <SsoProviderLogo :provider="identity.provider" />
            {{ providerLabel(identity.provider) }}
          </div>
          <div class="text-xs text-neutral-500 break-all">{{ identity.email || identity.display_name || '—' }}</div>
          <div class="text-xs text-neutral-500">
            {{ t('sso.linked_at') }} {{ stamp(identity.created_at) }}
            · {{ t('sso.last_used') }} {{ stamp(identity.last_used_at) }}
          </div>
        </div>
        <button type="button" :class="btnOutlineSm('danger')" :disabled="busy" @click="unlink(identity)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('sso.unlink_button') }}
        </button>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-for="provider in providers" :key="provider.id" type="button"
                :class="btnOutline('primary')" :disabled="busy" :data-testid="`user-sso-invite-${provider.id}`"
                @click="invite(provider)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
          {{ t('sso.admin_invite', { provider: provider.label }) }}
        </button>
      </div>
    </template>
    <p v-if="error" class="text-sm text-danger-500">{{ error }}</p>
  </section>
</template>
