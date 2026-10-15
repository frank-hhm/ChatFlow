<template>
    <a-modal title="设置" @BeforeOk="onCreateOk" @BeforeCancel="close" :width="isMobile ? 'calc(100% - 20px)' : '600px'"
        class="modal" v-model:visible="visible" align-center title-align="start" render-to-body :footer="false">
        <div class="detail-main">
            <div class="avatar-main">
                <a-avatar @click="onUpdateAvatar" shape="square" :size="80">
                    <img :src="accountInfo?.avatar" />
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
                    <div class="desc">{{ accountInfo.account }}</div>
                </div>
                <div class="detail-item">
                    <div class="title">邮箱：</div>
                    <div class="desc">{{ accountInfo.email }}</div>
                    <div class="ml20">
                        <a-button size="small" shape="circle" @click="onUpdateEmail"><icon-edit /></a-button>
                    </div>
                </div>
            </div>
        </div>
        <template #footer>
        </template>
    </a-modal>
</template>

<script lang="ts">
export default {
    name: "update-email",
};
</script>
<script lang="ts" setup>
import { ref, getCurrentInstance, onMounted } from "vue";
import { EnumItemType, Result, ResultError } from "@/types";
import { storeToRefs } from "pinia";
import { useAccountStore, useAppStore } from "@/store";
import { uploadAvatarApi } from "@/api/tenant/account";

const { isMobile } = storeToRefs(useAppStore());

const { accountInfo } = storeToRefs(useAccountStore());

const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const labelColFlex = ref<string>("80px");

const emit = defineEmits(["success"]);

const visible = ref<boolean>(false);


const btnLoading = ref<boolean>(false);

const onCreateOk = () => {
};

const onUpdateEmail = () => {
    proxy?.$refs["emailComponentRef"]?.open();
}
const onChangeAvatar = (file: File) => {
    proxy?.$refs['cropperRef']?.close();
    avatarLoading.value = true;
    let fd = new FormData();
    fd.append('file', file);
    const { progress, request } = uploadAvatarApi(fd)
    avatarProgress.value = progress
    request.then((res: Result) => {
        avatarProgress.value = {
            parent: 100
        }
        useAccountStore().initInfo()
        setTimeout(() => {
            $utils.successMsg(res.message)
            avatarLoading.value = false;
        }, 500);
    }).catch((err: ResultError) => {
        $utils.errorMsg(err)
        avatarProgress.value = {
            parent: 0
        }
        avatarLoading.value = false;
    });
}

const cropperRef = ref<HTMLElement>();

const onUpdateAvatar = () => {
    proxy?.$refs['cropperRef']?.open();
}

const avatarLoading = ref<boolean>(false);
const avatarProgress = ref<any>({
    percent: 0,
});

const open = () => {
    btnLoading.value = false;
    visible.value = true;
};

const close = () => {
    proxy?.$refs['createRef']?.resetFields();
    visible.value = false;
    btnLoading.value = false;
    return true;
};

onMounted(() => {
});

defineExpose({ open });
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
</style>