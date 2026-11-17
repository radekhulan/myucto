<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi, type PurchaseRenumberPlan } from '@/api/purchaseInvoices'
import { renumberApi, type AgendaRenumberPlan, type RenumberAgenda } from '@/api/renumber'
import { cashApi, type CashRegister } from '@/api/cash'
import { apiErrorMessage } from '@/api/errors'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, disabledTitle } from '@/components/ui/buttonStyles'

/**
 * Přečíslování dokladů jednoho měsíce podle data. Záložky = agendy: přijaté doklady
 * (výchozí), pokladna, ostatní pohledávky, ostatní závazky a interní doklady. Náhled
 * se načte po výběru měsíce, samotné přečíslování až po potvrzení. Pravidla (co se
 * přečísluje, co zůstane, kdy je měsíc zamčený) drží backend
 * (PurchaseInvoiceRenumberService, AgendaRenumberService), stránka je jen zobrazuje.
 * Záložka se ukáže jen s právem zápisu dané agendy, stejným, jaké chce server.
 */
type Agenda = 'purchase' | RenumberAgenda

interface ViewRow {
  id: number
  date: string
  party: string
  detail: string
  kind: string
  number: string
  newNumber: string
  changed: boolean
  reason: string
}

interface ViewPlan {
  changes: ViewRow[]
  kept: ViewRow[]
  blocked: ViewRow[]
}

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const route = useRoute()
const router = useRouter()

const mode = computed(() => supplierStore.currentSupplier?.accounting_mode ?? '')
const cashMode = computed(() => mode.value === 'double_entry' || mode.value === 'tax_evidence')

const tabs = computed<Agenda[]>(() => {
  const out: Agenda[] = []
  if (auth.canWrite('purchase_invoices')) out.push('purchase')
  if (cashMode.value && auth.canWrite('cash.document.write')) out.push('cash')
  if (cashMode.value && auth.canWrite('other_items')) out.push('other_receivable', 'other_payable')
  if (mode.value === 'double_entry' && auth.hasCommercialFeatures && auth.canWrite('accounting.journal.write')) out.push('internal')
  return out
})

const agenda = computed<Agenda>(() => {
  const wanted = String(route.query.agenda ?? 'purchase') as Agenda
  return tabs.value.includes(wanted) ? wanted : (tabs.value[0] ?? 'purchase')
})

function selectTab(tab: Agenda): void {
  void router.replace({ query: { ...route.query, agenda: tab === 'purchase' ? undefined : tab } })
}

const now = new Date()
const period = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
const plan = ref<ViewPlan | null>(null)
const loading = ref(false)
const running = ref(false)
const keptOpen = ref(false)

const registers = ref<CashRegister[]>([])
const registerId = ref<number | null>(null)
const registersLoaded = ref(false)

const moves = computed(() => (plan.value?.changes ?? []).filter(c => c.changed))
const canRun = computed(() => !!plan.value && moves.value.length > 0 && plan.value.blocked.length === 0 && !auth.isClientRole)
const isPurchase = computed(() => agenda.value === 'purchase')
const showKind = computed(() => agenda.value === 'purchase' || agenda.value === 'cash')
const agendaGroup = computed(() => agenda.value === 'other_receivable' || agenda.value === 'other_payable' ? 'other' : agenda.value)

function docLink(id: number): string {
  switch (agenda.value) {
    case 'cash': return `/accounting/cash?document_id=${id}`
    case 'other_receivable':
    case 'other_payable': return `/other-items/${id}`
    case 'internal': return `/accounting/internal-documents/${id}`
    default: return `/purchase-invoices/${id}`
  }
}

function fromPurchase(p: PurchaseRenumberPlan): ViewPlan {
  const map = (rows: PurchaseRenumberPlan['changes']): ViewRow[] => rows.map(r => ({
    id: r.id, date: r.issue_date, party: r.vendor_name, detail: r.vendor_invoice_number,
    kind: t(`purchase_invoice.document_kind.${r.document_kind}`), number: r.varsymbol,
    newNumber: r.new_varsymbol ?? '', changed: !!r.changed, reason: r.reason ?? '',
  }))
  return { changes: map(p.changes), kept: map(p.kept), blocked: map(p.blocked) }
}

function fromAgenda(p: AgendaRenumberPlan): ViewPlan {
  const map = (rows: AgendaRenumberPlan['changes']): ViewRow[] => rows.map(r => ({
    id: r.id, date: r.date, party: r.partner, detail: r.description,
    kind: r.doc_type ? t(`cash.type.${r.doc_type}_short`) : '', number: r.number,
    newNumber: r.new_number ?? '', changed: !!r.changed, reason: r.reason ?? '',
  }))
  return { changes: map(p.changes), kept: map(p.kept), blocked: map(p.blocked) }
}

async function ensureRegisters(): Promise<void> {
  if (registersLoaded.value) return
  try {
    registers.value = await cashApi.listRegisters()
    registersLoaded.value = true
    registerId.value = (registers.value.find(r => r.is_default) ?? registers.value[0])?.id ?? null
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function fetchPlan(): Promise<ViewPlan | null> {
  const a = agenda.value
  if (a === 'purchase') return fromPurchase(await purchaseInvoicesApi.renumberPreview(period.value))
  if (a === 'cash' && registerId.value === null) return null
  return fromAgenda(await renumberApi.preview(a, period.value, { register_id: registerId.value ?? undefined }))
}

let seq = 0
async function load(): Promise<void> {
  if (!/^\d{4}-\d{2}$/.test(period.value) || !tabs.value.length) return
  const mine = ++seq
  loading.value = true
  plan.value = null
  try {
    if (agenda.value === 'cash') await ensureRegisters()
    const result = await fetchPlan()
    if (mine === seq) plan.value = result
  } catch (e) {
    if (mine === seq) {
      plan.value = null
      toast.error(apiErrorMessage(e))
    }
  } finally {
    if (mine === seq) loading.value = false
  }
}

async function run(): Promise<void> {
  if (!canRun.value || running.value) return
  if (!confirm(t('purchase_invoice.renumber.confirm', { count: moves.value.length, period: period.value }))) return
  running.value = true
  try {
    const a = agenda.value
    const result = a === 'purchase'
      ? await purchaseInvoicesApi.renumber(period.value)
      : await renumberApi.renumber(a, period.value, { register_id: registerId.value ?? undefined })
    toast.success(t('purchase_invoice.renumber.done', { count: result.renumbered }))
    plan.value = await fetchPlan()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    running.value = false
  }
}

watch([period, agenda, registerId], () => { void load() }, { immediate: true })
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ tabs.length <= 1 && isPurchase ? t('purchase_invoice.renumber.title') : t('purchase_invoice.renumber.page_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('purchase_invoice.renumber.not_offered') }}</p>
      </div>
    </div>

    <nav v-if="tabs.length > 1" class="flex flex-wrap gap-1 border-b border-neutral-200 mb-4" role="tablist"
      :aria-label="t('purchase_invoice.renumber.tabs.label')" data-test="renumber-tabs">
      <button v-for="tab in tabs" :key="tab" type="button" role="tab" :aria-selected="agenda === tab"
        :data-test="`renumber-tab-${tab}`"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="agenda === tab
          ? 'border-primary-600 text-primary-700'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="selectTab(tab)">
        {{ t(`purchase_invoice.renumber.tabs.${tab}`) }}
      </button>
    </nav>

    <p class="text-sm text-neutral-500 mb-4 max-w-3xl" data-test="renumber-intro">
      {{ isPurchase ? t('purchase_invoice.renumber.intro') : t(`purchase_invoice.renumber.agenda_intro.${agenda}`) }}
    </p>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-4 space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1" for="renumber-period">
            {{ isPurchase ? t('purchase_invoice.renumber.period') : t(`purchase_invoice.renumber.period_by.${agendaGroup}`) }}
          </label>
          <input id="renumber-period" v-model="period" type="month" data-test="renumber-period"
            class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div v-if="agenda === 'cash'">
          <label class="block text-sm font-medium text-neutral-700 mb-1" for="renumber-register">{{ t('purchase_invoice.renumber.register') }}</label>
          <select id="renumber-register" v-model="registerId" data-test="renumber-register"
            class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface min-w-48">
            <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" data-test="renumber-run"
          :disabled="!canRun || running" :title="disabledTitle(auth.isClientRole, t('purchase_invoice.extraction_review.readonly_client'))"
          @click="run">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('purchase_invoice.renumber.run', { count: moves.length }) }}
        </button>
      </div>
      <p class="text-xs text-neutral-500">
        {{ isPurchase ? t('purchase_invoice.renumber.tax_note') : t(`purchase_invoice.renumber.agenda_note.${agendaGroup}`) }}
      </p>
    </div>

    <p v-if="agenda === 'cash' && registersLoaded && !registers.length" class="text-sm text-neutral-500 mb-4">
      {{ t('purchase_invoice.renumber.no_register') }}
    </p>

    <div v-if="loading && !plan" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="plan">
      <div v-if="plan.blocked.length" class="mb-4 p-3 rounded-md border border-danger-500/40 bg-danger-50 text-sm text-danger-700" data-test="renumber-blocked">
        <p class="font-medium">{{ t('purchase_invoice.renumber.blocked_title') }}</p>
        <p class="mt-1">{{ t('purchase_invoice.renumber.blocked_hint') }}</p>
        <ul class="mt-1 space-y-0.5">
          <li v-for="b in plan.blocked" :key="b.id">
            <RouterLink :to="docLink(b.id)" class="font-mono hover:underline">{{ b.number }}</RouterLink>
            · {{ formatDate(b.date) }} · {{ t(`purchase_invoice.renumber.reason.${b.reason}`) }}
          </li>
        </ul>
      </div>

      <p v-if="!plan.changes.length" class="text-sm text-neutral-500 mb-4">{{ t('purchase_invoice.renumber.nothing') }}</p>
      <p v-else-if="!moves.length" class="text-sm text-success-600 mb-4">
        {{ isPurchase ? t('purchase_invoice.renumber.in_order') : t('purchase_invoice.renumber.in_order_agenda') }}
      </p>
      <p v-else class="text-sm text-neutral-700 mb-2">{{ t('purchase_invoice.renumber.changes_count', { count: moves.length }) }}</p>

      <div v-if="plan.changes.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <table class="hidden md:table w-full text-sm" data-test="renumber-table">
          <thead class="bg-neutral-50 text-neutral-500 text-xs">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_date') : t(`purchase_invoice.renumber.col_date_by.${agendaGroup}`) }}</th>
              <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_vendor') : t('purchase_invoice.renumber.col_partner') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_vendor_number') : t('purchase_invoice.renumber.col_description') }}</th>
              <th v-if="showKind" class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_kind') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_old') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_new') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in plan.changes" :key="c.id" class="border-t border-neutral-100" :class="c.changed ? '' : 'text-neutral-400'">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(c.date) }}</td>
              <td class="px-3 py-2">{{ c.party }}</td>
              <td class="px-3 py-2" :class="isPurchase ? 'font-mono' : ''">{{ c.detail }}</td>
              <td v-if="showKind" class="px-3 py-2">{{ c.kind }}</td>
              <td class="px-3 py-2 font-mono">
                <RouterLink :to="docLink(c.id)" class="hover:underline">{{ c.number }}</RouterLink>
              </td>
              <td class="px-3 py-2 font-mono" :class="c.changed ? 'font-semibold text-warning-700' : ''">
                {{ c.changed ? c.newNumber : t('purchase_invoice.renumber.unchanged') }}
              </td>
            </tr>
          </tbody>
        </table>
        <ul class="md:hidden divide-y divide-neutral-100">
          <li v-for="c in plan.changes" :key="c.id" class="p-3 text-sm" :class="c.changed ? '' : 'text-neutral-400'">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <span class="font-medium">{{ c.party }}</span>
              <span class="text-xs">{{ formatDate(c.date) }}</span>
            </div>
            <div class="text-xs text-neutral-500">{{ [c.kind, c.detail].filter(Boolean).join(' · ') }}</div>
            <div class="mt-1 font-mono text-xs">
              {{ c.number }} → <span :class="c.changed ? 'font-semibold text-warning-700' : ''">{{ c.changed ? c.newNumber : t('purchase_invoice.renumber.unchanged') }}</span>
            </div>
          </li>
        </ul>
      </div>

      <div v-if="plan.kept.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="renumber-kept">
        <button type="button" class="w-full flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-left hover:bg-neutral-50"
          :aria-expanded="keptOpen" @click="keptOpen = !keptOpen">
          <span>
            <span class="block text-sm font-medium text-neutral-700">{{ t('purchase_invoice.renumber.kept_title', { count: plan.kept.length }) }}</span>
            <span class="block text-xs text-neutral-500">{{ isPurchase ? t('purchase_invoice.renumber.kept_hint') : t('purchase_invoice.renumber.kept_hint_agenda') }}</span>
          </span>
          <svg class="w-4 h-4 text-neutral-400 transition-transform shrink-0" :class="keptOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
        </button>
        <template v-if="keptOpen">
          <table class="hidden md:table w-full text-sm border-t border-neutral-200">
            <thead class="bg-neutral-50 text-neutral-500 text-xs">
              <tr>
                <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_date') : t(`purchase_invoice.renumber.col_date_by.${agendaGroup}`) }}</th>
                <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_vendor') : t('purchase_invoice.renumber.col_partner') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ isPurchase ? t('purchase_invoice.renumber.col_vendor_number') : t('purchase_invoice.renumber.col_description') }}</th>
                <th v-if="showKind" class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_kind') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_old') }}</th>
                <th v-if="!isPurchase" class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_reason') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in plan.kept" :key="k.id" class="border-t border-neutral-100 text-neutral-600">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(k.date) }}</td>
                <td class="px-3 py-2">{{ k.party }}</td>
                <td class="px-3 py-2" :class="isPurchase ? 'font-mono' : ''">{{ k.detail }}</td>
                <td v-if="showKind" class="px-3 py-2">{{ k.kind }}</td>
                <td class="px-3 py-2 font-mono">
                  <RouterLink :to="docLink(k.id)" class="hover:underline">{{ k.number }}</RouterLink>
                </td>
                <td v-if="!isPurchase" class="px-3 py-2">{{ t(`purchase_invoice.renumber.reason.${k.reason}`) }}</td>
              </tr>
            </tbody>
          </table>
          <ul class="md:hidden divide-y divide-neutral-100 border-t border-neutral-200">
            <li v-for="k in plan.kept" :key="k.id" class="p-3 text-sm text-neutral-600">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-medium">{{ k.party }}</span>
                <span class="text-xs">{{ formatDate(k.date) }}</span>
              </div>
              <RouterLink :to="docLink(k.id)" class="font-mono text-xs hover:underline">{{ k.number }}</RouterLink>
              <span v-if="!isPurchase" class="text-xs"> · {{ t(`purchase_invoice.renumber.reason.${k.reason}`) }}</span>
            </li>
          </ul>
        </template>
      </div>
    </template>
  </div>
</template>
