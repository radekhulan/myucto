<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import EmailProfiles from '@/pages/admin/EmailProfiles.vue'
import Branding from '@/pages/admin/Branding.vue'
import ClientPaymentQrSettings from '@/components/settings/ClientPaymentQrSettings.vue'

type SettingsTab = 'email-profiles' | 'branding' | 'payment-qr'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const tabs: SettingsTab[] = ['email-profiles', 'branding', 'payment-qr']

function tabFromQuery(value: unknown): SettingsTab {
  const candidate = String(value ?? '') as SettingsTab
  return tabs.includes(candidate) ? candidate : 'email-profiles'
}

const activeTab = ref<SettingsTab>(tabFromQuery(route.query.tab))

watch(() => route.query.tab, value => {
  activeTab.value = tabFromQuery(value)
})

function switchTab(tab: SettingsTab) {
  if (activeTab.value === tab) return
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
        @click="switchTab('email-profiles')"
      >
        {{ t('nav.email_profiles') }}
      </button>
      <button
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'branding' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="switchTab('branding')"
      >
        {{ t('nav.branding') }}
      </button>
      <button
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === 'payment-qr' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="switchTab('payment-qr')"
      >
        {{ t('settings.payment_qr_tab') }}
      </button>
    </nav>

    <EmailProfiles v-if="activeTab === 'email-profiles'" client-scoped />
    <Branding v-else-if="activeTab === 'branding'" client-scoped />
    <ClientPaymentQrSettings v-else />
  </div>
</template>
