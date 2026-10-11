import BasicLayout from "@/components/layouts/index.vue";

export default [{
    path: '/',
    name: '/',
    header: '/',
    redirect: {
        path: `/index`
    },
    component: BasicLayout,
    children: [
        {
            path: '/index',
            name: '/index',
            meta: {
                auth: true,
                menu_name: "首页",
            },
            component: () => import('@/views/index/index.vue')
        },
        {
            path: '/detail',
            name: '/detail',
            meta: {
                auth: true,
                menu_name: "个人中心",
            },
            component: () => import('@/views/index/detail.vue')
        }
    ]
}]
