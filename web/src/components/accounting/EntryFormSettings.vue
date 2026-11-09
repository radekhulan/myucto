<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type AccountingPeriod, type EntryForm, type EntryFormState } from '@/api/accounting'
import { apiErrorText } from '@/utils/entryForm'
import { appIsoDate } from '@/utils/date'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import Modal from '@/components/ui/Modal.vue'
import PairConversionPreview from '@/components/accounting/PairConversionPreview.vue'

/**
 * Nastavení Formy účetního zápisu firmy: složený (dosavadní) nebo jednoduchý
 * (po dvojicích) od zvoleného data, s historií změn a náhledem převodu starších
 * zápisů nanečisto. Změnu smí jen admin firmy (`canChange`), ostatním je karta
 * jen ke čtení. Změna formy jde do auditního logu na serveru.
 */
defineProps<{
  /** Smí uživatel formu měnit (admin firmy). */
  canChange: boolean
}>()

const emit = defineEmits<{ changed: [state: EntryFormState] }>()

const { t } = useI18n()
const toast = useToast()

const state = ref<EntryFormState | null>(null)
const loading = ref(true)
const loadError = ref('')
const periods = ref<AccountingPeriod[]>([])

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    state.value = await accountingApi.getEntryForm()
  } catch (e) {
    state.value = null
    loadError.value = apiErrorText(e, t)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const current = computed<EntryForm>(() => state.value?.entry_form ?? 'composite')
const history = computed(() => [...(state.value?.history ?? [])].sort((a, b) => b.valid_from.localeCompare(a.valid_from)))
/** Naplánovaná změna v budoucnosti (valid_from po dnešku): ukáže se zvlášť, dnešní forma se nemění. */
const upcoming = computed(() => history.value.find(h => h.valid_from > appIsoDate()) ?? null)

// ── Dialog změny formy ──────────────────────────────────────────────────────
const dialog = reactive({ open: false, saving: false, error: '' })
const form = reactive({
  entry_form: 'single' as EntryForm,
  valid_from: '',
  note: '',
})
const previewId = ref<number | null>(null)

/** Doporučené datum: první den otevřeného období, ve kterém je dnešek (jinak nejstaršího otevřeného). */
function suggestedDate(): string {
  const today = appIsoDate()
  const open = periods.value.filter(p => p.status === 'open').sort((a, b) => a.starts_on.localeCompare(b.starts_on))
  const covering = open.find(p => p.starts_on <= today && p.ends_on >= today)
  return (covering ?? open[open.length - 1])?.starts_on ?? today
}

async function openDialog() {
  dialog.error = ''
  dialog.saving = false
  previewId.value = null
  form.entry_form = current.value === 'single' ? 'composite' : 'single'
  form.note = ''
  if (periods.value.length === 0) {
    try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  }
  form.valid_from = suggestedDate()
  dialog.open = true
}

function onFormChange() {
  previewId.value = null
}

const needsPreview = computed(() => form.entry_form === 'single')
const canSave = computed(() => !!form.valid_from && !dialog.saving)

async function submit() {
  dialog.error = ''
  dialog.saving = true
  try {
    await accountingApi.scheduleEntryForm({
      valid_from: form.valid_from,
      entry_form: form.entry_form,
      ...(previewId.value ? { conversion_preview_id: previewId.value } : {}),
      ...(form.note.trim() ? { note: form.note.trim() } : {}),
    })
    toast.success(t('accounting.pairs.settings.saved'))
    dialog.open = false
    await load()
    if (state.value) emit('changed', state.value)
  } catch (e) {
    dialog.error = apiErrorText(e, t)
  } finally {
    dialog.saving = false
  }
}

function formLabel(f: string): string {
  return t(`accounting.pairs.settings.form_${f}`)
}
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" data-test="entry-form-settings">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <h2 class="text-base font-semibold">{{ t('accounting.pairs.settings.title') }}</h2>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.pairs.settings.hint') }}</p>
      </div>
      <button v-if="canChange && state" type="button" :class="btnOutline('warning')" data-test="entry-form-change" @click="openDialog">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
        <span class="whitespace-nowrap">{{ t('accounting.pairs.settings.change') }}</span>
      </button>
    </div>

    <div v-if="loading" class="text-sm text-neutral-500 py-3">{{ t('common.loading') }}</div>
    <div v-else-if="loadError" class="text-sm text-danger-500 py-3">{{ loadError }}</div>
    <template v-else-if="state">
      <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-neutral-600">{{ t('accounting.pairs.settings.current') }}:</span>
        <span class="px-2 py-0.5 rounded text-xs font-medium"
          :class="current === 'single' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'"
          data-test="entry-form-current">{{ formLabel(current) }}</span>
        <span v-if="upcoming" class="text-xs text-warning-600">
          {{ t('accounting.pairs.settings.upcoming', { form: formLabel(upcoming.entry_form), date: formatDate(upcoming.valid_from) }) }}
        </span>
      </div>

      <div v-if="history.length" class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="py-1.5 pr-3 text-left font-medium">{{ t('accounting.pairs.settings.valid_from') }}</th>
              <th class="py-1.5 pr-3 text-left font-medium">{{ t('accounting.pairs.settings.form') }}</th>
              <th class="py-1.5 pr-3 text-left font-medium">{{ t('accounting.pairs.settings.changed_at') }}</th>
              <th class="py-1.5 text-left font-medium">{{ t('accounting.pairs.settings.note') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in history" :key="h.valid_from" class="border-t border-neutral-100">
              <td class="py-1.5 pr-3 whitespace-nowrap">{{ formatDate(h.valid_from) }}</td>
              <td class="py-1.5 pr-3">{{ formLabel(h.entry_form) }}</td>
              <td class="py-1.5 pr-3 whitespace-nowrap text-xs text-neutral-500">{{ formatDateTime(h.created_at) }}</td>
              <td class="py-1.5 text-neutral-600">{{ h.note || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="mt-2 text-xs text-neutral-500">{{ t('accounting.pairs.settings.no_history') }}</p>
    </template>

    <Modal v-if="dialog.open" :title="t('accounting.pairs.settings.dialog_title')" width-class="max-w-2xl" @close="dialog.open = false">
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.settings.form') }}</label>
            <select v-model="form.entry_form" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
              data-test="entry-form-select" @change="onFormChange">
              <option value="single">{{ t('accounting.pairs.settings.form_single') }}</option>
              <option value="composite">{{ t('accounting.pairs.settings.form_composite') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.settings.valid_from') }}</label>
            <DateInput v-model="form.valid_from" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" @update:model-value="onFormChange" />
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ t('accounting.pairs.settings.valid_from_hint') }}</p>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.settings.note') }}</label>
          <input v-model="form.note" type="text" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>

        <PairConversionPreview v-if="needsPreview" :date-from="form.valid_from" :date-to="appIsoDate()"
          @ready="id => previewId = id" @reset="previewId = null" />
        <p v-if="needsPreview && !previewId" class="text-xs text-warning-600">{{ t('accounting.pairs.settings.preview_recommended') }}</p>
        <p v-if="form.entry_form === 'composite'" class="text-xs text-neutral-500">{{ t('accounting.pairs.settings.back_to_composite') }}</p>

        <div v-if="dialog.error" class="text-sm text-danger-500" role="alert" data-test="entry-form-error">{{ dialog.error }}</div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="dialog.open = false">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="!canSave" data-test="entry-form-save" @click="submit">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ dialog.saving ? t('common.saving') : t('accounting.pairs.settings.save') }}
          </button>
        </div>
      </template>
    </Modal>
  </section>
</template>
