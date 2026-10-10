<template>
    <div>
        <a-card title="个人中心">
            <template #extra>
                <a-space>
                    <a-button size="mini" @click="onLoginLogs">登录记录</a-button>
                    <a-popconfirm content="确定要退出登录吗?" @ok="outLogin">
                        <a-button size="mini" type="text" status="danger">退出</a-button>
                    </a-popconfirm>
                    <a-button size="mini" type="text" @click="onUpdatePass">修改密码</a-button>
                </a-space>
            </template>
            <div class="detail-main">
                <div class="avatar-main">
                    <a-avatar @click="onUpdateAvatar" shape="square" :size="80">
                        <img :src="adminInfo?.avatar" />
                        <template #trigger-icon>
                            <IconEdit />
                        </template>
                    </a-avatar>
                    <div v-if="avatarLoading" class="avatar-progress">
                        <a-progress size="mini" :percent="avatarProgress.percent / 100" />
                    </div>
                    <img-cropper ref="cropperRef" @change="onChangeAvatar" :autoClose="false"
                        outputType="png"></img-cropper>
                </div>
                <div class="detail-right">
                    <div class="detail-item">
                        <div class="title">账号：</div>
                        <div class="desc">{{ adminInfo.account }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="title">邮箱：</div>
                        <div class="desc">{{ adminInfo.email }}</div>
                        <div class="ml20">
                            <a-button size="small" shape="circle" @click="onUpdateEmail"><icon-edit /></a-button>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="title">姓名：</div>
                        <div class="desc">{{ adminInfo.real_name }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="title">登录IP：</div>
                        <div class="content">
                            <div class="fz12">{{ adminInfo.last_ip?.value }}</div>
                            <div class="text-grey" v-if="adminInfo.last_ip?.text">{{ adminInfo.last_ip?.text
                            }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </a-card>
    </div>
</template>
<script setup lang="ts">
import { getCurrentInstance, ref, onMounted, watch } from "vue";
import router from "@/router/index";
import { storeToRefs } from "pinia";
import { logoutApi } from '@/api/login';
import { Message } from '@arco-design/web-vue';
import { Result, ResultError } from "@/types";


const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const cropperRef = ref<HTMLElement>();

const onUpdateAvatar = () => {
    proxy?.$refs['cropperRef']?.open();
}

const avatarLoading = ref<boolean>(false);
const avatarProgress = ref<any>({
    percent: 0,
});


const adminPassModalRef = ref<HTMLElement>();

const onUpdatePass = () => {
    proxy?.$refs["adminPassModalRef"]?.open();
};

const outLogin = () => {
    Message.loading({
        id: 'outlogin',
        content: "正在退出...",
    });
    logoutApi().then(async (res: any) => {
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
};

const toLogin = () => {
    router.replace({
        path: "/login",
        query: {
            redirect: router.currentRoute.value.fullPath,
        },
    });
};

const adminInfo = ref<any>(null);

const loginLogsComponentRef = ref<HTMLElement>();

const onLoginLogs = () => {
    proxy?.$refs["loginLogsComponentRef"]?.open(adminInfo?.value.id);
}

const emailComponentRef = ref<HTMLElement>();

const onUpdateEmail = () => {
    proxy?.$refs["emailComponentRef"]?.open();
}

const onChangeAvatar = (res: any) => {
}

</script>
<style scoped>
.detail-main {
    display: flex;

}

.detail-right {
    margin-left: 30px;
}

.detail-item {
    display: flex;
    line-height: 20px;
    align-items: center;
    margin-bottom: 20px;
}

.detail-item .desc {
    font-size: 16px;
    color: var(--color-text-1);

}

.detail-item .content {
    display: flex;
}

.avatar-main {
    height: 80px;
    width: 80px;
    position: relative;
}

.avatar-progress {
    position: absolute;
    left: calc(50% - 8px);
    top: calc(50% - 8px);
}



@media screen and (max-width:699px) {
    .detail-main {
        display: block;
    }

    .avatar-main {
        margin: 0 auto 20px;
    }

    .detail-item .content {
        display: block;
    }

    .detail-right {
        margin-left: 0;
    }
}
</style>
