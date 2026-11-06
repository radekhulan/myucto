<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { DphCrossCheckDocument } from '@/api/reports'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useToast } from '@/composables/useToast'
import { cashDocumentLink, journalEntryLink, journalSourceLink } from '@/utils/journalSourceLink'

const props = defineProps<{ document: DphCrossCheckDocument }>()
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const navigation = useWorkspaceNavigation()
const toast = useToast()

const label = computed(() => props.document.doc_number ?? `#${props.document.invoice_id}`)
const target = computed<RouteLocationRaw | null>(() => {
  const doc = props.document
  if (!Number.isSafeInteger(doc.invoice_id) || doc.invoice_id <= 0) return null
  // Starší kontroly KH/SH nesou jen source, kontrola 343 rozlišuje i typ dokladu.
  const type = doc.doc_type ?? ({ sale: 'invoice', purchase: 'purchase_invoice', cash: 'cash', manual: 'journal_entry' } as Record<string, string>)[doc.source]
  if (type === 'invoice') {
    return auth.canRead('invoices') ? journalSourceLink({ source_type: type, source_id: doc.invoice_id }) : null
  }
  if (type === 'purchase_invoice') {
    return auth.canRead('purchase_invoices') ? journalSourceLink({ source_type: type, source_id: doc.invoice_id }) : null
  }
  if (type === 'cash') return auth.canRead('cash') ? cashDocumentLink(doc.invoice_id) : null
  if (type === 'journal_entry') return auth.canRead('accounting') ? journalEntryLink(doc.invoice_id) : null
  return null
})

async function open(event: MouseEvent) {
  if (!target.value || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  try {
    await navigation.openBeside(target.value)
  } catch {
    toast.error(t('common.error'))
  }
}
</script>

<template>
  <a v-if="target" :href="router.resolve(target).href" :title="t('common.detail')"
    class="font-mono text-primary-600 underline hover:no-underline" data-workspace-open="beside" @click="open">{{ label }}</a>
  <span v-else class="font-mono">{{ label }}</span>
</template>
