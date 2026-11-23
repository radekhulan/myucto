<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemSettingsApi, resolveOtherItemFolder } from '@/api/otherItemSettings'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'

const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const folder = ref('')
const defaultFolder = ref('')
const loading = ref(true)
const saving = ref(false)
const canWrite = computed(() => auth.canWrite('other_items'))
const preview = computed(() => resolveOtherItemFolder(folder.value.trim() || defaultFolder.value, appIsoDate()) ?? '')

onMounted(async () => {
  try {
    const settings = await otherItemSettingsApi.get()
    folder.value = settings.document_folder
    defaultFolder.value = settings.document_folder_default
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
})

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    const settings = await otherItemSettingsApi.update(folder.value)
    folder.value = settings.document_folder
    toast.success(t('other_items.documents_settings.saved'))
    emit('close')
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('other_items.documents_settings.title')" width-class="max-w-lg" @close="emit('close')">
    <div v-if="loading" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <form v-else class="space-y-3" @submit.prevent="save">
      <p class="text-sm text-neutral-600">{{ t('other_items.documents_settings.hint') }}</p>
      <label class="block text-sm font-medium">{{ t('other_items.documents_settings.folder_label') }}
        <input v-model="folder" maxlength="500" :readonly="!canWrite" :placeholder="defaultFolder" data-test="other-item-folder"
          class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
      </label>
      <p v-if="preview" class="text-xs text-neutral-500" data-test="other-item-folder-preview">{{ t('other_items.documents_settings.preview', { folder: preview }) }}</p>
      <div v-if="canWrite" class="flex flex-wrap gap-2 pt-2">
        <button type="submit" :disabled="saving" :class="btnFilled('primary')" data-test="other-item-folder-save">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
        <button v-if="folder !== defaultFolder" type="button" :class="btnOutline('neutral')" data-test="other-item-folder-reset" @click="folder = defaultFolder">
          {{ t('other_items.documents_settings.reset') }}
        </button>
      </div>
    </form>
  </Modal>
</template>
