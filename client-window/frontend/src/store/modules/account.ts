import { ref } from "vue"
import store from "@/store"
import { defineStore } from "pinia"
import { getToken, removeToken } from '@/utils'
import { IAccount } from "@/types"
import { getDefaultDetailApi } from "@/api/tenant/account"

export const useAccountStore = defineStore("account", () => {

    const token = ref<string>(getToken() || "")

    const accountInfo = ref<IAccount>({})
    const setInfo = (value: IAccount) => {
        accountInfo.value = value
    }

    const initInfo = (callback: Function) => {
        getDefaultDetailApi().then(res => {
            accountInfo.value = res.data
            callback && callback()
        })
    }

    /** 重置 Token */
    const resetToken = () => {
        removeToken()
        token.value = ""
    }
    return { token, accountInfo, initInfo, setInfo, resetToken }
})

/** 在 setup 外使用 */
export function useAccountStoreHook() {
    return useAccountStore(store)
}


export default {
    useAccountStore,
    useAccountStoreHook
}