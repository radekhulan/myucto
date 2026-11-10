<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import RequiredMark from '@/components/ui/RequiredMark.vue'

const { t } = useI18n()
const tenantId = defineModel<string | null>('tenantId')
const clientId = defineModel<string | null>('clientId')
const clientSecret = defineModel<string | null>('clientSecret')
// Pole přebírají vzhled formuláře, do kterého jsou vložená (odesílací profil
// má menší popisky a nižší pole než IMAP účet bankovních avíz).
withDefaults(defineProps<{
  secretSaved?: boolean
  labelClass?: string
  inputClass?: string
}>(), {
  secretSaved: false,
  labelClass: 'block text-xs font-medium text-neutral-600',
  inputClass: 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm',
})
</script>

<template>
  <label :class="labelClass">
    {{ t('settings.imap_oauth_tenant_id') }}<RequiredMark />
    <input v-model.trim="tenantId" required maxlength="36" spellcheck="false" :class="inputClass" />
  </label>
  <label :class="labelClass">
    {{ t('settings.imap_oauth_client_id') }}<RequiredMark />
    <input v-model.trim="clientId" required maxlength="36" spellcheck="false" :class="inputClass" />
  </label>
  <label :class="labelClass">
    {{ t('settings.imap_oauth_client_secret') }}<RequiredMark v-if="!secretSaved" />
    <input v-model="clientSecret" type="password" autocomplete="new-password" :required="!secretSaved" maxlength="1024"
      :placeholder="secretSaved ? t('settings.imap_oauth_secret_saved') : undefined"
      :class="inputClass" />
  </label>
</template>
