<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import type { CurrencyAccount } from '@/api/settings'
import { bankConnectionsApi, type BankConnection, type BankConnectionProvider } from '@/api/bankConnections'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import BankConnectionAccount from './BankConnectionAccount.vue'

const props = defineProps<{ accounts: CurrencyAccount[]; canManage: boolean }>()
const { t } = useI18n()
const auth = useAuthStore()
const providers = ref<BankConnectionProvider[]>([])
const connections = ref<BankConnection[]>([])
const loading = ref(false)
const error = ref('')
const canRead = computed(() => auth.canRead('settings.bank_accounts'))
const canWrite = computed(() => props.canManage && auth.canWrite('settings.bank_accounts'))
const supportedAccounts = computed(() => props.accounts.filter(account => providers.value.some(provider =>
  provider.implemented && provider.capabilities.statement_import && provider.bank_codes.includes(account.bank_code || ''),
)))
async function load() {
  if (!canRead.value || loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await bankConnectionsApi.list()
    providers.value = result.providers
    connections.value = result.connections
  } catch (e) {
    error.value = apiErrorMessage(e, t('bank_connection.load_failed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <section v-if="canRead" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <header class="px-5 py-3 border-b border-neutral-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_connection.title') }}</h2>
      <p class="text-sm text-neutral-600 mt-1">{{ t('bank_connection.intro') }}</p>
    </header>
    <div v-if="error" class="p-4 space-y-2" role="alert">
      <p class="text-sm text-danger-600">{{ error }}</p>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
        {{ t('bank_connection.reload') }}
      </button>
    </div>
    <p v-else-if="loading && !providers.length" class="p-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else>
      <div class="px-5 py-3 flex flex-wrap gap-2">
        <span v-for="provider in providers" :key="provider.code" class="text-xs rounded px-2 py-1 border border-neutral-200 text-neutral-600 bg-neutral-50">
          {{ provider.label }}
        </span>
      </div>
      <p v-if="!supportedAccounts.length" class="px-5 pb-4 text-sm text-neutral-500">{{ t('bank_connection.no_supported_accounts') }}</p>
      <BankConnectionAccount v-for="account in supportedAccounts" :key="account.id" :account="account"
        :connection="connections.find(c => c.currency_id === account.id) ?? null" :providers="providers" :can-write="canWrite" @changed="load" />
    </template>
  </section>
</template>
