import { request, requestProgress } from '@/utils/request/default'
export const getListTenantApi = (params: any) => {
    return request({
        url: `tenant.tenant/list`,
        method: 'GET',
        params
    })
}
export const createTenantApi = (data: any) => {
    return request({
        url: 'tenant.tenant/create',
        method: 'POST',
        data
    })
}
export const updateTenantApi = (data: any) => {
    return request({
        url: `tenant.tenant/update`,
        method: 'PUT',
        data
    })
}
export const getDetailTenantApi = (params: any) => {
    return request({
        url: 'tenant.tenant/detail',
        method: 'GET',
        params
    })
}

export const updateStatusTenantApi = (data: any) => {
    return request({
        url: `tenant.tenant/status`,
        method: 'PUT',
        data
    })
}
export const updatePassTenantApi = (data: any) => {
    return request({
        url: `tenant.tenant/updatePass`,
        method: 'PUT',
        data
    })
}

export const deleteTenantApi = (params: { id: number | string }) => {
    return request({
        url: `tenant.tenant/delete`,
        method: 'DELETE',
        params
    })
}