<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PdfFrame from './PdfFrame.vue'

/** `compact` = nižší náhled pro detail dokladu na telefonu, kde pod ním pokračuje obsah. */
defineProps<{ src: string; fileName: string; docType: string; compact?: boolean }>()
const { t } = useI18n()
</script>

<template>
  <div class="bg-neutral-50" :class="compact ? 'min-h-[12rem]' : 'min-h-[420px]'">
    <PdfFrame v-if="docType === 'pdf'" :key="src" :src="src"
      class="block w-full" :class="compact ? 'h-[55dvh] min-h-[18rem]' : 'h-[68vh] min-h-[420px]'" :title="fileName" />
    <div v-else-if="docType === 'image'" class="flex justify-center" :class="compact ? 'p-2' : 'p-4'">
      <img :key="src" :src="src" :alt="fileName" class="object-contain" :class="compact ? 'max-h-[55dvh] w-full' : 'max-h-[68vh]'" />
    </div>
    <div v-else class="flex items-center justify-center p-6 text-center text-sm text-neutral-500" :class="compact ? 'min-h-[12rem]' : 'min-h-[420px]'">
      {{ t('purchase_submissions.no_inline_preview') }}
    </div>
  </div>
</template>
