import { ref } from 'vue'
import { defineStore } from 'pinia'
import { favoritesApi, type Favorite } from '@/api/favorites'

export const MAX_FAVORITES = 50

export const useFavoritesStore = defineStore('favorites', () => {
  const items = ref<Favorite[]>([])
  const loaded = ref(false)
  const open = ref(false)
  let loading: Promise<void> | null = null

  function load(force = false): Promise<void> {
    if (loaded.value && !force) return Promise.resolve()
    loading ??= favoritesApi.list()
      .then(list => { items.value = list; loaded.value = true })
      .catch(() => undefined)
      .finally(() => { loading = null })
    return loading
  }

  async function add(path: string, title: string): Promise<Favorite> {
    const created = await favoritesApi.create({ path, title })
    if (!items.value.some(item => item.id === created.id)) items.value.push(created)
    return created
  }

  async function rename(id: number, title: string): Promise<void> {
    const updated = await favoritesApi.rename(id, title)
    const index = items.value.findIndex(item => item.id === id)
    if (index !== -1) items.value[index] = updated
  }

  async function remove(id: number): Promise<void> {
    await favoritesApi.remove(id)
    items.value = items.value.filter(item => item.id !== id)
  }

  return { items, loaded, open, load, add, rename, remove }
})
