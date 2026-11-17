<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import MembershipMemberDetail from '@/components/accounting/membership/MembershipMemberDetail.vue'
import { forgetMemberInHandoff, MEMBERSHIP_LIST_PATH, readListHandoff, useMembershipAccess } from '@/components/accounting/membership/membershipPage'
import { usePaneId } from '@/workspace/paneActivity'

/** Detail člena jako samostatná stránka, stejně jako detail kontaktu: celá šířka, vlastní adresa, otevření v novém panelu. */
const { t } = useI18n()
const toast = useToast()
const route = useRoute(), router = useRouter()
const { supplierId, canManage } = useMembershipAccess()
// Člen patří firmě, pod kterou se stránka otevřela; po přepnutí firmy se pod novou nenačítá (odchází se na seznam).
const pageSupplier = supplierId.value
const paneId = usePaneId()
const pane = paneId.value === null ? null : String(paneId.value)
const memberId = computed(() => Number(route.params.id))
const periods = ref<AccountingPeriod[]>([])
let alive = true, ticket = 0

/**
 * Návrat na seznam i s filtry, ze kterých uživatel přišel. Panely mají router nad paměťovou historií, proto adresu
 * seznamu předává seznam sám (readListHandoff); přímý odkaz nebo nový panel vede na seznam bez filtrů.
 */
const backTo = readListHandoff(pageSupplier, pane)?.path ?? MEMBERSHIP_LIST_PATH

async function loadPeriods() {
  const run = ++ticket
  try {
    const rows = await accountingApi.listPeriods()
    if (alive && run === ticket) periods.value = rows
  } catch {
    if (alive && run === ticket) periods.value = []
  }
}

/** Smazaný člen: zpět na seznam bez možnosti vrátit se na jeho adresu; oznámení přežije přechod. */
function deleted() {
  forgetMemberInHandoff(pageSupplier, pane, memberId.value)
  toast.success(t('nonprofitMembership.correction.done_delete'))
  void router.replace(backTo)
}

watch(supplierId, now => { if (now !== pageSupplier) void router.replace(MEMBERSHIP_LIST_PATH) })
onMounted(() => { void loadPeriods() })
onBeforeUnmount(() => { alive = false; ticket++ })
</script>

<template>
  <MembershipMemberDetail v-if="pageSupplier && supplierId === pageSupplier && memberId" :key="memberId" :supplier="pageSupplier" :can-manage="canManage" :member-id="memberId" :periods="periods"
    @deleted="deleted()">
    <template #back>
      <RouterLink :to="backTo" class="text-sm text-neutral-600 hover:text-neutral-900" data-action="back-to-list">{{ t('nonprofitMembership.backToList') }}</RouterLink>
    </template>
  </MembershipMemberDetail>
</template>
