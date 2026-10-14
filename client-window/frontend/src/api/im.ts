import { request, requestProgress } from '@/utils/request/default'

export const getConversationListApi = (data: any) => {
    return request({
        url: 'tenant.account/getConversationList',
        method: 'GET',
        params: data
    })
}
export const getMessageListApi = (data: any) => {
    return request({
        url: 'tenant.account/getMessageListApi',
        method: 'GET',
        params: data
    })
}

export const readMessageApi = (data: any) => {
    return request({
        url: `tenant.account/readMessage`,
        method: 'POST',
        data
    })
}

export const uploadMediaApi = (data: any, options: any = {}) => {
    return requestProgress({
        url: `tenant.account/uplaodMedia`,
        method: 'POST',
        data
    }, Object.assign({
        isAllow: true
    }, options))
}