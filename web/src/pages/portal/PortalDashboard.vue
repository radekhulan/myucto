<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import DashboardBoard from '@/components/dashboard/board/DashboardBoard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
const { t } = useI18n()
const supplierStore = useSupplierStore()
</script>
<template>
  <div class="space-y-5">
    <EmptyState v-if="!supplierStore.hasSupplier" boxed accent="neutral" icon="lock" :title="t('portal.no_company')" />
    <template v-else>

      <DashboardBoard board="portal">
        <template #heading><h1 class="text-2xl font-semibold">{{ supplierStore.currentSupplier?.company_name || t('portal.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('portal.title') }}</p><p class="text-xs text-neutral-400 mt-1">{{ t('portal.disclaimer') }}</p></template>
      </DashboardBoard>
    </template>
  </div>
</template>
