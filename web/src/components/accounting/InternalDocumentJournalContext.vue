<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import type { JournalEntryDetail } from '@/api/accounting'
import { internalDocumentsApi } from '@/api/internalDocuments'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Vazba zápisu deníku na interní doklad: odkaz na doklad, nebo u ručního zápisu
 * bez dokladu akce „Vytvořit interní doklad" (jen hlavička nad existujícím zápisem).
 */
const props = defineProps<{ detail: JournalEntryDetail }>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const busy = ref(false)

const linked = computed(() => props.detail.internal_document ?? null)
const canCreate = computed(() => !linked.value && auth.canWrite('accounting.journal.post')
  && props.detail.source_type === 'manual' && props.detail.source_id === null && !!props.detail.posted_at
  && !props.detail.reversed_by && !props.detail.reverses_entry_id)

async function create() {
  if (busy.value || blockDemoMutation() || !window.confirm(t('internal_documents.confirm_from_entry'))) return
  busy.value = true
  try {
    const doc = await internalDocumentsApi.fromEntry(props.detail.id)
    toast.success(t('internal_documents.created_from_entry', { number: doc.document_no || '' }))
    await router.push({ name: 'accounting-internal-document-detail', params: { id: doc.id } })
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section v-if="linked" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3 text-sm" data-test="internal-document-context">
    <p>
      {{ t(linked.role === 'reversal' ? 'internal_documents.journal_reversal_of' : 'internal_documents.journal_posting_of') }}
      <strong class="font-mono">{{ linked.document_no || t('internal_documents.no_number') }}</strong>
    </p>
    <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: linked.id } }" :class="btnOutline('primary')">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
      <span class="whitespace-nowrap">{{ t('internal_documents.open_document') }}</span>
    </RouterLink>
  </section>
  <div v-else-if="canCreate" class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm" data-test="internal-document-create">
    <p class="text-neutral-500">{{ t('internal_documents.from_entry_hint') }}</p>
    <button type="button" :disabled="busy" :class="btnOutline('primary')" @click="create">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
      <span class="whitespace-nowrap">{{ t('internal_documents.from_entry') }}</span>
    </button>
  </div>
</template>
