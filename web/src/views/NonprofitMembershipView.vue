<script setup lang="ts">
import MembershipWorkspace from '@/components/accounting/membership/MembershipWorkspace.vue'
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useMembershipAccess } from '@/components/accounting/membership/membershipPage'
const { supplierId: id, canManage, canManageRates } = useMembershipAccess()
const auth = useAuthStore()
const canReadErasure = computed(() => Boolean(auth.user && auth.can('accounting.membership_erasure', 'read')))
const canWriteErasure = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.membership_erasure', 'write')))
</script>
<template><MembershipWorkspace v-if="id" :key="id" :supplier="id" :can-manage="canManage" :can-manage-rates="canManageRates" :can-read-erasure="canReadErasure" :can-write-erasure="canWriteErasure" /></template>
