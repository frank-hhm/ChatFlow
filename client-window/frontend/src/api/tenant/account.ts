import { request, requestProgress } from '@/utils/request/default'

export const getDefaultDetailApi = () => {
    return request({
        url: 'tenant.account/defaultDetail',
        method: 'GET'
    })
}