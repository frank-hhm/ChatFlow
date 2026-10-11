import { request, requestProgress } from '@/utils/request/default'
export const getListTenantAccountApi = (params: any) => {
    return request({
        url: `tenant.account/list`,
        method: 'GET',
        params
    })
}
export const createTenantAccountApi = (data: any) => {
    return request({
        url: 'tenant.account/create',
        method: 'POST',
        data
    })
}
export const updateTenantAccountApi = (data: any) => {
    return request({
        url: `tenant.account/update`,
        method: 'PUT',
        data
    })
}
export const getDetailTenantAccountApi = (params: any) => {
    return request({
        url: 'tenant.account/detail',
        method: 'GET',
        params
    })
}

export const updateStatusTenantAccountApi = (data: any) => {
    return request({
        url: `tenant.account/status`,
        method: 'PUT',
        data
    })
}
export const updatePassTenantAccountApi = (data: any) => {
    return request({
        url: `tenant.account/updatePass`,
        method: 'PUT',
        data
    })
}

export const deleteTenantAccountApi = (params: { id: number | string }) => {
    return request({
        url: `tenant.account/delete`,
        method: 'DELETE',
        params
    })
}