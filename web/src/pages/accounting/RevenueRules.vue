<script setup lang="ts">
/**
 * Pravidla výnosů: editor pro tabulku revenue-rules, protějšek Pravidel nákladů.
 *
 * Pravidlo určí účet položky vydané faktury podle kódu položky, textu nebo sazby DPH.
 * Shoda = VŠECHNA vyplněná kritéria, první podle priority vyhrává.
 */
import { ref, reactive, computed, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { revenueRulesApi, revenueRuleErrorMessage, type RevenueRule, type RevenueRulePayload } from '@/api/revenueRules'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useResultAccounts } from '@/composables/useResultAccounts'
import { formatDate } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()
const resultAccounts = useResultAccounts()

const canWrite = computed(() => auth.canWrite('accounting'))

const items = ref<RevenueRule[]>([])
const loading = ref(false)
const busyId = ref<number | null>(null)
const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const activeFilter = ref<'' | 'true' | 'false'>('')

const accountByCode = computed(() => new Map(resultAccounts.accounts.value.map(a => [a.account_code, a])))
function accountName(code: string): string {
  return accountByCode.value.get(code)?.name ?? ''
}

async function load() {
  loading.value = true
  try {
    const [r] = await Promise.all([
      revenueRulesApi.listRules({
        active: activeFilter.value === '' ? undefined : activeFilter.value === 'true',
        page: page.value,
      }),
      resultAccounts.load(),
    ])
    if (r.items.length === 0 && r.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(r.total / r.per_page))
      return load()
    }
    items.value = r.items
    total.value = r.total
    perPage.value = r.per_page
  } catch (e) {
    toast.error(revenueRuleErrorMessage(e, t))
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}
function goPage(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  load()
}

async function toggleActive(r: RevenueRule) {
  if (busyId.value) return
  busyId.value = r.id
  try {
    await revenueRulesApi.updateRule(r.id, { is_active: !r.is_active })
    await load()
  } catch (e) {
    toast.error(revenueRuleErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function removeRule(r: RevenueRule) {
  if (busyId.value) return
  if (!window.confirm(t('accounting.expense_rules.confirm_delete', { name: r.name }))) return
  busyId.value = r.id
  try {
    await revenueRulesApi.deleteRule(r.id)
    toast.success(t('accounting.expense_rules.deleted'))
    await load()
  } catch (e) {
    toast.error(revenueRuleErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

// ── formulář ────────────────────────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({
  name: '',
  item_code: '',
  description_contains: '',
  vat_rate: null as number | null,
  target_account_code: '',
  priority: 100,
  is_active: true,
})

const hasCriteria = computed(() =>
  form.item_code.trim() !== '' || form.description_contains.trim() !== '' || (form.vat_rate !== null && String(form.vat_rate) !== ''),
)

function openNew() {
  editingId.value = null
  Object.assign(form, { name: '', item_code: '', description_contains: '', vat_rate: null, target_account_code: '', priority: 100, is_active: true })
  showForm.value = true
}

function openEdit(r: RevenueRule) {
  editingId.value = r.id
  Object.assign(form, {
    name: r.name,
    item_code: r.item_code ?? '',
    description_contains: r.description_contains ?? '',
    vat_rate: r.vat_rate,
    target_account_code: r.target_account_code,
    priority: r.priority,
    is_active: r.is_active,
  })
  showForm.value = true
}

async function saveRule() {
  if (!form.name.trim()) {
    toast.error(t('accounting.expense_rules.name_required'))
    return
  }
  if (!hasCriteria.value) {
    toast.error(t('accounting.revenue_rules.err_criteria_missing'))
    return
  }
  if (!form.target_account_code.trim()) {
    toast.error(t('accounting.revenue_rules.target_required'))
    return
  }
  saving.value = true
  try {
    const payload: RevenueRulePayload = {
      name: form.name.trim(),
      item_code: form.item_code.trim() || null,
      description_contains: form.description_contains.trim() || null,
      vat_rate: form.vat_rate === null || String(form.vat_rate) === '' ? null : Number(form.vat_rate),
      target_account_code: form.target_account_code.trim(),
      priority: Number(form.priority),
      is_active: form.is_active,
    }
    if (editingId.value === null) {
      await revenueRulesApi.createRule(payload)
      toast.success(t('accounting.expense_rules.created'))
    } else {
      await revenueRulesApi.updateRule(editingId.value, payload)
      toast.success(t('accounting.expense_rules.updated'))
    }
    showForm.value = false
    await load()
  } catch (e) {
    toast.error(revenueRuleErrorMessage(e, t))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 v-if="!embedded" class="text-2xl font-semibold">{{ t('accounting.revenue_rules.title') }}</h1>
        <h2 v-else class="text-xl font-semibold">{{ t('accounting.revenue_rules.title') }}</h2>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.revenue_rules.subtitle') }}</p>
      </div>
      <button v-if="canWrite" @click="openNew" :class="btnFilled('primary')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.expense_rules.new') }}
      </button>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label :for="`${pageId}-filter-active`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.filter_active') }}</label>
          <select :id="`${pageId}-filter-active`" v-model="activeFilter" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.expense_rules.active_all') }}</option>
            <option value="true">{{ t('accounting.expense_rules.active_yes') }}</option>
            <option value="false">{{ t('accounting.expense_rules.active_no') }}</option>
          </select>
        </div>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" icon="cycle"
        :title="t('accounting.revenue_rules.empty')"
        :cta="canWrite ? t('accounting.expense_rules.new') : undefined"
        @action="openNew" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_name') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_criteria') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.revenue_rules.col_target') }}</th>
              <th class="text-right font-medium px-3 py-2">{{ t('accounting.expense_rules.col_priority') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_usage') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_active') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items" :key="r.id" class="border-t border-neutral-100" :class="{ 'opacity-50': !r.is_active }">
              <td class="px-3 py-2"><div class="font-medium text-neutral-700">{{ r.name }}</div></td>
              <td class="px-3 py-2 text-xs text-neutral-500">
                <div class="space-y-0.5">
                  <div v-if="r.item_code"><span class="text-neutral-400">{{ t('accounting.revenue_rules.code_label') }}:</span> <span class="font-mono">{{ r.item_code }}</span></div>
                  <div v-if="r.description_contains" class="italic">„{{ r.description_contains }}"</div>
                  <div v-if="r.vat_rate !== null" class="font-mono text-neutral-400">{{ t('accounting.revenue_rules.vat_label', { rate: r.vat_rate }) }}</div>
                </div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap font-mono text-xs text-neutral-700" :title="accountName(r.target_account_code)">→ {{ r.target_account_code }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ r.priority }}</td>
              <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">
                <div>{{ t('accounting.expense_rules.usage', { count: r.hit_count }) }}</div>
                <div v-if="r.last_hit_at" class="text-neutral-400">{{ formatDate(r.last_hit_at) }}</div>
                <div v-else class="text-neutral-400">{{ t('accounting.expense_rules.never_used') }}</div>
              </td>
              <td class="px-3 py-2">
                <button v-if="canWrite" @click="toggleActive(r)" :disabled="busyId === r.id"
                  class="cursor-pointer text-xs px-2 py-0.5 rounded font-medium disabled:opacity-50"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('accounting.expense_rules.active_yes') : t('accounting.expense_rules.active_no') }}
                </button>
                <span v-else class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('accounting.expense_rules.active_yes') : t('accounting.expense_rules.active_no') }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div v-if="canWrite" class="flex flex-wrap items-center justify-end gap-1">
                  <button @click="openEdit(r)" :class="btnOutlineSm('primary')" :title="t('common.edit')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button @click="removeRule(r)" :disabled="busyId === r.id" :class="btnOutlineSm('danger')" :title="t('common.delete')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="totalPages > 1" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-neutral-100">
        <span class="text-xs text-neutral-500">{{ t('accounting.expense_rules.count', { total }) }}</span>
        <div class="flex items-center gap-2">
          <button :disabled="page <= 1" @click="goPage(page - 1)" :class="btnOutlineSm('neutral')">{{ t('common.previous') }}</button>
          <span class="text-xs text-neutral-500">{{ page }} / {{ totalPages }}</span>
          <button :disabled="page >= totalPages" @click="goPage(page + 1)" :class="btnOutlineSm('neutral')">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>

    <Modal v-if="showForm" :title="editingId === null ? t('accounting.expense_rules.new') : t('accounting.expense_rules.edit')" @close="showForm = false">
      <p class="text-sm text-neutral-600 mb-4">{{ t('accounting.revenue_rules.form_help') }}</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label :for="`${pageId}-name`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_name') }} *</label>
          <input :id="`${pageId}-name`" v-model="form.name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>

        <div class="sm:col-span-2 border border-neutral-200 rounded-md p-3">
          <div class="text-xs font-medium text-neutral-500 mb-2">{{ t('accounting.revenue_rules.criteria_group') }}</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label :for="`${pageId}-code`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.revenue_rules.form_item_code') }}</label>
              <input :id="`${pageId}-code`" v-model="form.item_code" maxlength="64" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
              <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.revenue_rules.item_code_hint') }}</p>
            </div>
            <div>
              <label :for="`${pageId}-vat`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.revenue_rules.form_vat_rate') }}</label>
              <input :id="`${pageId}-vat`" v-model.number="form.vat_rate" type="number" min="0" max="100" step="0.01"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
            </div>
            <div class="sm:col-span-2">
              <label :for="`${pageId}-description`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.revenue_rules.form_description_contains') }}</label>
              <input :id="`${pageId}-description`" v-model="form.description_contains" maxlength="120" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
          </div>
          <p class="text-xs mt-2" :class="hasCriteria ? 'text-neutral-400' : 'text-danger-500'">{{ t('accounting.revenue_rules.criteria_hint') }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.revenue_rules.form_target_account') }} *</label>
          <ChartAccountSelect v-model="form.target_account_code" :accounts="resultAccounts.issuedItemAccounts.value"
            :input-id="`${pageId}-target-account`" :aria-label="t('accounting.revenue_rules.form_target_account')" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.revenue_rules.target_account_hint') }}</p>
        </div>

        <div>
          <label :for="`${pageId}-priority`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_priority') }}</label>
          <input :id="`${pageId}-priority`" v-model.number="form.priority" type="number" min="0" max="999" step="1"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.priority_hint') }}</p>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.expense_rules.form_active') }}
          </label>
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2 mt-4">
        <button @click="showForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="saving || !hasCriteria || !form.name.trim() || !form.target_account_code.trim()" @click="saveRule" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </Modal>
  </div>
</template>
