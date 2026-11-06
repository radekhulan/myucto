<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
const props = defineProps<{ supplier: number; labels?: Record<number, string>; readonly?: boolean }>()
const emit = defineEmits<{ personSelected: [value: { id: number; name: string }] }>()
const model = defineModel<number[]>({ required: true }), person = ref<number | null>(null), names = ref<Record<number, string>>({})
const { t } = useI18n(), labels = computed(() => ({ ...props.labels, ...names.value }))
function add(p: { id: number; name: string }) { names.value[p.id] = p.name; emit('personSelected', p); if (!model.value.includes(p.id)) model.value = [...model.value, p.id].sort((a, b) => a - b); person.value = null }
</script>
<template><div class="space-y-2"><div class="flex flex-wrap gap-2"><span v-for="id in model" :key="id" class="flex flex-wrap items-center gap-2 rounded border p-2">{{ labels[id] || t('propertySettlements.selectedPerson') }}<PropertyButton v-if="!readonly" icon="trash" variant="danger" @click="model = model.filter(v => v !== id)">{{ t('propertySettlements.remove') }}</PropertyButton></span></div><PropertyPartyPicker v-if="!readonly" v-model="person" :supplier-id="supplier" @selected="add" /></div></template>
