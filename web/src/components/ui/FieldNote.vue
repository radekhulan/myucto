<script setup lang="ts">
/**
 * Hláška přímo pod polem formuláře: chyba (červeně), kvůli které uložení
 * neprošlo, nebo varování (žlutě) k uloženému údaji, který se jinde ještě
 * tvrdě kontroluje. Chyba má přednost — obojí najednou by se četlo špatně.
 */
defineProps<{ error?: string | null; warning?: string | null }>()
</script>

<template>
  <span v-if="error" class="mt-1 block text-xs text-danger-600" data-test="field-note-error">{{ error }}</span>
  <span v-else-if="warning" class="mt-1 flex items-start gap-1 text-xs text-warning-700" data-test="field-note-warning">
    <svg class="mt-px h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /></svg>
    <span class="min-w-0">{{ warning }}</span>
  </span>
</template>
