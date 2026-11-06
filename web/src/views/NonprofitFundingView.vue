<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import FundingWorkspace from '@/components/accounting/funding/FundingWorkspace.vue'
const supplier = useSupplierStore(), auth = useAuthStore()
const id = computed(() => Number(supplier.currentSupplierId))
const canManage = computed(() => auth.can('accounting.periods.close', 'write'))
</script>
<template><FundingWorkspace v-if="id" :key="id" :supplier="id" :can-manage="canManage" /></template>
