<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import ServiceLifecycleWorkspace from '@/components/property/lifecycle/ServiceLifecycleWorkspace.vue'
const store = useSupplierStore()
const supplier = computed(() => Number(store.currentSupplierId))
</script>
<template><ServiceLifecycleWorkspace v-if="supplier" :key="supplier" :supplier="supplier" /></template>
