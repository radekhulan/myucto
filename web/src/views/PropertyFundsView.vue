<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import FundingWorkspace from '@/components/property/funds/FundingWorkspace.vue'
const supplier = useSupplierStore()
const current = computed(() => Number(supplier.currentSupplierId))
</script>
<template><FundingWorkspace v-if="current" :key="current" :supplier="current" /></template>
