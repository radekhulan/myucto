<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import { taxEvidenceApi, type CashJournalNote, type CashJournalRow } from '@/api/taxEvidence'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'

/**
 * Poznámky a přílohy k pohybu peněžního deníku. Poznámka visí na řádku deníku,
 * přílohy na dokladu pohybu (pokladní doklad, bankovní pohyb, úhrada nebo přijatá
 * faktura) existující vazbou dokument ↔ entita.
 */
const props = defineProps<{ row: CashJournalRow }>()
const emit = defineEmits<{ close: []; changed: [notes: CashJournalNote[]] }>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const canWrite = auth.canWrite('tax_evidence')

const notes = ref<CashJournalNote[]>([])
const loading = ref(true)
const busy = ref(false)
const draft = ref('')
const editingId = ref<number | null>(null)
const editBody = ref('')

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

async function run(action: () => Promise<CashJournalNote[]>) {
  busy.value = true
  try {
    notes.value = await action()
    emit('changed', notes.value)
    return true
  } catch (e) {
    toast.error(errorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    notes.value = await taxEvidenceApi.movementNotes(props.row.source_type, props.row.source_id)
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    loading.value = false
  }
})

async function add() {
  const body = draft.value.trim()
  if (!body) return
  if (await run(() => taxEvidenceApi.createMovementNote(props.row.source_type, props.row.source_id, body))) draft.value = ''
}

function startEdit(note: CashJournalNote) {
  editingId.value = note.id
  editBody.value = note.body
}

async function saveEdit(note: CashJournalNote) {
  const body = editBody.value.trim()
  if (!body) return
  if (await run(() => taxEvidenceApi.updateMovementNote(props.row.source_type, props.row.source_id, note.id, { body }))) editingId.value = null
}

function togglePin(note: CashJournalNote) {
  run(() => taxEvidenceApi.updateMovementNote(props.row.source_type, props.row.source_id, note.id, { pinned: !note.pinned }))
}

function remove(note: CashJournalNote) {
  if (!confirm(t('tax_evidence.cash_journal.note_delete_confirm'))) return
  run(() => taxEvidenceApi.deleteMovementNote(props.row.source_type, props.row.source_id, note.id))
}
</script>

<template>
  <Modal :title="t('tax_evidence.cash_journal.notes_title')" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-4" data-test="cash-journal-movement">
      <p class="text-xs text-neutral-500">
        {{ t('tax_evidence.cash_journal.notes_hint', { date: formatDate(row.date), doc: row.doc_no || '—' }) }}
      </p>

      <section>
        <h3 class="text-sm font-semibold mb-2">{{ t('tax_evidence.cash_journal.notes_section') }}</h3>
        <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
        <p v-else-if="notes.length === 0" class="text-sm text-neutral-500">{{ t('tax_evidence.cash_journal.notes_empty') }}</p>
        <ul v-else class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="note in notes" :key="note.id" class="px-3 py-2 space-y-1" data-test="cash-journal-note">
            <template v-if="editingId === note.id">
              <textarea v-model="editBody" rows="3" maxlength="5000" class="w-full px-2 py-1 border border-neutral-300 rounded-md text-sm" />
              <div class="flex flex-wrap justify-end gap-2">
                <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="editingId = null">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  {{ t('common.cancel') }}
                </button>
                <button type="button" :disabled="busy || !editBody.trim()" :class="btnOutlineSm('success')" class="whitespace-nowrap" @click="saveEdit(note)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('common.save') }}
                </button>
              </div>
            </template>
            <template v-else>
              <p class="text-sm whitespace-pre-wrap break-words">
                <span v-if="note.pinned" class="mr-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700">{{ t('tax_evidence.cash_journal.note_pinned') }}</span>{{ note.body }}
              </p>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs text-neutral-500">{{ note.created_by_name || '—' }} · {{ formatDate(note.created_at) }}</span>
                <div v-if="canWrite" class="flex flex-wrap gap-1">
                  <button type="button" :disabled="busy" :class="btnOutlineSm('neutral')" :title="note.pinned ? t('tax_evidence.cash_journal.note_unpin') : t('tax_evidence.cash_journal.note_pin')" :aria-label="note.pinned ? t('tax_evidence.cash_journal.note_unpin') : t('tax_evidence.cash_journal.note_pin')" @click="togglePin(note)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pin" /></svg>
                  </button>
                  <button type="button" :disabled="busy" :class="btnOutlineSm('neutral')" :title="t('common.edit')" :aria-label="t('common.edit')" @click="startEdit(note)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button type="button" :disabled="busy" :class="btnOutlineSm('danger')" :title="t('common.delete')" :aria-label="t('common.delete')" @click="remove(note)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </div>
            </template>
          </li>
        </ul>
        <div v-if="canWrite" class="mt-3 space-y-2">
          <textarea v-model="draft" rows="3" maxlength="5000" :placeholder="t('tax_evidence.cash_journal.note_placeholder')"
                    class="w-full px-2 py-1 border border-neutral-300 rounded-md text-sm" data-test="cash-journal-note-input" />
          <div class="flex flex-wrap justify-end">
            <button type="button" :disabled="busy || !draft.trim()" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="cash-journal-note-add" @click="add">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('tax_evidence.cash_journal.note_add') }}
            </button>
          </div>
        </div>
      </section>

      <LinkedDocumentsPanel v-if="row.attachment_entity && auth.canRead('documents')" :entity-type="row.attachment_entity" :entity-id="row.source_id"
                            uploadable :readonly="!canWrite" :title="t('tax_evidence.cash_journal.attachments_title')" />
      <p v-else-if="!row.attachment_entity" class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.attachments_unavailable') }}</p>
    </div>
  </Modal>
</template>
