<template>
    <div class="layout">
        <div class="layout-side">
            <div class="layout-side-left">
                <LayoutSideMenu ref="LayoutSideMenuRef"></LayoutSideMenu>
            </div>
        </div>

        <div class="layout-body">
            <router-view></router-view>
        </div>
        <div class="layout-footer">
            <copyright></copyright>
        </div>
    </div>
</template>
<script lang="ts" setup>
import LayoutSideMenu from "./LayoutSideMenu.vue";
import common from "@/components/layouts/common";
import {
    ref,
    getCurrentInstance,
    onMounted,
    nextTick,
    computed,
} from "vue";
import { useRoute } from "vue-router";

const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const route = useRoute();

const menusLoad = ref<boolean>(true);

onMounted(() => {

});

</script>
<style scoped>
.layout {}

.layout-side {
    width: 100px;
    position: fixed;
    z-index: -1;
    background: var(--color-bg-1);
    height: 100%;
}

.layout-side .el-menu {
    border-right: none;
}

.layout-side-left {
    height: calc(100% - 40px);
    overflow-x: hidden;
    position: fixed;
    width: 80px;
    z-index: 99;
    height: 100%;
    padding: 20px 10px;
    border-right: 1px solid var(--color-border-1);
}

.layout-body {
    left: 101px;
    top: 51px;
    position: fixed;
    width: calc(100% - 101px - (2*var(--base-padding)));
    height: calc(100% - (2*var(--base-padding)) - 51px - 51px);
    padding: var(--base-padding);
    background: var(--color-bg-body);
    overflow-y: scroll;
}

.layout-footer {
    width: calc(100% - 101px);
    left: 101px;
    text-align: center;
    position: fixed;
    bottom: 0;
    height: 50px;
    background: var(--color-bg-1);
    border-top: 1px solid var(--color-border-1);
    line-height: 40px;
    z-index: -1;
}


.layout-footer img {
    margin: 10px auto;
    height: 30px;
}

html.dark .layout-body {
    background: var(--color-bg-1);
}
</style>