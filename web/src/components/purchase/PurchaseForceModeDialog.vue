<script setup lang="ts">
/**
 * Volba režimu vynucené úpravy zaúčtované přijaté faktury v uzavřeném období (B11).
 * Server bez ní vrací 422 force_mode_required: zápis v uzavřeném období nejde přepsat,
 * takže buď storno a nový zápis v běžném období, nebo jen neúčetní pole.
 */
import { ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import type { PurchaseForceMode } from '@/api/purchaseInvoices'

const emit = defineEmits<{ close: []; choose: [mode: PurchaseForceMode] }>()
const { t } = useI18n()
const uid = useId()
const mode = ref<PurchaseForceMode | null>(null)
const MODES: PurchaseForceMode[] = ['reconcile', 'notes_only']
</script>

<template>
  <Modal :title="t('purchase_invoice.force_mode.title')" width-class="max-w-xl" @close="emit('close')">
    <p class="text-sm text-neutral-600 mb-4">{{ t('purchase_invoice.force_mode.help') }}</p>
    <div class="space-y-2" role="radiogroup">
      <label v-for="m in MODES" :key="m" :for="`${uid}-${m}`"
        class="flex items-start gap-3 p-3 rounded-md border cursor-pointer"
        :class="mode === m ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'"
        :data-test="`force-mode-${m}`">
        <input :id="`${uid}-${m}`" v-model="mode" type="radio" :value="m" class="mt-1" />
        <span>
          <span class="block text-sm font-medium text-neutral-800">{{ t(`purchase_invoice.force_mode.${m}`) }}</span>
          <span class="block text-xs text-neutral-500 mt-0.5">{{ t(`purchase_invoice.force_mode.${m}_hint`) }}</span>
        </span>
      </label>
    </div>
    <div class="flex flex-wrap justify-end gap-2 mt-4">
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" :disabled="mode === null"
        data-test="force-mode-confirm" @click="mode && emit('choose', mode)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
        {{ t('purchase_invoice.force_mode.confirm') }}
      </button>
    </div>
  </Modal>
</template>
