<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import EmailProfiles from '@/pages/admin/EmailProfiles.vue'
import Branding from '@/pages/admin/Branding.vue'
import ClientSigningProfiles from '@/components/settings/ClientSigningProfiles.vue'
import CompanyEmailTemplates from '@/components/settings/CompanyEmailTemplates.vue'
import ClientPaymentQrSettings from '@/components/settings/ClientPaymentQrSettings.vue'
import ClientInvoiceDefaults from '@/components/settings/ClientInvoiceDefaults.vue'

type SettingsTab = 'email-profiles' | 'branding' | 'payment-qr' | 'signing-profiles' | 'email-templates' | 'invoice-defaults'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const tabs = computed<SettingsTab[]>(() => {
  const allowed: SettingsTab[] = []
  if (auth.canWrite('settings.email_profiles')) allowed.push('email-profiles')
  if (auth.canWrite('settings.branding')) allowed.push('branding')
  if (auth.canWrite('settings.invoice_defaults')) allowed.push('invoice-defaults')
  if (auth.canWrite('settings.payment_qr')) allowed.push('payment-qr')
  if (auth.isClientRole && auth.canWrite('settings.signing_profiles')) allowed.push('signing-profiles')
  if (auth.canWrite('settings.email_templates')) allowed.push('email-templates')
  return allowed
})

function tabFromQuery(value: unknown): SettingsTab | undefined {
  const candidate = String(value ?? '') as SettingsTab
  return tabs.value.includes(candidate) ? candidate : tabs.value[0]
}

const activeTab = ref<SettingsTab | undefined>(tabFromQuery(route.query.tab))

watch(() => [route.query.tab, tabs.value] as const, ([value]) => {
  activeTab.value = tabFromQuery(value)
})

function switchTab(tab: SettingsTab) {
  if (!tabs.value.includes(tab) || activeTab.value === tab) return
  activeTab.value = tab
  void router.replace({ query: { ...route.query, tab } })
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold">{{ t('settings.client_company_settings_title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">
        {{ t('settings.client_company_settings_hint') }}
      </p>
    </header>

    <nav class="flex flex-wrap border-b border-neutral-200" :aria-label="t('settings.client_company_settings_tabs')">
      <button
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'email-profiles' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('email-profiles')" @click="switchTab('email-profiles')"
      >
        {{ t('nav.email_profiles') }}
      </button>
      <button
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'branding' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('branding')" @click="switchTab('branding')"
      >
        {{ t('nav.branding') }}
      </button>
      <button
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'payment-qr' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('payment-qr')" @click="switchTab('payment-qr')"
      >
        {{ t('settings.payment_qr_tab') }}
      </button>
      <button type="button" class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'invoice-defaults' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('invoice-defaults')" @click="switchTab('invoice-defaults')">{{ t('settings.invoice_defaults_title') }}</button>
      <button type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'signing-profiles' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('signing-profiles')" @click="switchTab('signing-profiles')">
        {{ t('settings.signing_profiles_title') }}
      </button>
      <button type="button" class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'email-templates' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        v-if="tabs.includes('email-templates')" @click="switchTab('email-templates')">{{ t('users.email_templates_title') }}</button>
    </nav>

    <EmailProfiles v-if="activeTab === 'email-profiles'" client-scoped />
    <Branding v-else-if="activeTab === 'branding'" client-scoped />
    <ClientSigningProfiles v-else-if="activeTab === 'signing-profiles'" />
    <CompanyEmailTemplates v-else-if="activeTab === 'email-templates'" client-scoped />
    <ClientPaymentQrSettings v-else-if="activeTab === 'payment-qr'" />
    <ClientInvoiceDefaults v-else-if="activeTab === 'invoice-defaults'" />
  </div>
</template>
