<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import MembershipMemberDetail from '@/components/accounting/membership/MembershipMemberDetail.vue'
import { MEMBERSHIP_LIST_PATH, membershipListNotice, useMembershipAccess } from '@/components/accounting/membership/membershipPage'

/** Detail člena jako samostatná stránka, stejně jako detail kontaktu: celá šířka, vlastní adresa, otevření v novém panelu. */
const { t } = useI18n()
const route = useRoute(), router = useRouter()
const { supplierId, canManage } = useMembershipAccess()
const memberId = computed(() => Number(route.params.id))
const periods = ref<AccountingPeriod[]>([])
let alive = true, ticket = 0

/** Návrat na seznam i s filtry, ze kterých uživatel přišel; přímý odkaz vede na seznam bez filtrů. */
const backTo = computed(() => {
  const back = typeof window !== 'undefined' ? (window.history.state as { back?: unknown } | null)?.back : null
  return typeof back === 'string' && (back === MEMBERSHIP_LIST_PATH || back.startsWith(`${MEMBERSHIP_LIST_PATH}?`)) ? back : MEMBERSHIP_LIST_PATH
})

async function loadPeriods() {
  const run = ++ticket, supplier = supplierId.value
  try {
    const rows = await accountingApi.listPeriods()
    if (alive && run === ticket && supplier === supplierId.value) periods.value = rows
  } catch {
    if (alive && run === ticket) periods.value = []
  }
}

function close() { void router.push(backTo.value) }
/** Smazaný člen: zpět na seznam bez možnosti vrátit se na jeho adresu, seznam ohlásí smazání. */
function deleted() {
  membershipListNotice.value = t('nonprofitMembership.correction.done_delete')
  void router.replace(backTo.value)
}

// Člen patří jedné firmě; po přepnutí firmy jeho adresa nic neznamená.
watch(supplierId, (now, before) => { if (before && now !== before) void router.replace(MEMBERSHIP_LIST_PATH) })
onMounted(() => { void loadPeriods() })
onBeforeUnmount(() => { alive = false; ticket++ })
</script>

<template>
  <div v-if="supplierId && memberId" class="space-y-3" data-section="member-page">
    <RouterLink :to="backTo" class="text-sm text-neutral-600 hover:text-neutral-900" data-action="back-to-list">{{ t('nonprofitMembership.backToList') }}</RouterLink>
    <MembershipMemberDetail :key="`${supplierId}-${memberId}`" :supplier="supplierId" :can-manage="canManage" :member-id="memberId" :periods="periods"
      @close="close()" @deleted="deleted()" />
  </div>
</template>
