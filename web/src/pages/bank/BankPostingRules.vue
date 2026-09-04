<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { bankPostingApi, bankPostingErrorMessage, type BankPostingRule } from '@/api/bankPosting'
import RuleFormModal from '@/components/bank/RuleFormModal.vue'
import RuleTemplatesModal from '@/components/bank/RuleTemplatesModal.vue'
import RuleHistoryModal from '@/components/bank/RuleHistoryModal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const emit = defineEmits<{ 'counts-changed': [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const COLUMNS: ColumnDef[] = [
  { key: 'state', labelKey: 'bank.posting.col_state', required: true },
  { key: 'pattern', labelKey: 'bank.posting.col_pattern', required: true },
  { key: 'accounts', labelKey: 'bank.posting.col_accounts' },
  { key: 'mode', labelKey: 'bank.posting.rule_mode' },
  { key: 'priority', labelKey: 'bank.posting.rule_priority', defaultHidden: true },
  { key: 'stats', labelKey: 'bank.posting.col_stats' },
  { key: 'created', labelKey: 'bank.posting.col_created', defaultHidden: true },
  { key: 'author', labelKey: 'bank.posting.col_author', defaultHidden: true },
]
const tbl = useTablePrefs('bank_posting_rules', COLUMNS)

const rules = ref<BankPostingRule[]>([])
const page = ref(1)
const perPage = 50
const total = ref(0)
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const busyId = ref<number | null>(null)
const templatesOpen = ref(false)
const historyRule = ref<BankPostingRule | null>(null)

const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})
function accountName(code: string): string {
  return accountByCode.value[code]?.name ?? code
}

async function load() {
  loading.value = true
  try {
    const [r, accs] = await Promise.all([bankPostingApi.listRules({ page: page.value, per_page: perPage }), accountingApi.listAccounts()])
    if (r.items.length === 0 && r.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(r.total / r.per_page))
      return
    }
    rules.value = r.items
    total.value = r.total
    accounts.value = accs
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(page, load)

// Modal (create/edit)
const modalOpen = ref(false)
const editing = ref<BankPostingRule | null>(null)
function openCreate() { editing.value = null; modalOpen.value = true }
function openEdit(r: BankPostingRule) { editing.value = r; modalOpen.value = true }
function onSaved() {
  modalOpen.value = false
  emit('counts-changed')
  load()
}

async function onTemplateApplied() {
  templatesOpen.value = false
  toast.success(t('bank.templates.applied'))
  emit('counts-changed')
  await load()
}

async function toggleActive(r: BankPostingRule) {
  if (busyId.value) return
  busyId.value = r.id
  try {
    await bankPostingApi.updateRule(r.id, { is_active: !r.is_active })
    await load()
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function remove(r: BankPostingRule) {
  if (busyId.value) return
  if (!confirm(t('bank.posting.rule_delete_confirm', { name: r.name }))) return
  busyId.value = r.id
  try {
    await bankPostingApi.deleteRule(r.id)
    toast.success(t('common.deleted'))
    emit('counts-changed')
    await load()
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function promote(r: BankPostingRule) {
  if (busyId.value || !confirm(t('automation.rules.promote_confirm', { name: r.name }))) return
  busyId.value = r.id
  try {
    await bankPostingApi.promoteRule(r.id)
    toast.success(t('automation.rules.promoted'))
    await load()
  } catch (e) { toast.error(bankPostingErrorMessage(e, t)) }
  finally { busyId.value = null }
}

async function demote(r: BankPostingRule) {
  if (busyId.value || !confirm(t('automation.rules.demote_confirm', { name: r.name }))) return
  busyId.value = r.id
  try {
    await bankPostingApi.demoteRule(r.id)
    toast.success(t('automation.rules.demoted'))
    await load()
  } catch (e) { toast.error(bankPostingErrorMessage(e, t)) }
  finally { busyId.value = null }
}

async function backfill(r: BankPostingRule) {
  if (busyId.value || !confirm(t('automation.rules.backfill_confirm', { name: r.name }))) return
  busyId.value = r.id
  try {
    const result = await bankPostingApi.backfillRule(r.id)
    toast.success(t('automation.rules.backfill_done', { count: result.backfilled }))
    emit('counts-changed')
    await load()
  } catch (e) { toast.error(bankPostingErrorMessage(e, t)) }
  finally { busyId.value = null }
}

function amountRange(r: BankPostingRule): string {
  if (r.amount_min == null && r.amount_max == null) return ''
  if (r.amount_min == null) return `≤ ${formatMoney(r.amount_max!, r.applies_currency)}`
  if (r.amount_max == null) return `≥ ${formatMoney(r.amount_min, r.applies_currency)}`
  const lo = formatMoney(r.amount_min, r.applies_currency)
  const hi = formatMoney(r.amount_max, r.applies_currency)
  return `${lo} – ${hi}`
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
      <p class="text-sm text-neutral-500">{{ t('bank.posting.rules_subtitle') }}</p>
      <div class="flex items-center gap-2 flex-wrap">
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button v-if="auth.canWrite('bank.rules')" @click="templatesOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
          {{ t('bank.templates.from_template') }}
        </button>
        <button v-if="auth.canWrite('bank.rules')" @click="openCreate" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('bank.posting.rule_create_title') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="rules.length === 0" boxed icon="cycle" :title="t('bank.posting.rules_empty')"
      :cta="auth.canWrite('bank.rules') ? t('bank.posting.rule_create_title') : undefined"
      :secondary="t('accounting.setup_assistant.open')"
      secondary-to="/accounting/setup-assistant" secondary-icon="chart"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="tbl.isVisible('state')" class="px-3 py-2 text-left font-medium w-24">{{ t('bank.posting.col_state') }}</th>
              <th v-if="tbl.isVisible('pattern')" class="px-3 py-2 text-left font-medium">{{ t('bank.posting.col_pattern') }}</th>
              <th v-if="tbl.isVisible('accounts')" class="px-3 py-2 text-left font-medium w-28">{{ t('bank.posting.col_accounts') }}</th>
              <th v-if="tbl.isVisible('mode')" class="px-3 py-2 text-center font-medium w-28">{{ t('bank.posting.rule_mode') }}</th>
              <th v-if="tbl.isVisible('priority')" class="px-3 py-2 text-right font-medium w-20">{{ t('bank.posting.rule_priority') }}</th>
              <th v-if="tbl.isVisible('stats')" class="px-3 py-2 text-left font-medium w-40">{{ t('bank.posting.col_stats') }}</th>
              <th v-if="tbl.isVisible('created')" class="px-3 py-2 text-left font-medium w-24">{{ t('bank.posting.col_created') }}</th>
              <th v-if="tbl.isVisible('author')" class="px-3 py-2 text-left font-medium w-32">{{ t('bank.posting.col_author') }}</th>
              <th class="px-3 py-2 min-w-[22rem]"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in rules" :key="r.id" :class="{ 'opacity-50': !r.is_active }">
              <td v-if="tbl.isVisible('state')" class="px-3 py-2">
                <button v-if="auth.canWrite('bank.rules')" @click="toggleActive(r)" :disabled="busyId === r.id"
                  class="cursor-pointer text-xs px-2 py-0.5 rounded font-medium disabled:opacity-50"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('bank.posting.active') : t('bank.posting.inactive') }}
                </button>
                <span v-else class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('bank.posting.active') : t('bank.posting.inactive') }}
                </span>
              </td>
              <td v-if="tbl.isVisible('pattern')" class="px-3 py-2 text-xs">
                <div class="flex items-center gap-1.5">
                  <span :title="r.direction === 'incoming' ? t('bank.posting.dir_incoming') : t('bank.posting.dir_outgoing')"
                    :class="r.direction === 'incoming' ? 'text-success-600' : 'text-danger-500'">
                    {{ r.direction === 'incoming' ? '↓' : '↑' }}
                  </span>
                  <span class="font-medium text-neutral-700">{{ r.name }}</span>
                </div>
                <div class="text-neutral-500 mt-0.5 space-x-2">
                  <span v-if="r.counterparty_account" class="font-mono">{{ r.counterparty_account }}<span v-if="r.counterparty_bank">/{{ r.counterparty_bank }}</span></span>
                  <span v-if="r.variable_symbol" class="font-mono">VS {{ r.variable_symbol }}</span>
                  <span v-if="r.message_contains" class="italic">„{{ r.message_contains }}"</span>
                  <span v-if="amountRange(r)" class="font-mono">{{ amountRange(r) }}</span>
                </div>
              </td>
              <td v-if="tbl.isVisible('accounts')" class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                :title="`${accountName(r.debit_account_code)} / ${accountName(r.credit_account_code)}`">
                {{ r.debit_account_code }} / {{ r.credit_account_code }}
              </td>
              <td v-if="tbl.isVisible('mode')" class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="r.mode === 'auto' ? 'bg-success-50 text-success-600' : 'bg-primary-100 text-primary-700'">
                  {{ r.mode === 'auto' ? t('bank.posting.mode_auto') : t('bank.posting.mode_suggest') }}
                </span>
              </td>
              <td v-if="tbl.isVisible('priority')" class="px-3 py-2 text-right font-mono text-xs">{{ r.priority }}</td>
              <td v-if="tbl.isVisible('stats')" class="px-3 py-2 text-xs text-neutral-500">
                <span>{{ t('bank.posting.hits', { count: r.hit_count }) }}</span>
                <span v-if="r.last_hit_at" class="ml-1">· {{ formatDate(r.last_hit_at) }}</span>
                <span v-if="r.auto_amount_cap != null" class="block mt-0.5">
                  {{ t('bank.posting.rule_auto_cap') }}: {{ formatMoney(r.auto_amount_cap, r.applies_currency) }}
                </span>
                <span v-if="r.mode === 'suggest' && r.approved_streak > 0" class="mt-1 inline-flex rounded bg-primary-50 px-2 py-0.5 font-medium text-primary-700">
                  {{ t('automation.rules.streak', { n: Math.min(r.approved_streak, 5) }) }}
                </span>
                <button v-if="r.promotion_candidate && auth.canWrite('bank.rules')" type="button" @click="promote(r)"
                  :disabled="busyId === r.id" :class="`${btnOutlineSm('success')} mt-1`">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>
                  {{ t('automation.rules.promote') }}
                </button>
                <span v-if="r.rejected_streak >= 2" class="ml-1 text-warning-600" :title="t('bank.posting.rejects', { count: r.rejected_streak })">
                  ⚠ {{ r.rejected_streak }}
                </span>
              </td>
              <td v-if="tbl.isVisible('created')" class="px-3 py-2 text-xs whitespace-nowrap">{{ formatDate(r.created_at) }}</td>
              <td v-if="tbl.isVisible('author')" class="px-3 py-2 text-xs text-neutral-500">
                <div class="truncate max-w-[10rem]">{{ r.created_by_name || '—' }}</div>
              </td>
              <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
                <div class="flex flex-nowrap justify-end gap-1">
                <button type="button" @click="historyRule = r" :class="btnOutlineSm('neutral')">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                  {{ t('automation.rules.history') }}
                </button>
                <button v-if="r.mode === 'auto' && auth.canWrite('bank.rules')" type="button" @click="demote(r)" :disabled="busyId === r.id" :class="btnOutlineSm('warning')">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                  {{ t('automation.rules.demote') }}
                </button>
                <button v-if="r.is_active && auth.canWrite('bank.rules')" type="button" @click="backfill(r)" :disabled="busyId === r.id" :class="btnOutlineSm('primary')">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                  {{ t('automation.rules.backfill') }}
                </button>
                <button v-if="auth.canWrite('bank.rules')" type="button" @click="openEdit(r)" :class="btnOutlineSm('primary')">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
                <button v-if="auth.canWrite('bank.rules')" type="button" @click="remove(r)" :disabled="busyId === r.id" :class="btnOutlineSm('danger')">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  {{ t('common.delete') }}
                </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="r in rules" :key="`m-${r.id}`" class="p-3 space-y-1.5" :class="{ 'opacity-50': !r.is_active }">
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium text-sm text-neutral-700">
              <span :class="r.direction === 'incoming' ? 'text-success-600' : 'text-danger-500'">{{ r.direction === 'incoming' ? '↓' : '↑' }}</span>
              {{ r.name }}
            </span>
            <span class="text-xs px-2 py-0.5 rounded font-medium"
              :class="r.mode === 'auto' ? 'bg-success-50 text-success-600' : 'bg-primary-100 text-primary-700'">
              {{ r.mode === 'auto' ? t('bank.posting.mode_auto') : t('bank.posting.mode_suggest') }}
            </span>
          </div>
          <div class="text-xs text-neutral-500 space-x-2">
            <span v-if="r.counterparty_account" class="font-mono">{{ r.counterparty_account }}</span>
            <span v-if="r.variable_symbol" class="font-mono">VS {{ r.variable_symbol }}</span>
            <span v-if="r.message_contains" class="italic">„{{ r.message_contains }}"</span>
          </div>
          <div class="flex items-center justify-between text-xs">
            <span class="font-mono text-neutral-500">
              {{ r.debit_account_code }} / {{ r.credit_account_code }}
              <span v-if="r.auto_amount_cap != null" class="block font-sans mt-0.5">
                {{ t('bank.posting.rule_auto_cap') }}: {{ formatMoney(r.auto_amount_cap, r.applies_currency) }}
              </span>
            </span>
            <div class="flex flex-wrap justify-end gap-1">
              <button type="button" @click="historyRule = r" :class="btnOutlineSm('neutral')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>{{ t('automation.rules.history') }}</button>
              <button v-if="r.promotion_candidate && auth.canWrite('bank.rules')" type="button" @click="promote(r)" :class="btnOutlineSm('success')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>{{ t('automation.rules.promote') }}</button>
              <button v-else-if="r.mode === 'auto' && auth.canWrite('bank.rules')" type="button" @click="demote(r)" :class="btnOutlineSm('warning')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>{{ t('automation.rules.demote') }}</button>
              <button v-if="r.is_active && auth.canWrite('bank.rules')" type="button" @click="backfill(r)" :disabled="busyId === r.id" :class="btnOutlineSm('primary')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>{{ t('automation.rules.backfill') }}</button>
              <button v-if="auth.canWrite('bank.rules')" @click="toggleActive(r)" :disabled="busyId === r.id"
                class="cursor-pointer text-neutral-500 disabled:opacity-50">{{ r.is_active ? t('bank.posting.inactive') : t('bank.posting.active') }}</button>
              <button v-if="auth.canWrite('bank.rules')" type="button" @click="openEdit(r)" :class="btnOutlineSm('primary')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button>
              <button v-if="auth.canWrite('bank.rules')" type="button" @click="remove(r)" :disabled="busyId === r.id" :class="btnOutlineSm('danger')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <PaginationBar :page="page" :per-page="perPage" :total="total" @update:page="page = $event" />

    <RuleFormModal v-if="modalOpen" :rule="editing" @saved="onSaved" @close="modalOpen = false" />
    <RuleTemplatesModal v-if="templatesOpen" @applied="onTemplateApplied" @close="templatesOpen = false" />
    <RuleHistoryModal v-if="historyRule" :rule="historyRule" @close="historyRule = null" />
  </div>
</template>
