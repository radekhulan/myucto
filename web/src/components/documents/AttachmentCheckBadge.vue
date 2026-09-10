<script setup lang="ts">
/**
 * Odznak „doklad proti příloze" v detailu dokladu. Doklad bez vytěžené přílohy se
 * nekontroluje a odznak se nezobrazí vůbec. Klik otevře porovnání a potvrzení.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AttachmentCheckReview from './AttachmentCheckReview.vue'
import {
  attachmentChecksApi,
  type AttachmentCheckResult,
  type AttachmentEntityType,
} from '@/api/attachmentChecks'

const props = withDefaults(defineProps<{
  entityType: AttachmentEntityType
  entityId: number
  canAcknowledge?: boolean
}>(), { canAcknowledge: true })

const { t } = useI18n()
const result = ref<AttachmentCheckResult | null>(null)
const open = ref(false)

async function load() {
  try {
    result.value = await attachmentChecksApi.forEntity(props.entityType, props.entityId)
  } catch {
    // Odznak je doplněk detailu — chyba ho jen skryje, detail dokladu nesmí shodit.
    result.value = null
  }
}

onMounted(load)
watch(() => props.entityId, load)

const state = computed(() => result.value?.summary.state ?? 'none')

const label = computed(() => {
  const n = result.value?.summary.open ?? 0
  switch (state.value) {
    case 'ok': return t('attachment_check.badge_ok')
    case 'acknowledged': return t('attachment_check.badge_acknowledged')
    case 'warning': return t('attachment_check.badge_warning', { n })
    case 'info': return t('attachment_check.badge_info', { n })
    default: return ''
  }
})

const cls = computed(() => ({
  ok: 'bg-success-50 text-success-600 hover:bg-success-100',
  acknowledged: 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200',
  warning: 'bg-warning-50 text-warning-700 hover:bg-warning-100',
  info: 'bg-primary-50 text-primary-700 hover:bg-primary-100',
  none: '',
}[state.value]))

function onChanged(r: AttachmentCheckResult) {
  result.value = r
}
</script>

<template>
  <button v-if="state !== 'none'" type="button" data-testid="attachment-check-badge" :data-state="state"
    class="text-xs px-2 py-0.5 rounded font-normal cursor-pointer whitespace-nowrap transition-colors" :class="cls"
    :title="t('attachment_check.badge_title')" @click="open = true">
    {{ label }}
  </button>
  <AttachmentCheckReview v-if="open && result" :entity-type="entityType" :entity-id="entityId" :initial="result"
    :can-acknowledge="canAcknowledge" @close="open = false" @changed="onChanged" />
</template>
