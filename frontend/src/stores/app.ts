import { ref } from 'vue'
import { defineStore } from 'pinia'

export const useAppStore = defineStore('app', () => {
  const isRailCollapsed = ref(false)

  function toggleRail() {
    isRailCollapsed.value = !isRailCollapsed.value
  }

  return {
    isRailCollapsed,
    toggleRail,
  }
})
