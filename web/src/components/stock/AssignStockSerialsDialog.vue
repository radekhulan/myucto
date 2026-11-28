<script setup lang="ts">
import { isAxiosError } from 'axios'
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { stockApi, type SerialAssignmentStock, type WarehouseLocation } from '@/api/stock'
import DialogActions, { type DialogAction } from '@/components/ui/DialogActions.vue'
import Modal from '@/components/ui/Modal.vue'

const props = defineProps<{ itemId: number; warehouses: SerialAssignmentStock[] }>()
const emit = defineEmits<{ close: []; assigned: [count: number] }>()
const { t } = useI18n()
const serialInput = ref<HTMLTextAreaElement | null>(null)
const serialHintId = useId()
const maxSerialsPerAssignment = 1000
const warehouseId = ref(props.warehouses[0]?.warehouse_id ?? null)
const locationId = ref<number | null>(null)
const locations = ref<WarehouseLocation[]>([])
const locationsLoading = ref(false)
const locationsError = ref('')
const serialText = ref('')
const busy = ref(false)
const error = ref('')

const warehouse = computed(() => props.warehouses.find(row => row.warehouse_id === warehouseId.value))
const serialNumbers = computed(() => serialText.value.split(/\r\n?|\n/).map(line => line.trim()).filter(Boolean))
const exceedsBatchSize = computed(() => serialNumbers.value.length > maxSerialsPerAssignment)
const tooMany = computed(() => exceedsBatchSize.value || serialNumbers.value.length > (warehouse.value?.quantity ?? 0))
const canSubmit = computed(() => !!warehouse.value && serialNumbers.value.length > 0 && !tooMany.value && !busy.value)

watch(warehouseId, async (id, _, onCleanup) => {
  locationId.value = null
  locations.value = []
  locationsError.value = ''
  locationsLoading.value = id != null
  if (id == null) return
  let active = true
  onCleanup(() => { active = false })
  try {
    const result = await stockApi.listLocations(id)
    if (active) locations.value = result.filter(location => location.warehouse_id === id && location.is_active)
  } catch (e) {
    if (active) locationsError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (active) locationsLoading.value = false
  }
}, { immediate: true })

onMounted(() => { void nextTick(() => serialInput.value?.focus()) })

function close() {
  if (!busy.value) emit('close')
}

async function submit() {
  if (!canSubmit.value || warehouseId.value == null) return
  busy.value = true
  error.value = ''
  try {
    const result = await stockApi.assignSerialNumbers(props.itemId, {
      warehouse_id: warehouseId.value,
      location_id: locationId.value,
      serial_numbers: serialNumbers.value,
    })
    emit('assigned', result.assigned)
  } catch (e) {
    const message = apiErrorMessage(e, t('common.error'))
    const serial = isAxiosError<{ error?: { serial_number?: unknown } }>(e) ? e.response?.data?.error?.serial_number : null
    error.value = typeof serial === 'string' && !message.includes(serial) ? `${message} (${serial})` : message
  } finally {
    busy.value = false
  }
}

const actions = computed<DialogAction[]>(() => [
  { key: 'cancel', label: t('common.cancel'), icon: 'x', variant: 'neutral', disabled: busy.value, run: close },
  {
    key: 'assign-serials', label: t('stock.tracking.serial_assignment.action'), icon: 'check', tier: 'primary',
    variant: 'primary', loading: busy.value, disabled: !canSubmit.value, run: submit,
  },
])
</script>

<template>
  <Modal :title="t('stock.tracking.serial_assignment.action')" width-class="max-w-lg" @close="close">
    <div class="space-y-4">
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium">
          {{ t('stock.item_detail.col_warehouse') }}
          <select v-model="warehouseId" :disabled="busy" data-test="serial-assignment-warehouse"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-2 font-normal">
            <option v-for="row in warehouses" :key="row.warehouse_id" :value="row.warehouse_id">{{ row.warehouse_code }}</option>
          </select>
        </label>
        <label class="block text-sm font-medium">
          {{ t('stock.tracking.serial_assignment.location') }}
          <select v-model="locationId" :disabled="busy || locationsLoading" data-test="serial-assignment-location"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-2 font-normal">
            <option :value="null">{{ t(locationsLoading ? 'common.loading' : 'stock.tracking.no_location') }}</option>
            <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.code }} · {{ location.name }}</option>
          </select>
        </label>
      </div>
      <p v-if="locationsError" class="text-sm text-warning-700" role="status">{{ t('stock.tracking.serial_assignment.locations_error', { message: locationsError }) }}</p>
      <label class="block text-sm font-medium">
        {{ t('stock.tracking.mode_serial') }}
        <span :id="serialHintId" class="mt-1 block text-sm font-normal text-neutral-600">{{ t('stock.tracking.serial_assignment.hint') }}</span>
        <textarea ref="serialInput" v-model="serialText" :aria-describedby="serialHintId" :aria-invalid="tooMany || undefined"
          :disabled="busy" rows="6" autocomplete="off" autocapitalize="off" :spellcheck="false"
          class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 font-mono font-normal" />
      </label>
      <p v-if="warehouse" class="text-sm" :class="tooMany ? 'text-danger-700' : 'text-neutral-600'">
        {{ t('stock.tracking.serial_assignment.count', { count: serialNumbers.length, available: warehouse.quantity }) }}
      </p>
      <p v-if="exceedsBatchSize" class="text-sm text-danger-700" role="alert">
        {{ t('stock.tracking.serial_assignment.batch_limit', { count: maxSerialsPerAssignment }) }}
      </p>
      <p v-if="error" class="text-sm text-danger-700" role="alert">{{ error }}</p>
      <DialogActions :actions="actions" />
    </div>
  </Modal>
</template>
