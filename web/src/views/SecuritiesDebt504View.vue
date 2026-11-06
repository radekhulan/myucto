<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import SecuritiesDebt504Workspace from '@/components/accounting/SecuritiesDebt504Workspace.vue'
const store = useSupplierStore()
const supplier = computed(() => Number(store.currentSupplierId))
</script>
<template><SecuritiesDebt504Workspace v-if="supplier" :key="supplier" :supplier="supplier" /></template>
