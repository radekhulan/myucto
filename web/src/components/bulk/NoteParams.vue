<script setup lang="ts">
/**
 * Parametry hromadné změny poznámky faktur: pole (nad / pod položkami), způsob změny,
 * text a volba úpravy vystavených dokladů.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { NOTE_FIELDS, NOTE_MODES, NOTE_TEXT_MAX, noteFieldLabelKey, noteTextError, type BulkNoteForm } from './noteSymbolForm'

const model = defineModel<BulkNoteForm>({ required: true })
defineProps<{ disabled?: boolean }>()

const { t } = useI18n()
const textError = computed(() => {
  const err = noteTextError(model.value)
  if (err === 'too_long') return t('bulk_actions.note.text_too_long', { max: NOTE_TEXT_MAX })
  return err === 'required' && model.value.text !== '' ? t('bulk_actions.note.text_required') : null
})
</script>

<template>
  <div class="space-y-3" data-test="bulk-params-note">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <fieldset>
        <legend class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bulk_actions.note.field_label') }}</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-1">
          <label v-for="f in NOTE_FIELDS" :key="f" class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
            <input v-model="model.field" type="radio" :value="f" class="h-4 w-4" :disabled="disabled" :data-test="`bulk-note-field-${f}`" />
            {{ t(noteFieldLabelKey(f)) }}
          </label>
        </div>
      </fieldset>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-note-mode">{{ t('bulk_actions.note.mode_label') }}</label>
        <select id="bulk-note-mode" v-model="model.mode" :disabled="disabled" data-test="bulk-note-mode"
          class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface">
          <option v-for="m in NOTE_MODES" :key="m" :value="m">{{ t('bulk_actions.note.mode.' + m) }}</option>
        </select>
        <p class="mt-1 text-xs text-neutral-500">{{ t('bulk_actions.note.mode_hint.' + model.mode) }}</p>
      </div>
    </div>

    <div v-if="model.mode !== 'clear'">
      <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-note-text">{{ t('bulk_actions.note.text_label') }}</label>
      <textarea id="bulk-note-text" v-model="model.text" rows="3" :disabled="disabled" data-test="bulk-note-text"
        class="w-full px-3 py-2 border rounded-md bg-surface"
        :class="textError ? 'border-danger-500' : 'border-neutral-300'" :aria-invalid="textError ? 'true' : undefined"></textarea>
      <div class="mt-1 flex flex-wrap justify-between gap-2 text-xs">
        <span v-if="textError" class="text-danger-500" data-test="bulk-note-error">{{ textError }}</span>
        <span v-else></span>
        <span class="text-neutral-500 tabular-nums">{{ t('bulk_actions.note.text_counter', { n: model.text.trim().length, max: NOTE_TEXT_MAX }) }}</span>
      </div>
    </div>

    <label class="flex items-start gap-2 cursor-pointer">
      <input v-model="model.includeIssued" type="checkbox" class="mt-0.5 h-4 w-4" :disabled="disabled" data-test="bulk-note-include-issued" />
      <span>
        <span class="block">{{ t('bulk_actions.note.include_issued') }}</span>
        <span class="block text-xs text-neutral-500">{{ t('bulk_actions.note.include_issued_hint') }}</span>
      </span>
    </label>
  </div>
</template>
