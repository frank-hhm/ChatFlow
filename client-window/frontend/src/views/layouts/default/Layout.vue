<template>
    <div class="layout">
        <div class="layout-side">
            <div class="layout-side-left">
                <LayoutSideMenu ref="LayoutSideMenuRef"></LayoutSideMenu>
            </div>
            <div class="account-box">
                <a-dropdown content-class="account-box-popover" trigger="click" position="tl">
                    <div class="account-avatar">
                        <a-avatar :size="50" :imageUrl="accountInfo.avatar"></a-avatar>
                    </div>
                    <template #content>
                        <a-doption @click="onSettingClick">
                            <div class="account-box-popover-menu-item">
                                <span class="icon icon-shezhi-2 mr5"></span>
                                <span>设置</span>
                            </div>
                        </a-doption>
                        <a-doption>
                            <div class="account-box-popover-menu-item" @click="onOutLogin">
                                <span class="icon icon-guanji mr5"></span>
                                <span>退出登录</span>
                            </div>
                        </a-doption>
                    </template>
                </a-dropdown>
                <settingModal ref="settingModalRef"></settingModal>
            </div>
        </div>

        <div class="layout-body">
            <router-view></router-view>
        </div>
    </div>
</template>
<script lang="ts" setup>
import LayoutSideMenu from "./LayoutSideMenu.vue";
import settingModal from "@/views/components/setting.vue";
import {
    getCurrentInstance,
    onMounted,
} from "vue";
import { useAccountStore, useWebsocketStore } from "@/store";
import { storeToRefs } from "pinia";
import { Message } from "@arco-design/web-vue";
import router from "@/router";
import { logoutApi } from "@/api/login";

const { accountInfo } = storeToRefs(useAccountStore());
const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const onSettingClick = () => {
    proxy?.$refs["settingModalRef"]?.open();
}

const onOutLogin = () => {
    Message.loading({
        id: 'outlogin',
        content: "正在退出...",
    });
    logoutApi().then(async (res: any) => {
        useAccountStore().resetToken();
        useWebsocketStore().closeWebSocket();
        $utils.setStorage("token", null);
        setTimeout(() => {
            Message.success({
                id: 'outlogin',
                content: "退出成功!",
                duration: 1000,
                onClose: () => {
                    toLogin();
                },
            });
        }, 500);
    });
}

const toLogin = () => {
    router.replace({
        path: "/login",
        query: {
            redirect: router.currentRoute.value.fullPath,
        },
    });
};


onMounted(() => {

});

</script>
<style lang="less">
.account-box-popover {
    width: 160px;

    .account-box-popover-menu-item {
        display: flex;
        align-items: center;
        height: 40px;
        line-height: 40px;

        .icon {
            font-size: 16px;
        }
    }
}
</style>
<style scoped>
.layout {}

.layout-side {
    width: 80px;
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
    width: 60px;
    z-index: 99;
    padding: 20px 10px;
    border-right: 1px solid var(--color-border-1);
}

.layout-body {
    left: 81px;
    top: 0;
    position: fixed;
    width: calc(100% - 81px);
    height: 100%;
    background: var(--color-bg-body);
}


html.dark .layout-body {
    background: var(--color-bg-1);
}

.account-box {
    position: absolute;
    bottom: 10px;
    left: 10px;
    z-index: 999;
}


.account-box-popover-menu {
    width: 160px;

    .popover-menu-item {
        padding: 10px;
        cursor: pointer;
        margin-bottom: 2px;
        border-radius: var(--border-radius-medium);

        &:hover {
            background: var(--color-fill-1);
        }
    }
}

.account-avatar {
    height: 60px;
    width: 60px;
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>