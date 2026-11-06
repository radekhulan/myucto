<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import NonprofitDppoWorkspace from '@/components/accounting/nonprofitDppo/NonprofitDppoWorkspace.vue'
const supplier = useSupplierStore(), auth = useAuthStore()
const id = computed(() => Number(supplier.currentSupplierId))
const canManage = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.periods.close', 'write')))
const canEditReturn = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('reports', 'write')))
</script>
<template><NonprofitDppoWorkspace v-if="id" :key="id" :supplier="id" :can-manage="canManage" :can-edit-return="canEditReturn" /></template>
