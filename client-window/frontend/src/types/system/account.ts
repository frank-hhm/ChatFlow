export interface IAccount {
    id?: string | number
    tenant_id?: string | number
    avatar?: string
    email?: string
    phone?: string
    nick_name?: string
    status?: {
        value: number | string;
        name: string;
    }
    [key: string]: any
} 
