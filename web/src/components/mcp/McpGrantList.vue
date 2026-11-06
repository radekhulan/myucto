<script setup lang="ts" generic="T extends { id: number; client_name: string; is_active: boolean }">
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

defineProps<{ grants: T[]; revokingId: number | null; revokeLabel: string }>()
const emit = defineEmits<{ revoke: [grant: T] }>()
</script>

<template>
  <div class="divide-y divide-neutral-200 border border-neutral-200 rounded-md">
    <div v-for="grant in grants" :key="grant.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
      <div class="min-w-0">
        <div class="font-medium text-sm">{{ grant.client_name }}</div>
        <slot name="details" :grant="grant" />
      </div>
      <button v-if="grant.is_active" type="button" :disabled="revokingId !== null" :class="btnOutline('danger')" @click="emit('revoke', grant)">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
        </svg>
        {{ revokeLabel }}
      </button>
    </div>
  </div>
</template>
