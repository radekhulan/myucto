<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  adminApi,
  type AdminBankRuleTemplate,
  type AdminBankRuleTemplateCatalog,
  type AdminBankRuleTemplatePayload,
} from '@/api/admin'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const canWrite = computed(() => auth.canWrite('bank.rules'))
const catalog = ref<AdminBankRuleTemplateCatalog>({ templates: [], operation_types: [], posting_rules: [] })
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const formOpen = ref(false)
const page = ref(1)
const perPage = 25
let loadVersion = 0

const form = reactive<AdminBankRuleTemplatePayload>({
  template_key: '',
  name_cs: '',
  name_en: '',
  direction: 'outgoing',
  operation_type: 'bank.rule.custom',
  counterparty_bank: null,
  counterparty_prefix: null,
  vs_placeholder: null,
  message_contains: null,
  rule_key: '',
  default_priority: 100,
  sort_order: 0,
  is_active: true,
})

const sortedTemplates = computed(() => [...catalog.value.templates].sort((a, b) =>
  a.sort_order - b.sort_order || a.template_key.localeCompare(b.template_key),
))
const pagedTemplates = computed(() => sortedTemplates.value.slice((page.value - 1) * perPage, page.value * perPage))
const availablePostingRules = computed(() => catalog.value.posting_rules.filter(rule =>
  form.direction === 'incoming'
    ? String(rule.debit_account_code ?? '').startsWith('221')
    : String(rule.credit_account_code ?? '').startsWith('221'),
))
const selectedPostingRule = computed(() =>
  catalog.value.posting_rules.find(rule => rule.rule_key === form.rule_key) ?? null,
)
watch(() => form.direction, () => {
  if (!availablePostingRules.value.some(rule => rule.rule_key === form.rule_key)) form.rule_key = ''
})

// Klíč se u nové šablony předvyplní slugem českého názvu, dokud do něj uživatel nesáhne.
const keySlug = useAutoSlug(slug => { form.template_key = slug }, { maxLen: 64 })
function onNameCsInput(e: Event) { keySlug.fromName((e.target as HTMLInputElement).value) }
function onTemplateKeyInput(e: Event) { keySlug.markManual((e.target as HTMLInputElement).value) }

async function load() {
  const version = ++loadVersion
  loading.value = true
  error.value = ''
  try {
    const nextCatalog = await adminApi.listBankRuleTemplates()
    if (version !== loadVersion) return
    catalog.value = nextCatalog
    page.value = Math.min(page.value, Math.max(1, Math.ceil(catalog.value.templates.length / perPage)))
  } catch (e) {
    if (version === loadVersion) error.value = apiErrorMessage(e, t('bank_template_admin.load_error'))
  } finally {
    if (version === loadVersion) loading.value = false
  }
}

onMounted(load)
watch(() => supplierStore.currentSupplierId, () => {
  closeForm()
  page.value = 1
  catalog.value = { templates: [], operation_types: [], posting_rules: [] }
  void load()
})

function nextSortOrder(): number {
  return catalog.value.templates.reduce((max, item) => Math.max(max, item.sort_order), 0) + 10
}

function startNew() {
  editingId.value = null
  Object.assign(form, {
    template_key: '', name_cs: '', name_en: '', direction: 'outgoing',
    operation_type: 'bank.rule.custom', counterparty_bank: null, counterparty_prefix: null,
    vs_placeholder: null, message_contains: null, rule_key: '', default_priority: 100,
    sort_order: nextSortOrder(), is_active: true,
  })
  keySlug.init('', false)
  error.value = ''
  formOpen.value = true
}

function editTemplate(item: AdminBankRuleTemplate) {
  editingId.value = item.id
  Object.assign(form, {
    template_key: item.template_key,
    name_cs: item.name_cs,
    name_en: item.name_en,
    direction: item.direction,
    operation_type: item.operation_type,
    counterparty_bank: item.counterparty_bank,
    counterparty_prefix: item.counterparty_prefix,
    vs_placeholder: item.vs_placeholder,
    message_contains: item.message_contains,
    rule_key: item.rule_key,
    default_priority: item.default_priority,
    sort_order: item.sort_order,
    is_active: item.is_active,
  })
  keySlug.init(item.template_key, true)
  error.value = ''
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
  editingId.value = null
}

function nullable(value: string | null): string | null {
  const text = String(value ?? '').trim()
  return text === '' ? null : text
}

async function save() {
  error.value = ''
  const payload: AdminBankRuleTemplatePayload = {
    ...form,
    template_key: form.template_key.trim(),
    name_cs: form.name_cs.trim(),
    name_en: form.name_en.trim(),
    counterparty_bank: nullable(form.counterparty_bank),
    counterparty_prefix: nullable(form.counterparty_prefix),
    message_contains: nullable(form.message_contains),
    rule_key: form.rule_key.trim(),
    default_priority: Number(form.default_priority),
    sort_order: Number(form.sort_order),
  }
  saving.value = true
  try {
    if (editingId.value === null) await adminApi.createBankRuleTemplate(payload)
    else await adminApi.updateBankRuleTemplate(editingId.value, payload)
    toast.success(t('common.saved'))
    closeForm()
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('bank_template_admin.save_error'))
  } finally {
    saving.value = false
  }
}

async function remove(item: AdminBankRuleTemplate) {
  if (!confirm(t('bank_template_admin.delete_confirm', { name: item.name_cs }))) return
  error.value = ''
  try {
    await adminApi.deleteBankRuleTemplate(item.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('bank_template_admin.delete_error'))
  }
}

function directionLabel(direction: 'incoming' | 'outgoing'): string {
  return direction === 'incoming' ? t('bank.posting.dir_incoming') : t('bank.posting.dir_outgoing')
}

function criteria(item: AdminBankRuleTemplate): string {
  const parts = [
    item.counterparty_prefix ? t('bank.templates.criteria_prefix', { prefix: item.counterparty_prefix }) : '',
    item.counterparty_bank ? t('bank.templates.criteria_bank', { bank: item.counterparty_bank }) : '',
    item.vs_placeholder ?? '',
    item.message_contains ? t('bank.templates.criteria_message', { value: item.message_contains }) : '',
  ].filter(Boolean)
  return parts.join(', ')
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <!-- h1, ne h2: komponenta se od vyčlenění z nastavení používá jako
             SAMOSTATNÁ stránka, takže dřív neměla vůbec nadpis první úrovně —
             stránce chyběl display font, který ostatní tituly nesou, a čtečka
             obrazovky začínala rovnou druhou úrovní. -->
        <h1 class="text-2xl font-semibold">{{ t('bank_template_admin.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1">{{ t('bank_template_admin.subtitle') }}</p>
      </div>
      <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="startNew">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('bank_template_admin.new') }}
      </button>
    </div>

    <div v-if="error" class="mb-4 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="sortedTemplates.length === 0" boxed icon="copy"
      :title="t('bank_template_admin.empty')" :cta="canWrite ? t('bank_template_admin.new') : undefined" @action="startNew" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="hidden lg:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-600">
            <tr>
              <th class="px-3 py-3 text-left font-medium">{{ t('bank_template_admin.name') }}</th>
              <th class="px-3 py-3 text-left font-medium">{{ t('bank_template_admin.matching') }}</th>
              <th class="px-3 py-3 text-left font-medium">{{ t('bank_template_admin.posting') }}</th>
              <th class="px-3 py-3 text-center font-medium">{{ t('bank_template_admin.order') }}</th>
              <th class="px-3 py-3 text-center font-medium">{{ t('bank_template_admin.usage') }}</th>
              <th v-if="canWrite" class="px-3 py-3 text-right font-medium">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in pagedTemplates" :key="item.id" :class="{ 'opacity-55': !item.is_active }">
              <td class="px-3 py-3">
                <div class="font-medium text-neutral-800">{{ item.name_cs }}</div>
                <div class="font-mono text-xs text-neutral-500">{{ item.template_key }}</div>
                <span v-if="!item.is_active" class="inline-flex mt-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('bank_template_admin.inactive') }}</span>
              </td>
              <td class="px-3 py-3 max-w-sm">
                <div>{{ directionLabel(item.direction) }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">{{ criteria(item) }}</div>
              </td>
              <td class="px-3 py-3">
                <div class="font-mono text-xs">{{ item.rule_key }}</div>
                <div class="text-xs text-neutral-500">{{ item.debit_account_code || '—' }} / {{ item.credit_account_code || '—' }}</div>
              </td>
              <td class="px-3 py-3 text-center font-mono">{{ item.sort_order }}</td>
              <td class="px-3 py-3 text-center">{{ item.usage_count }}</td>
              <td v-if="canWrite" class="px-3 py-3">
                <div class="flex flex-wrap justify-end gap-2">
                  <button type="button" :class="btnOutlineSm('neutral')" @click="editTemplate(item)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </button>
                  <button type="button" :class="btnOutlineSm('danger')" :disabled="item.usage_count > 0" :title="item.usage_count > 0 ? t('bank_template_admin.in_use') : t('common.delete')" @click="remove(item)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="lg:hidden divide-y divide-neutral-100">
        <article v-for="item in pagedTemplates" :key="`mobile-${item.id}`" class="p-4 space-y-2" :class="{ 'opacity-55': !item.is_active }">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="font-medium text-neutral-800">{{ item.name_cs }}</div>
              <div class="font-mono text-xs text-neutral-500">{{ item.template_key }}</div>
            </div>
            <span class="text-xs rounded-full px-2 py-1" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">
              {{ item.is_active ? t('bank_template_admin.active') : t('bank_template_admin.inactive') }}
            </span>
          </div>
          <p class="text-sm text-neutral-600">{{ directionLabel(item.direction) }} · {{ criteria(item) }}</p>
          <p class="text-xs font-mono text-neutral-500">{{ item.rule_key }} · {{ item.debit_account_code || '—' }}/{{ item.credit_account_code || '—' }}</p>
          <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
            <span class="text-xs text-neutral-500">{{ t('bank_template_admin.usage_count', { count: item.usage_count }) }}</span>
            <div v-if="canWrite" class="flex flex-wrap gap-2">
              <button type="button" :class="btnOutlineSm('neutral')" @click="editTemplate(item)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button type="button" :class="btnOutlineSm('danger')" :disabled="item.usage_count > 0" @click="remove(item)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </article>
      </div>
      <PaginationBar embedded :page="page" :per-page="perPage" :total="sortedTemplates.length" @update:page="page = $event" />
    </div>

    <Modal v-if="formOpen"
      :title="editingId === null ? t('bank_template_admin.new') : t('bank_template_admin.edit')"
      widthClass="max-w-4xl" @close="closeForm">
      <div class="space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.name_cs') }}</label>
          <input v-model="form.name_cs" @input="onNameCsInput" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.name_en') }}</label>
          <input v-model="form.name_en" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.template_key') }}</label>
          <input v-model="form.template_key" @input="onTemplateKeyInput" type="text" maxlength="64" :disabled="editingId !== null" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
          <p v-if="editingId === null" class="mt-1 text-xs text-neutral-500">{{ t('bank_template_admin.template_key_hint') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.direction') }}</label>
          <select v-model="form.direction" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="incoming">{{ t('bank.posting.dir_incoming') }}</option>
            <option value="outgoing">{{ t('bank.posting.dir_outgoing') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.operation_type') }}</label>
          <select v-model="form.operation_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface font-mono">
            <option v-for="type in catalog.operation_types" :key="type" :value="type">{{ type }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.rule_key') }}</label>
          <select v-model="form.rule_key" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="" disabled>{{ t('bank_template_admin.select_posting') }}</option>
            <option v-for="rule in availablePostingRules" :key="rule.rule_key" :value="rule.rule_key">
              {{ rule.rule_key }} — {{ rule.debit_account_code || '—' }}/{{ rule.credit_account_code || '—' }} — {{ rule.description }}
            </option>
          </select>
          <p v-if="selectedPostingRule" class="mt-1 text-xs text-neutral-500">{{ selectedPostingRule.description }}</p>
        </div>
      </div>

      <fieldset class="border border-neutral-200 rounded-lg p-4">
        <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('bank_template_admin.criteria') }}</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm text-neutral-600 mb-1">{{ t('bank_template_admin.bank_code') }}</label>
            <input v-model="form.counterparty_bank" type="text" inputmode="numeric" maxlength="10" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm text-neutral-600 mb-1">{{ t('bank_template_admin.account_prefix') }}</label>
            <input v-model="form.counterparty_prefix" type="text" inputmode="numeric" maxlength="6" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm text-neutral-600 mb-1">{{ t('bank_template_admin.vs_placeholder') }}</label>
            <select v-model="form.vs_placeholder" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface font-mono">
              <option :value="null">{{ t('common.unset') }}</option>
              <option value="{cssz_vsdp}">{cssz_vsdp}</option>
              <option value="{health_insurance_number}">{health_insurance_number}</option>
              <option value="{dic_kmen}">{dic_kmen}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm text-neutral-600 mb-1">{{ t('bank_template_admin.message') }}</label>
            <input v-model="form.message_contains" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-500 mt-2">{{ t('bank_template_admin.criteria_hint') }}</p>
      </fieldset>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.priority') }}</label>
          <input v-model.number="form.default_priority" type="number" min="0" max="999" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_template_admin.order') }}</label>
          <input v-model.number="form.sort_order" type="number" min="0" max="65535" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <label class="flex items-center gap-2 text-sm mt-7">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('bank_template_admin.active') }}
        </label>
      </div>

      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" :class="btnOutline('neutral')" @click="closeForm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
      </div>
    </Modal>
  </div>
</template>
