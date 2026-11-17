<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import MembershipMemberDetail from '@/components/accounting/membership/MembershipMemberDetail.vue'
import { MEMBERSHIP_LIST_PATH, membershipDetailNotice, membershipListNotice, readListHandoff, useMembershipAccess } from '@/components/accounting/membership/membershipPage'

/** Detail člena jako samostatná stránka, stejně jako detail kontaktu: celá šířka, vlastní adresa, otevření v novém panelu. */
const { t } = useI18n()
const route = useRoute(), router = useRouter()
const { supplierId, canManage } = useMembershipAccess()
// Člen patří firmě, pod kterou se stránka otevřela; po přepnutí firmy se pod novou nenačítá (odchází se na seznam).
const pageSupplier = supplierId.value
const memberId = computed(() => Number(route.params.id))
const periods = ref<AccountingPeriod[]>([])
const notice = ref('')
let alive = true, ticket = 0

/**
 * Návrat na seznam i s filtry, ze kterých uživatel přišel. Panely mají router nad paměťovou historií, proto adresu
 * seznamu předává seznam sám (readListHandoff); přímý odkaz nebo nový panel vede na seznam bez filtrů.
 */
const backTo = readListHandoff(pageSupplier)?.path ?? MEMBERSHIP_LIST_PATH

async function loadPeriods() {
  const run = ++ticket
  try {
    const rows = await accountingApi.listPeriods()
    if (alive && run === ticket) periods.value = rows
  } catch {
    if (alive && run === ticket) periods.value = []
  }
}

function close() { void router.push(backTo) }
/** Smazaný člen: zpět na seznam bez možnosti vrátit se na jeho adresu, seznam ohlásí smazání. */
function deleted() {
  membershipListNotice.value = t('nonprofitMembership.correction.done_delete')
  void router.replace(backTo)
}

watch(supplierId, now => { if (now !== pageSupplier) void router.replace(MEMBERSHIP_LIST_PATH) })
onMounted(() => {
  // Potvrzení akce ze seznamu, která sem vedla (založení člena).
  if (membershipDetailNotice.value) { notice.value = membershipDetailNotice.value; membershipDetailNotice.value = '' }
  void loadPeriods()
})
onBeforeUnmount(() => { alive = false; ticket++ })
</script>

<template>
  <div v-if="pageSupplier && supplierId === pageSupplier && memberId" class="space-y-3" data-section="member-page">
    <RouterLink :to="backTo" class="text-sm text-neutral-600 hover:text-neutral-900" data-action="back-to-list">{{ t('nonprofitMembership.backToList') }}</RouterLink>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="member-page-notice">{{ notice }}</p>
    <MembershipMemberDetail :key="memberId" :supplier="pageSupplier" :can-manage="canManage" :member-id="memberId" :periods="periods"
      @close="close()" @deleted="deleted()" />
  </div>
</template>
