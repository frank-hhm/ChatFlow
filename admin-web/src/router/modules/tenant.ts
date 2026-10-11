export default [
    {
        path: `/tenant/tenant/list`,
        name: `tenantList`,
        component: () => import("@/views/tenant/tenant/list.vue"),
        meta: {
            menu_name: "租户",
        },
    },
    {
        path: `/tenant/account/list`,
        name: `tenantAccountList`,
        component: () => import("@/views/tenant/account/list.vue"),
        meta: {
            menu_name: "租户客服",
        },
    },
]