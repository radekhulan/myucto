<script setup lang="ts">
/**
 * Nastavení → Firma → Přihlášení: firma může vyžadovat přihlášení přes účet
 * organizace. Zapnout to jde jen ze sezení přihlášeného přes daného
 * poskytovatele (jinak by si správce firmu zavřel), před zapnutím se ukáže,
 * kolik členů ještě nemá propojenou identitu.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { oidcApi, type LoginPolicy, type LoginPolicyState } from '@/api/oidc'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const suppliers = useSupplierStore()
const toast = useToast()

const state = ref<LoginPolicyState | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const policy = ref<LoginPolicy>('any')
const provider = ref('')

const canManage = computed(() => state.value?.can_manage === true)
const changed = computed(() => state.value !== null && (policy.value !== state.value.login_policy
  || (policy.value === 'sso_required' && provider.value !== (state.value.login_sso_provider ?? ''))))
const enabling = computed(() => policy.value === 'sso_required' && state.value?.login_policy !== 'sso_required')

function apply(next: LoginPolicyState) {
  state.value = next
  policy.value = next.login_policy
  provider.value = next.login_sso_provider ?? next.providers[0]?.id ?? ''
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    apply(await oidcApi.loginPolicy())
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('sso.policy_load_failed')
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!canManage.value || !changed.value || saving.value) return
  saving.value = true
  error.value = ''
  try {
    apply(await oidcApi.updateLoginPolicy(policy.value, policy.value === 'sso_required' ? provider.value : null))
    toast.success(t('sso.policy_saved'))
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'sso_session_required'
      ? t('sso.policy_session_required')
      : e?.response?.data?.error?.message || t('common.error')
  } finally {
    saving.value = false
  }
}

onMounted(load)
watch(() => suppliers.currentSupplierId, load)
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-testid="login-policy-settings">
    <div class="mb-4">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('sso.policy_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('sso.policy_intro') }}</p>
    </div>

    <p v-if="loading" class="py-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <!-- Vynucení pro celou instalaci přebíjí nastavení firmy, firma ho jen ukazuje. -->
    <div v-else-if="state?.global_sso_provider" class="space-y-2" data-testid="login-policy-global">
      <p class="rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-800">
        {{ t('sso.policy_global', { provider: state.providers.find(p => p.id === state!.global_sso_provider)?.label ?? state.global_sso_provider }) }}
      </p>
      <p v-if="state.users_without_identity > 0" class="rounded-md border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-700">
        {{ t('sso.policy_users_without_identity', { count: state.users_without_identity }) }}
      </p>
    </div>
    <form v-else-if="state" class="space-y-4" @submit.prevent="save">
      <fieldset class="space-y-2" :disabled="!canManage || saving">
        <label class="flex items-start gap-2 text-sm">
          <input v-model="policy" type="radio" value="any" class="mt-0.5" />
          <span>
            <span class="font-medium">{{ t('sso.policy_any') }}</span>
            <span class="block text-xs text-neutral-500">{{ t('sso.policy_any_hint') }}</span>
          </span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="policy" type="radio" value="sso_required" class="mt-0.5" />
          <span>
            <span class="font-medium">{{ t('sso.policy_required') }}</span>
            <span class="block text-xs text-neutral-500">{{ t('sso.policy_required_hint') }}</span>
          </span>
        </label>
        <label v-if="policy === 'sso_required' && state.providers.length > 1" class="block text-sm pl-6">
          <span class="mb-1 block font-medium">{{ t('sso.policy_provider') }}</span>
          <select v-model="provider" class="h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option v-for="p in state.providers" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
        </label>
      </fieldset>

      <div v-if="enabling && state.users_without_identity > 0"
           class="rounded-md border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="login-policy-warning">
        {{ t('sso.policy_users_without_identity', { count: state.users_without_identity }) }}
      </div>
      <p v-if="!canManage" class="text-xs text-neutral-500">{{ t('sso.policy_read_only') }}</p>
      <p v-if="error" class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</p>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button type="submit" :class="[btnFilled(enabling ? 'warning' : 'primary'), 'whitespace-nowrap']" :disabled="saving || !changed">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
          {{ saving ? '…' : t('sso.policy_save') }}
        </button>
      </div>
    </form>
    <p v-else-if="error" class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</p>
  </section>
</template>
