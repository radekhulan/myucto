<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import CapitalWorkspace from '@/components/accounting/capital/CapitalWorkspace.vue'
const supplier = useSupplierStore(), auth = useAuthStore()
const id = computed(() => Number(supplier.currentSupplierId))
const canManage = computed(() => auth.can('accounting.periods.close', 'write'))
</script>
<template><CapitalWorkspace v-if="id" :key="id" :supplier="id" :can-manage="canManage" /></template>
