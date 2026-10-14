import { ref } from 'vue'
import { defineStore } from 'pinia'

export const useAppStore = defineStore('app', () => {
  const isRailCollapsed = ref(false)
  const rechargeOpen = ref(false)
  const rechargeRevision = ref(0)

  function toggleRail() {
    isRailCollapsed.value = !isRailCollapsed.value
  }

  return {
    isRailCollapsed,
    rechargeOpen,
    rechargeRevision,
    toggleRail,
  }
})
