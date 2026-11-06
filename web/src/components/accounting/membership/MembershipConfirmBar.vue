<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { warningBox } from './membershipFormat'

/** `tone`: potvrzení zápisu je `success`, storno (protizápis) `danger` podle sémantiky akčních tlačítek. */
const props = withDefaults(defineProps<{ acknowledged: boolean; busy: boolean; uncertain: boolean; canManage: boolean; ackLabel: string; blockedReason?: string; tone?: 'success' | 'danger' }>(), { blockedReason: undefined, tone: 'success' })
const emit = defineEmits<{ 'update:acknowledged': [value: boolean]; confirm: []; cancel: []; discard: [] }>()
const { t } = useI18n()

const actions = computed<ActionItem[]>(() => [
  {
    key: 'confirm', label: t(props.uncertain ? 'nonprofitMembership.retrySame' : 'nonprofitMembership.confirm'), icon: 'check', tier: 'primary', variant: props.tone, loading: props.busy,
    disabled: props.busy || !props.acknowledged || !props.canManage || !!props.blockedReason,
    disabledReason: props.blockedReason || t('nonprofitMembership.needAck'),
    run: () => emit('confirm'),
  },
  { key: 'cancel', label: t('nonprofitMembership.cancel'), icon: 'x', show: !props.uncertain, disabled: props.busy, run: () => emit('cancel') },
  { key: 'discard', label: t('nonprofitMembership.discard'), icon: 'trash', variant: 'danger', show: props.uncertain, disabled: props.busy, run: () => emit('discard') },
])
</script>

<template>
  <div class="space-y-3" data-section="confirm-bar">
    <p v-if="uncertain" role="status" :class="warningBox" data-section="uncertain">{{ t('nonprofitMembership.uncertain') }}</p>
    <p v-if="blockedReason" role="status" class="text-sm text-warning-700" data-section="blocked">{{ blockedReason }}</p>
    <label class="flex items-start gap-2 text-sm">
      <input type="checkbox" :checked="acknowledged" :disabled="busy || uncertain" data-field="ack" @change="emit('update:acknowledged', ($event.target as HTMLInputElement).checked)" />
      <span>{{ ackLabel }}</span>
    </label>
    <ActionBar :actions="actions" />
  </div>
</template>
