<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type ActionItem, type ActionItemsResult } from '@/api/crm'
import { apiErrorMessage } from '@/api/errors'
import { ensureInstanceStatus, instanceStatus } from '@/api/instanceStatus'
import { resolveHostingActions, type HostingActionSeverity } from '@/api/hostingActions'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const actionItems = ref<ActionItemsResult | null>(null)
const openMenuIdx = ref<number | null>(null)

/**
 * Provoz instalace — nahoře a barevně.
 *
 * Proč mimo seznam ze serveru: tyhle položky nejsou úkoly z účetnictví, které
 * si jde odložit na příště. Neuhrazená platba a zaplněný disk jsou stavy, které
 * uživateli něco ZAVÍRAJÍ, takže se nedají odkliknout ani odložit — a musí být
 * první, protože když nejde zaplatit, je jedno, kolik faktur čeká na zaúčtování.
 *
 * ⚠️ Na self-hosted instalaci je seznam prázdný a nevykreslí se ani řádek;
 * `instanceStatus` tam nic nenačte (viz `ensureInstanceStatus`).
 */
const hostingItems = computed(() => resolveHostingActions(instanceStatus.status.value))

watch(
  () => [auth.isManagedInstallation, auth.isSuperadmin] as const,
  ([managed, superadmin]) => { void ensureInstanceStatus({ managed, superadmin }) },
  { immediate: true },
)

/** Barvy podle závažnosti. `info` je oznámení, ne poplach — proto brand, ne jantar. */
const HOSTING_TONE: Record<HostingActionSeverity, { row: string; dot: string; title: string }> = {
  high:   { row: 'bg-danger-50 border-l-4 border-danger-500',      dot: 'bg-danger-500',  title: 'text-danger-600' },
  medium: { row: 'bg-warning-50 border-l-4 border-warning-500',    dot: 'bg-warning-500', title: 'text-warning-600' },
  info:   { row: 'bg-primary-50/60 border-l-4 border-primary-400', dot: 'bg-primary-400', title: 'text-primary-800' },
}

/** Termín se formátuje až tady; `null` znamená, že v textu žádný nebude. */
function hostingHint(item: { hintKey: string; at: number | null; percent: number | null; quotaGb: number | null; active: number | null; limit: number | null }): string {
  return t(item.hintKey, {
    date: item.at === null ? '' : new Date(item.at * 1000).toLocaleDateString(),
    percent: item.percent === null ? '' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(item.percent),
    gb: item.quotaGb ?? '',
    active: item.active ?? '',
    limit: item.limit ?? '',
  })
}

/** Kolik toho celkem čeká — hostingové položky se počítají taky. */
const totalCount = computed(() => (actionItems.value?.total ?? 0) + hostingItems.value.length)

function toggleMenu(idx: number) {
  openMenuIdx.value = openMenuIdx.value === idx ? null : idx
}

async function dismissItem(itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') {
  try {
    await crmApi.dismissActionItem(itemType, mode)
    openMenuIdx.value = null
    await reload()
    toast.success(t('crm.action_items.dismissed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function restoreAllDismissed() {
  try {
    const r = await crmApi.restoreAllActionItems()
    await reload()
    toast.success(t('crm.action_items.restored_n', { n: r.restored }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

/**
 * Doplatek DPPO se dotahuje ZVLÁŠŤ. Je to živá projekce celoročního účetnictví
 * (naměřeno 444 z 473 ms původního feedu), takže kdyby byl součástí action-items,
 * čekal by dashboard půl vteřiny na jednu dlaždici. Takhle se seznam vykreslí hned
 * a daňová položka do něj přibude, až dopočítá.
 *
 * Selhání je tiché a bez následků — zbytek seznamu je už zobrazený.
 */
async function loadTaxBalance() {
  try {
    const r = await crmApi.actionItemTaxBalance()
    if (r.item && actionItems.value) {
      appendItem(r.item)
    }
  } catch {
    // dlaždice se prostě neobjeví
  }
}

function appendItem(item: ActionItem) {
  if (!actionItems.value) return
  // Ochrana proti dvojímu vložení (dismiss/restore znovu načte seznam i dlaždici).
  if (actionItems.value.items.some(i => i.type === item.type)) return
  actionItems.value.items.push(item)
  actionItems.value.total += 1
}

async function reload() {
  actionItems.value = await crmApi.actionItems()
  await loadTaxBalance()
}

onMounted(async () => {
  try {
    actionItems.value = await crmApi.actionItems()
  } catch {
    // tichý fail — widget se prostě nezobrazí
    return
  }
  void loadTaxBalance()
})
</script>

<template>
  <!-- ═══ Action items widget (daily TODO) ═══ -->
  <!-- Nejcennější widget na dashboardu (co mám dneska udělat) měl doteď vzhled
       nejtišší karty. Zvednutá plocha + brand rámeček mu dávají váhu, kterou
       jeho obsah zaslouží. `to-white` bylo navíc v dark módu doslova bílé —
       gradient musí končit v tokenu plochy, ne v natvrdo zapsané barvě. -->
  <!-- Pozor: kontejner NESMÍ mít `overflow-hidden`. Nabídka odložení je `absolute`
       uvnitř řádku, takže u posledního řádku přesahuje pod kartu a `overflow-hidden`
       ji uřízne. Zaoblení proto drží přímo hlavička a poslední řádek. -->
  <div v-if="totalCount > 0" class="bg-surface-raised border border-primary-500/25 rounded-xl shadow-md">
    <header class="px-5 py-3.5 border-b border-neutral-200 flex items-center justify-between gap-3 bg-gradient-to-r from-primary-50 to-surface rounded-t-xl">
      <h3 class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-[0.12em] text-primary-700">
        <span aria-hidden="true">⚡</span>
        {{ t('crm.action_items.title') }}
        <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 bg-primary-600 text-white rounded-full text-xs font-semibold tabular-nums">{{ totalCount }}</span>
      </h3>
      <button v-if="actionItems && actionItems.dismissed_count > 0 && auth.canWrite('dashboard')" type="button" @click="restoreAllDismissed"
        class="text-xs text-neutral-500 hover:text-primary-600 underline decoration-dotted">
        {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
      </button>
    </header>
    <div class="divide-y divide-neutral-100">
      <!-- ═══ Provoz instalace — vždy první a barevně ═══
           ⚠️ Bez nabídky odložení: neuhrazená platba ani plný disk nejsou úkol,
           který jde odsunout na příště, a zaváděné rozšíření není výzva k nákupu. -->
      <RouterLink
        v-for="hosting in hostingItems" :key="hosting.kind"
        :to="hosting.link"
        class="flex items-center gap-3 px-5 py-3 hover:brightness-[0.98]"
        :class="HOSTING_TONE[hosting.severity].row"
        :data-hosting-action="hosting.kind"
      >
        <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :class="HOSTING_TONE[hosting.severity].dot"></span>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-semibold" :class="HOSTING_TONE[hosting.severity].title">{{ t(hosting.titleKey) }}</div>
          <div class="text-xs text-neutral-600 mt-0.5">{{ hostingHint(hosting) }}</div>
        </div>
        <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
      </RouterLink>

      <div v-for="(item, idx) in (actionItems?.items ?? [])" :key="idx"
        class="relative px-5 py-3 hover:bg-neutral-50 last:rounded-b-xl">
        <div class="flex items-center justify-between">
        <RouterLink :to="item.link" class="flex items-center gap-3 flex-1 min-w-0">
          <span :class="['inline-block w-2.5 h-2.5 rounded-full shrink-0',
            item.severity === 'high' ? 'bg-danger-500' :
            item.severity === 'medium' ? 'bg-warning-500' : 'bg-neutral-400']"></span>
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-700">{{ item.title }}</div>
            <div class="text-xs text-neutral-500 mt-0.5">{{ item.hint }}</div>
          </div>
        </RouterLink>
        <div class="flex items-center gap-1 ml-3 shrink-0">
          <RouterLink :to="item.link" class="text-neutral-400 hover:text-primary-700 p-1 transition-transform hover:translate-x-0.5" :title="t('crm.action_items.go_to')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
          </RouterLink>
          <button v-if="auth.canWrite('dashboard')" type="button" @click.stop="toggleMenu(idx)"
            class="text-neutral-400 hover:text-neutral-700 p-1 rounded hover:bg-neutral-100"
            :title="t('crm.action_items.dismiss')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
          </button>
          <div v-if="(openMenuIdx === idx) && auth.canWrite('dashboard')"
            class="absolute right-3 top-12 z-20 bg-surface border border-neutral-200 rounded-md shadow-lg py-1 w-[280px]"
            @click.stop>
            <div class="px-3 py-1.5 text-xs uppercase tracking-wide text-neutral-500 font-semibold border-b border-neutral-100">
              {{ t('crm.action_items.dismiss_title') }}
            </div>
            <button type="button" @click="dismissItem(item.type, 'day')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_day') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_day_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'week')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_week') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_week_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'historical')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_historical') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_historical_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'forever')"
              class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-danger-600 border-t border-neutral-100">
              {{ t('crm.action_items.dismiss_forever') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_forever_hint') }}</div>
            </button>
          </div>
        </div>
        </div>
        <!-- Rozpad (FV / PF / banka) — jen u položek s breakdown (unbooked_documents) -->
        <div v-if="item.breakdown && item.breakdown.length"
          class="flex flex-wrap gap-2 mt-2 ml-[1.375rem]">
          <RouterLink v-for="b in item.breakdown" :key="b.key" :to="b.link"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-neutral-100 hover:bg-primary-50 text-xs text-neutral-600 hover:text-primary-700 whitespace-nowrap">
            <span>{{ t('crm.action_items.breakdown_' + b.key) }}</span>
            <!-- Bez počtu jde o nabídku cesty (průvodce), ne o rozpad čísla — „0" by tam lhala. -->
            <span v-if="b.count !== undefined && b.count !== null" class="font-semibold">{{ b.count }}</span>
          </RouterLink>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ Standalone restore hint — pro případ že total=0 ale jsou skryté ═══ -->
  <div v-else-if="actionItems && actionItems.dismissed_count > 0"
    class="bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2 flex items-center justify-between text-sm">
    <span class="text-neutral-500">
      {{ t('crm.action_items.all_clear_n_hidden', { n: actionItems.dismissed_count }) }}
    </span>
    <button v-if="auth.canWrite('dashboard')" type="button" @click="restoreAllDismissed"
      class="text-xs text-primary-600 hover:text-primary-700 underline decoration-dotted">
      {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
    </button>
  </div>
</template>
