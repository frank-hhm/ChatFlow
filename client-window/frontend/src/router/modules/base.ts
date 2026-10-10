

export default [{
    path: '/',
    name: '/',
    header: '/',
    redirect: {
        path: `/detail`
    },
    children: [
        {
            path: '/index',
            name: '/index',
            meta: {
                auth: false,
                menu_name: "首页",
            },
            component: () => import('@/views/index/index.vue')
        },
        {
            path: '/detail',
            name: '/detail',
            meta: {
                auth: false,
                menu_name: "个人中心",
            },
            component: () => import('@/views/index/detail.vue')
        }
    ]
}]
