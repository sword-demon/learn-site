import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { TokenPair } from '@learn-site/contracts'
import { clearTokens, hasTokens, http, setTokens } from '@/api/http'

export const useLoginFamilyStore = defineStore('loginFamily', () => {
  const loggedIn = ref(hasTokens())

  function signIn(pair: TokenPair): void {
    setTokens(pair)
    loggedIn.value = true
  }

  async function signOut(): Promise<void> {
    try {
      await http.post('/auth/logout')
    } catch {
      /* still drop local tokens */
    }
    clearTokens()
    loggedIn.value = false
  }

  return { loggedIn, signIn, signOut }
})
