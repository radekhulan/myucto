<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { assetsApi, type AssetDetail, type AssetRevisionPayload, type AssetRevisionPreview } from '@/api/assets'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatMonth } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import AssetRevisionPlanTable from './AssetRevisionPlanTable.vue'

const props = defineProps<{ asset: AssetDetail }>()
const emit = defineEmits<{ close: []; saved: []; refresh: [] }>()
const { t } = useI18n()
const toast = useToast()
const expectedRevision = props.asset.imported_depreciation_plan?.revisions?.length ?? 0
const form = reactive({ effective_from: '', remaining_months: null as number | null, reason: '' })
const preview = ref<AssetRevisionPreview | null>(null)
const previewPayload = ref<AssetRevisionPayload | null>(null)
const previewing = ref(false)
const saving = ref(false)
const error = ref('')
const stale = ref(false)
let request = 0
const valid = computed(() => /^\d{4}-(0[1-9]|1[0-2])$/.test(form.effective_from)
  && Number.isInteger(form.remaining_months) && (form.remaining_months ?? 0) >= 1 && (form.remaining_months ?? 0) <= 3000
  && form.reason.trim().length > 0 && form.reason.trim().length <= 2000)
watch(form, () => {
  request++
  preview.value = null
  previewPayload.value = null
  previewing.value = false
  error.value = ''
}, { flush: 'sync' })
watch(() => [props.asset, props.asset.imported_depreciation_plan?.revisions?.length], () => {
  request++
  preview.value = null
  previewPayload.value = null
  previewing.value = false
  saving.value = false
  stale.value = true
  error.value = t('accounting.assets.revision.stale')
}, { flush: 'sync' })
onBeforeUnmount(() => { request++ })

function failed(caught: unknown): void {
  const data = (caught as { response?: { data?: { error?: { code?: string; message?: string } } } } | null)?.response?.data?.error
  stale.value = data?.code === 'imported_plan_revision_stale'
  const key = stale.value ? 'stale' : data?.code === 'imported_plan_revision_locked' ? 'locked'
    : data?.code === 'imported_plan_revision_not_allowed' ? 'not_allowed' : ''
  error.value = key ? t(`accounting.assets.revision.${key}`) : data?.message || t('accounting.assets.revision.failed')
}

async function loadPreview(): Promise<void> {
  if (!valid.value || previewing.value || saving.value || stale.value) return
  const payload: AssetRevisionPayload = { effective_from: form.effective_from, remaining_months: form.remaining_months!, reason: form.reason.trim(), expected_revision: expectedRevision }
  const current = ++request
  previewing.value = true
  error.value = ''
  preview.value = null
  previewPayload.value = null
  try {
    const result = await assetsApi.previewRevision(props.asset.id, payload)
    if (current !== request) return
    preview.value = result
    previewPayload.value = payload
  } catch (caught) {
    if (current === request) failed(caught)
  } finally {
    if (current === request) previewing.value = false
  }
}

async function save(): Promise<void> {
  if (!preview.value || !previewPayload.value || saving.value || stale.value) return
  const payload = { ...previewPayload.value, expected_calendar_start_month: preview.value.calendar_start_month, expected_calendar_fingerprint: preview.value.calendar_fingerprint }
  const current = ++request
  saving.value = true
  error.value = ''
  try {
    const result = await assetsApi.revise(props.asset.id, payload)
    if (current !== request) return
    for (const warning of result.warnings ?? []) toast.warning(warning.message)
    toast.success(t('accounting.assets.revision.saved'))
    emit('saved')
  } catch (caught) {
    if (current !== request) return
    preview.value = null
    previewPayload.value = null
    failed(caught)
  } finally {
    if (current === request) saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('accounting.assets.revision.title')" width-class="max-w-6xl" @close="!saving && emit('close')">
    <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('accounting.assets.revision.hint') }}</p>
    <form data-testid="revision-form" class="space-y-4" @submit.prevent="loadPreview">
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium">{{ t('accounting.assets.revision.effective_from') }}
          <input v-model="form.effective_from" data-testid="revision-effective-from" type="month" required :disabled="saving || stale" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
        </label>
        <label class="block text-sm font-medium">{{ t('accounting.assets.revision.remaining_months') }}
          <input v-model.number="form.remaining_months" data-testid="revision-remaining-months" type="number" min="1" max="3000" step="1" required :disabled="saving || stale" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
        </label>
      </div>
      <label class="block text-sm font-medium">{{ t('accounting.assets.revision.reason') }}
        <textarea v-model="form.reason" data-testid="revision-reason" rows="3" maxlength="2000" required :disabled="saving || stale" class="mt-1 block w-full rounded-md border border-neutral-300 px-2 py-2 text-sm"></textarea>
      </label>
      <div class="flex flex-wrap gap-2">
        <button data-testid="revision-preview" type="submit" :class="btnOutline('primary')" :disabled="!valid || previewing || saving || stale"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t(previewing ? 'accounting.assets.revision.previewing' : 'accounting.assets.revision.preview') }}</button>
      </div>
    </form>
    <div v-if="preview" class="mt-5 space-y-4">
      <p class="text-sm font-medium">{{ t('accounting.assets.revision.preview_summary', { revision: preview.revision, month: formatMonth(preview.effective_from), months: preview.remaining_months, amount: formatMoney(preview.remaining_amount) }) }}</p>
      <div class="grid gap-5 lg:grid-cols-2">
        <section data-testid="revision-before"><h3 class="mb-2 font-semibold">{{ t('accounting.assets.revision.before') }}</h3><AssetRevisionPlanTable :plan="preview.before" /></section>
        <section data-testid="revision-after"><h3 class="mb-2 font-semibold">{{ t('accounting.assets.revision.after') }}</h3><AssetRevisionPlanTable :plan="preview.after" /></section>
      </div>
    </div>
    <p v-if="error" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</p>
    <div class="mt-5 flex flex-wrap justify-end gap-2">
      <button v-if="stale" data-testid="revision-refresh" type="button" :class="btnOutline('primary')" @click="emit('refresh')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('accounting.assets.revision.refresh') }}</button>
      <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="emit('close')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
      <button data-testid="revision-save" type="button" :class="btnFilled('warning')" :disabled="!preview || saving || stale" @click="save"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t(saving ? 'common.saving' : 'accounting.assets.revision.save') }}</button>
    </div>
  </Modal>
</template>
