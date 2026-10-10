<template>
    <LayoutNav></LayoutNav>
    <div class="layout">
        <div class="layout-side" >
            <div class="layout-side-left">
                <LayoutSideMenu ref="LayoutSideMenuRef" ></LayoutSideMenu>
            </div>
        </div>

        <div class="layout-body" >
            <router-view></router-view>
        </div>
        <div class="layout-footer">
            <copyright></copyright>
        </div>
    </div>
</template>
<script lang="ts" setup>
import LayoutSideMenu from "./LayoutSideMenu.vue";
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

</script>
<style scoped>
.layout {}
</style>