<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import MembershipWorkspace from '@/components/accounting/membership/MembershipWorkspace.vue'
const supplier = useSupplierStore(), auth = useAuthStore()
const id = computed(() => Number(supplier.currentSupplierId))
const canManage = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.journal.post', 'write')))
</script>
<template><MembershipWorkspace v-if="id" :key="id" :supplier="id" :can-manage="canManage" /></template>
