<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type Warehouse, type WarehousePayload, type WarehouseLocation } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const warehouses = ref<Warehouse[]>([])
const loading = ref(false)
const expandedWarehouseId = ref<number | null>(null)
const locations = ref<WarehouseLocation[]>([])
const locationsLoading = ref(false)
const locationsError = ref('')
const locationSearch = ref('')
const locationStatus = ref<'all' | 'active' | 'inactive'>('all')
const locationPage = ref(1)
const locationsPerPage = 20
const normalizeSearch = (value: string) => value.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase()
const filteredLocations = computed(() => {
  const terms = normalizeSearch(locationSearch.value.trim()).split(/\s+/).filter(Boolean)
  return locations.value.filter(location => {
    if (locationStatus.value !== 'all' && location.is_active !== (locationStatus.value === 'active')) return false
    const text = normalizeSearch(location.code + ' ' + location.name)
    return terms.every(term => text.includes(term))
  })
})
const visibleLocations = computed(() => filteredLocations.value.slice(
  (locationPage.value - 1) * locationsPerPage,
  locationPage.value * locationsPerPage,
))
watch([locationSearch, locationStatus], () => { locationPage.value = 1 })
watch(() => filteredLocations.value.length, count => {
  locationPage.value = Math.min(locationPage.value, Math.max(1, Math.ceil(count / locationsPerPage)))
})

async function load() {
  loading.value = true
  try {
    warehouses.value = await stockApi.listWarehouses()
    if (!warehouses.value.some(warehouse => warehouse.id === expandedWarehouseId.value)) {
      expandedWarehouseId.value = null
    }
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)

let locationRequest = 0
async function loadLocations(warehouseId: number) {
  const request = ++locationRequest
  locationsLoading.value = true
  locationsError.value = ''
  try {
    const rows = await stockApi.listLocations(warehouseId)
    if (request === locationRequest) locations.value = rows
  } catch (e: any) {
    if (request === locationRequest) locationsError.value = mapError(e)
  } finally {
    if (request === locationRequest) locationsLoading.value = false
  }
}

function toggleLocations(warehouse: Warehouse) {
  if (expandedWarehouseId.value === warehouse.id) {
    expandedWarehouseId.value = null
    locationRequest++
    return
  }
  expandedWarehouseId.value = warehouse.id
  locations.value = []
  locationPage.value = 1
  locationSearch.value = ''
  locationStatus.value = 'all'
  void loadLocations(warehouse.id)
}

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
const form = ref<WarehousePayload>({ code: '', name: '', is_default: false, is_active: true, is_sellable: true, note: null })
// Kód skladu backend drží do 20 znaků a unikátní v rámci firmy.
const takenWarehouseCodes = computed(() => warehouses.value.filter(w => w.id !== editing.value?.id).map(w => w.code))

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', is_default: warehouses.value.length === 0, is_active: true, is_sellable: true, note: null }
  error.value = ''
  modalOpen.value = true
}
function openEdit(w: Warehouse) {
  editing.value = w
  form.value = { code: w.code, name: w.name, is_default: w.is_default, is_active: w.is_active, is_sellable: w.is_sellable, note: w.note }
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

const locationOpen = ref(false)
const locationEditing = ref<WarehouseLocation | null>(null)
const savingLocation = ref(false)
const locationForm = ref({ warehouse_id: 0, code: '', name: '', is_active: true })
// Kód lokace je unikátní v rámci skladu (backend do 50 znaků).
const takenLocationCodes = computed(() => locations.value
  .filter(l => l.warehouse_id === locationForm.value.warehouse_id && l.id !== locationEditing.value?.id)
  .map(l => l.code))
function openLocation(warehouse: Warehouse, location: WarehouseLocation | null = null) {
  locationEditing.value = location
  locationForm.value = { warehouse_id: warehouse.id, code: location?.code ?? '', name: location?.name ?? '', is_active: location?.is_active ?? true }
  locationOpen.value = true
}
async function saveLocation() {
  if (!locationForm.value.code.trim() || !locationForm.value.name.trim()) return
  const payload = locationForm.value
  savingLocation.value = true
  try {
    if (locationEditing.value) await stockApi.updateLocation(locationEditing.value.id, payload)
    else await stockApi.createLocation(payload)
    locationOpen.value = false
    if (expandedWarehouseId.value === payload.warehouse_id) await loadLocations(payload.warehouse_id)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    savingLocation.value = false
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

    <ul v-else class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <li v-for="warehouse in warehouses" :key="warehouse.id">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-4 py-3">
          <div class="min-w-0 flex-1 basis-52">
            <div class="flex flex-wrap items-center gap-2">
              <span class="min-w-0 break-words text-sm font-semibold">{{ warehouse.name }}</span>
              <span v-if="warehouse.is_default" class="rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700">{{ t('stock.warehouses.default_badge') }}</span>
              <span v-if="!warehouse.is_active" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ t('stock.locations.inactive') }}</span>
            </div>
            <div class="mt-0.5 break-all font-mono text-xs text-neutral-500">{{ warehouse.code }}</div>
          </div>
          <div class="text-sm">
            <span class="text-xs text-neutral-500">{{ t('stock.warehouses.col_value') }}</span>
            <div class="font-medium tabular-nums">{{ formatMoney(Number(warehouse.value)) }}</div>
          </div>
          <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" @click="toggleLocations(warehouse)" :class="btnOutline('neutral')"
              :aria-expanded="expandedWarehouseId === warehouse.id">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pin" /></svg>
              {{ t('stock.locations.title') }}
              <svg class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': expandedWarehouseId === warehouse.id }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
            </button>
            <template v-if="auth.canWrite('stock.items.write')">
              <button type="button" @click="openEdit(warehouse)" :title="t('common.edit')" :aria-label="t('common.edit')" :class="btnOutline('neutral')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              </button>
              <button type="button" @click="remove(warehouse)" :title="t('common.delete')" :aria-label="t('common.delete')" :class="btnOutline('danger')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </template>
          </div>
        </div>

        <section v-if="expandedWarehouseId === warehouse.id" :aria-label="t('stock.locations.for_warehouse', { warehouse: warehouse.name })"
          class="border-t border-neutral-200 bg-neutral-50 px-4 py-4">
          <div class="mb-3 flex flex-wrap items-center gap-2">
            <input v-model="locationSearch" type="search" :aria-label="t('stock.locations.search')" :placeholder="t('stock.locations.search')"
              class="h-9 min-w-0 grow rounded-md border border-neutral-300 bg-surface px-3 text-sm sm:max-w-xs" />
            <select v-model="locationStatus" :aria-label="t('stock.locations.status')" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              <option value="all">{{ t('stock.locations.all') }}</option>
              <option value="active">{{ t('stock.warehouses.col_active') }}</option>
              <option value="inactive">{{ t('stock.locations.inactive') }}</option>
            </select>
            <button v-if="auth.canWrite('stock.items.write')" type="button" @click="openLocation(warehouse)" :class="btnOutline('primary')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('stock.locations.new') }}
            </button>
          </div>
          <p v-if="locationsLoading" class="py-3 text-sm text-neutral-500" role="status">{{ t('common.loading') }}</p>
          <div v-else-if="locationsError" class="flex flex-wrap items-center gap-2" role="alert">
            <span class="text-sm text-danger-600">{{ locationsError }}</span>
            <button type="button" @click="loadLocations(warehouse.id)" :class="btnOutline('neutral')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ t('common.refresh') }}
            </button>
          </div>
          <p v-else-if="filteredLocations.length === 0" class="py-3 text-sm text-neutral-500" role="status">
            {{ t(locations.length ? 'stock.locations.no_matches' : 'stock.locations.empty') }}
          </p>
          <div v-else class="overflow-hidden rounded-md border border-neutral-200 bg-surface">
            <ul class="divide-y divide-neutral-100">
              <li v-for="location in visibleLocations" :key="location.id" class="flex items-center gap-3 px-3 py-2 text-sm">
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <span class="break-all font-mono text-xs font-medium text-neutral-700">{{ location.code }}</span>
                    <span v-if="location.name.trim() !== location.code.trim()" class="break-words">{{ location.name }}</span>
                    <span v-if="!location.is_active" class="text-xs text-neutral-500">{{ t('stock.locations.inactive') }}</span>
                  </div>
                </div>
                <button v-if="auth.canWrite('stock.items.write')" type="button" @click="openLocation(warehouse, location)"
                  :title="t('stock.locations.edit')" :aria-label="t('stock.locations.edit_named', { code: location.code })" :class="btnOutline('neutral')">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
              </li>
            </ul>
            <PaginationBar v-model:page="locationPage" embedded :per-page="locationsPerPage" :total="filteredLocations.length" />
          </div>
        </section>
      </li>
    </ul>

    <Modal v-if="modalOpen" :title="editing ? t('stock.warehouses.edit') : t('stock.warehouses.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <!-- Název je to, co uživatel opravdu vymýšlí; kód je zkratka odvozená z něj,
             proto stojí až za ním (stejné pořadí jako v ostatních číselnících). -->
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('stock.warehouses.field_code')"
          :name-label="t('stock.warehouses.field_name')"
          :editing="!!editing"
          code-mode="code"
          :code-maxlength="20"
          :name-maxlength="100"
          :taken-codes="takenWarehouseCodes"
        />
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
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_sellable" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('stock.warehouses.field_sellable') }}
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

    <Modal v-if="locationOpen" :title="locationEditing ? t('stock.locations.edit') : t('stock.locations.new')" widthClass="max-w-md" @close="locationOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="locationForm.code"
          v-model:name="locationForm.name"
          :code-label="t('stock.locations.code')"
          :name-label="t('stock.locations.name')"
          :editing="!!locationEditing"
          code-mode="code"
          :code-maxlength="50"
          :name-maxlength="100"
          :taken-codes="takenLocationCodes"
        />
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="locationForm.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />{{ t('stock.warehouses.field_active') }}</label>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button type="button" @click="locationOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button type="button" @click="saveLocation" :disabled="savingLocation" :class="btnFilled('primary')"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
