<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, type ActionVariant } from '@/components/ui/buttonStyles'

/**
 * Potvrzení nevratné nebo opravné akce ve stylu aplikace (místo nativního confirm()).
 * Rodič ho vykresluje přes v-if, `confirm` spustí akci, `cancel` dialog zavře.
 */
withDefaults(defineProps<{
  title: string
  message: string
  confirmLabel: string
  variant?: ActionVariant
  busy?: boolean
}>(), { variant: 'primary', busy: false })

defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()

const { t } = useI18n()
</script>

<template>
  <Modal :title="title" width-class="max-w-md" @close="$emit('cancel')">
    <p class="text-sm text-neutral-700" data-test="confirm-message">{{ message }}</p>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-test="confirm-cancel" @click="$emit('cancel')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled(variant)" class="whitespace-nowrap" :disabled="busy" data-test="confirm-ok" @click="$emit('confirm')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ confirmLabel }}
        </button>
      </div>
    </template>
  </Modal>
</template>
