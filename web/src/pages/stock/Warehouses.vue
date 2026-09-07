<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type Warehouse, type WarehousePayload } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    warehouses.value = await stockApi.listWarehouses()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function mapError(e: any): string {
  const code = e?.response?.data?.error?.code
  if (code) {
    const key = code.startsWith('stock.error.') ? code : `stock.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  return e?.response?.data?.error?.message || t('common.error')
}

// ── Modal: založit / upravit ────────────────────────────────────────────
const modalOpen = ref(false)
const editing = ref<Warehouse | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<WarehousePayload>({ code: '', name: '', is_default: false, is_active: true, note: null })

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', is_default: warehouses.value.length === 0, is_active: true, note: null }
  error.value = ''
  modalOpen.value = true
}
function openEdit(w: Warehouse) {
  editing.value = w
  form.value = { code: w.code, name: w.name, is_default: w.is_default, is_active: w.is_active, note: w.note }
  error.value = ''
  modalOpen.value = true
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('stock.warehouses.field_code') + ' / ' + t('stock.warehouses.field_name')
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await stockApi.updateWarehouse(editing.value.id, form.value)
    } else {
      await stockApi.createWarehouse(form.value)
    }
    toast.success(t('common.saved'))
    modalOpen.value = false
    await load()
  } catch (e: any) {
    error.value = mapError(e)
  } finally {
    saving.value = false
  }
}

async function remove(w: Warehouse) {
  if (!confirm(t('stock.warehouses.delete_confirm', { name: w.name }))) return
  try {
    await stockApi.deleteWarehouse(w.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'warehouse_in_use' || code === 'stock.error.warehouse_in_use') {
      toast.warning(t('stock.warehouses.in_use_hint'))
    } else {
      toast.error(mapError(e))
    }
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('stock.warehouses.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.warehouses.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('stock.items.write')" @click="openCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('stock.warehouses.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="warehouses.length === 0" boxed icon="stock_warehouses"
      :title="t('stock.warehouses.empty_title')"
      :message="t('stock.warehouses.empty_hint')"
      :cta="auth.canWrite('stock.items.write') ? t('stock.warehouses.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('stock.warehouses.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('stock.warehouses.col_name') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('stock.warehouses.col_value') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('stock.warehouses.col_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('stock.warehouses.col_active') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="w in warehouses" :key="w.id" :class="{ 'opacity-50': !w.is_active }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ w.code }}</td>
              <td class="px-3 py-2">
                {{ w.name }}
                <span v-if="w.is_default" class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 font-medium">{{ t('stock.warehouses.default_badge') }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(w.value)) }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="w.is_default ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ w.is_default ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="w.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ w.is_active ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap" v-if="auth.canWrite('stock.items.write')">
                <button type="button" @click="openEdit(w)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <button type="button" @click="remove(w)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
              <td v-else></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="modalOpen" :title="editing ? t('stock.warehouses.edit') : t('stock.warehouses.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <!-- Název je to, co uživatel opravdu vymýšlí; kód je zkratka odvozená z něj,
             proto stojí až za ním (stejné pořadí jako v ostatních číselnících). -->
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.warehouses.field_name') }}</label>
          <input v-model="form.name" type="text" maxlength="100" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.warehouses.field_code') }}</label>
          <input v-model="form.code" type="text" maxlength="20" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.warehouses.field_note') }}</label>
          <textarea v-model="form.note" rows="2" class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm"></textarea>
        </div>
        <div class="flex items-center gap-4">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('stock.warehouses.field_default') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('stock.warehouses.field_active') }}
          </label>
        </div>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex justify-end gap-2 pt-2 border-t border-neutral-100">
          <button @click="modalOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button @click="save" :disabled="saving" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
