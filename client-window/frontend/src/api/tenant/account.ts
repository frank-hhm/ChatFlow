import { request, requestProgress } from '@/utils/request/default'

export const getDefaultDetailApi = () => {
    return request({
        url: 'tenant.account/defaultDetail',
        method: 'GET'
    })
}

export const uploadAvatarApi = (data: any) => {
    return requestProgress({
        url: `tenant.account/updateAvatar`,
        method: 'POST',
        data
    })
}