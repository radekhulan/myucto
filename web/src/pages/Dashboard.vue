<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
import OnboardingGuide from '@/components/dashboard/OnboardingGuide.vue'
const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const canCreateSupplier = computed(() => auth.isSuperadmin || auth.isAdminPlusRole)
const hasSupplier = computed(() => supplierStore.hasSupplier)
const empty = ref(false)
const supplierIdentity = computed<string>(() => {
  const s = supplierStore.currentSupplier
  if (!s) return ''
  const address = [s.street, [s.zip, s.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
  return [
    s.ic ? `${t('common.ic')} ${s.ic}` : '',
    s.dic ? `${t('common.dic')} ${s.dic}` : '',
    address,
  ].filter(Boolean).join(' · ')
})

</script>
<template>
  <div class="space-y-5">
    <div v-if="!hasSupplier" class="max-w-2xl mx-auto bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center">
      <div class="w-14 h-14 bg-primary-100 rounded-full mx-auto mb-4 flex items-center justify-center">
        <svg class="w-7 h-7 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
        </svg>
      </div>
      <h2 class="text-xl font-semibold mb-2">{{ t('dashboard.no_supplier.title') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('dashboard.no_supplier.intro') }}</p>
      <RouterLink v-if="canCreateSupplier" to="/admin/suppliers?create=supplier"
        class="px-5 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('dashboard.no_supplier.cta_admin') }}
      </RouterLink>
      <p v-else class="text-sm text-neutral-600">{{ t('dashboard.no_supplier.non_admin') }}</p>
    </div>


    <template v-else>

      <DashboardBoard board="home" @empty="empty = $event">
        <template #heading><h1 class="text-2xl font-semibold">{{ supplierStore.currentSupplier?.company_name ?? t('nav.dashboard') }}</h1><p v-if="supplierIdentity" class="text-sm text-neutral-500 mt-0.5">{{ supplierIdentity }}</p></template>
        <template #before><OnboardingGuide v-if="empty && auth.canWrite('dashboard')" /></template>
      </DashboardBoard>
    </template>
  </div>
</template>
