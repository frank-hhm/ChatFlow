<template>
    <template v-for="(item, index) in menus" :key="index">
        <div class="layout-side-item" :class="curPath == item.id ? 'active' : ''" @click="onPath(item)">
            <div class="icon" :class="item.icon"></div>
            <div class="name">{{ item.menu_name }}</div>
        </div>
    </template>
</template>
<script lang="ts">
export default {
    name: "LayoutSideMenu",
};
</script>
<script setup lang="ts">
import {
    ref,
    getCurrentInstance,
    onMounted,
    watch,
    nextTick,
} from "vue";
import { useRouter, useRoute } from "vue-router";
import { storeToRefs } from "pinia";

const {
    proxy: { $utils },
} = getCurrentInstance() as any;

const router = useRouter();

const route = useRoute();

const emit = defineEmits(["change"]);

const menus = ref<any>([{
    menu_name: "聊天",
    icon: "icon-pinglun-1",
    menu_path: "/index"
}, {
    menu_name: "通讯录",
    icon: "icon-pinglun-1",
    menu_path: "/index"
}])

const curPath = ref<number | string | unknown>(null);
const onPath = (menu: any) => {
    emit("change", []);
    // 直接跳转指定页面
    curPath.value = menu.menu_path
    router.push({
        path: menu.menu_path,
        query: menu.url_params || {},
    });
};
const initRoute = () => {
    if (!menus.value || menus.value == undefined) {
        router.replace({
            path: "/",
        });
        return false;
    }
};


watch(
    () => router.currentRoute.value,
    (val) => {
        initRoute();
    },
    { deep: true }
);

watch(
    () => menus.value,
    (val) => {
        menus.value = val;
        nextTick(() => {
            initRoute();
        });
    },
    { deep: true }
);

onMounted(() => {
    nextTick(() => {
        initRoute();
    });
});
</script>
<style scoped>
.layout-side-item {
    width: 40px;
    /* height: 30px; */
    height: 40px;
    text-align: center;
    padding: 10px;
    cursor: pointer;
    border-radius: var(--border-radius-medium);
    color: var(--color-text-1);
    margin-bottom: 10px;
    user-select: none;
    position: relative;
}

.layout-side-item .icon {
    font-size: 24px;
    font-weight: 400;
    line-height: 24px;
}

.layout-side-item .name {
    font-size: 12px;
    line-height: 20px;
    display: flex;
    justify-content: center;
    white-space: nowrap;
}

.layout-side-sub-item>div {
    margin: 0 10px;
    /* height: 40px; */
}

.layout-side-item.active {
    color: rgb(var(--primary-6));
    background: var(--color-fill-2);
}

.layout-side-item:hover {
    color: rgb(var(--primary-6));
    background: var(--color-fill-2);
}
</style>