<template>
    <!-- 图片消息：上传中/失败/成功 三种状态 -->
    <div class="message-content-image">

        <div class="upload-cancel icon icon-cuo" v-if="msg.uploadStatus === 'uploading'" @click="cancelUpload(msg.id)"></div>
        <div v-else-if="msg.uploadStatus === 'failed'" class="upload-retry icon icon-shuaxin" @click="retryUpload(msg)"></div>
        <div class="message-content-image-box">
            <!-- 上传中 -->
            <div v-if="msg.uploadStatus === 'uploading'" class="image-upload-wrapper">
                <a-progress type="circle" status='warning' :percent="parseFloat(msg.progress) / 100" />
            </div>
            <!-- 上传失败 -->
            <div v-else-if="msg.uploadStatus === 'failed'" class="image-upload-wrapper image-upload-failed">
                <div>
                    <div class="failed-icon icon icon-tishi"></div>
                    <div class="failed-text">上传失败</div>
                </div>
            </div>
            <!-- 上传成功 -->
            <div v-else class="image-success-wrapper">
                <img :src="msg.content" class="message-image" @error="handleImageError"
                    @click="previewImage(msg.content)" />
                <!-- 文件大小标签 -->
                <span v-if="fileSizeText" class="image-size-tag">{{ fileSizeText }}</span>
            </div>
            <a-image-preview :src="previewImageUrl" v-model:visible="imageVisible" />
        </div>
    </div>
</template>
<script setup lang="ts">
import { uploadMediaApi } from '@/api/im';
import { computed, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        msg?: Object | any;
    }>(),
    {
        msg: () => {
            return {}
        },
    }
);

const emit = defineEmits(['change']);

/* ================= 图片预览 ================= */
const imageVisible = ref<Boolean>(false);

const previewImageUrl = ref<string | null>(null);

const previewImage = (imageUrl: string) => {
    previewImageUrl.value = imageUrl;
    imageVisible.value = true;
}


/* ================= 文件大小格式化 ================= */
/* ================= 通过 URL 获取文件大小 ================= */
const fileSizeBytes = ref<number | null>(null);

const fetchFileSize = async (url: string) => {
    if (!url) return;
    try {
        // 用 HEAD 请求只拿响应头，不下载完整图片，省流量
        const res = await fetch(url, { method: 'HEAD' });
        const size = res.headers.get('content-length');
        if (size) {
            fileSizeBytes.value = parseInt(size, 10);
        }
    } catch (e) {
        console.log('获取图片大小失败', e);
        // HEAD 请求可能跨域失败，降级用 GET + blob
        try {
            const res = await fetch(url);
            const blob = await res.blob();
            fileSizeBytes.value = blob.size;
        } catch (e2) {
            console.warn('获取图片大小失败', url, e2);
        }
    }
};

const init = () => {
    const url = props.msg.content;
    if (url) {
        fetchFileSize(url);
    }
};

onMounted(() => {
    init()
});


const fileSizeText = computed(() => {
    const size = fileSizeBytes.value;
    if (!size) return '';
    if (size < 1024) return size + ' B';
    if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB';
    return (size / (1024 * 1024)).toFixed(1) + ' MB';
});

/**
 * 取消指定消息的上传
 * @param msgId 消息 ID
 */
const cancelUpload = (msgId) => {
    emit("change", {
        event: "cancel",
        msgId,
    })
};
/**
 * 重新上传
 * @param msgId 消息 ID
 */
const retryUpload = (msgId) => {
    emit("change", {
        event: "retry",
        msgId,
    })
};

const handleImageError = (event) => {
    event.target.style.display = "none";
};
defineExpose({ init });
</script>

<style scoped lang="less">
.message-content-image {
    position: relative;
    display: flex;
    align-items: flex-end;
    height: 120px;

    .upload-cancel,
    .upload-retry {
        margin-right: 10px;
        height: 20px;
        line-height: 20px;
        width: 20px;
        font-size: 12px;
        text-align: center;
        color: #000;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 20px;
        cursor: pointer;
        user-select: none;

        &:hover {
            color: #fff;
        }
    }

    .message-content-image-box {
        max-width: 60%;
        border-radius: var(--base-radius-default);

        /* 图片消息样式 */
        .message-image {
            height: 120px;
            border-radius: var(--base-radius-default);
            display: block;
            cursor: pointer;
            background: var(--color-bg-1);
        }

        /* 图片上传进度样式 */
        .image-upload-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: var(--base-radius-default);
            background: var(--color-bg-1);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* 上传失败样式 */
        .image-upload-failed {
            background: #fef0f0;
            border: 1px solid #fde2e2;
            text-align: center;
        }

        .failed-icon {
            font-size: 20px;
            color: #f56c6c;
            margin-bottom: 4px;
        }

        .failed-text {
            display: block;
            font-size: 12px;
            color: #f56c6c;
            margin-bottom: 4px;
        }

        .image-success-wrapper {
            position: relative;
            display: inline-block;
        }

        .image-size-tag {
            position: absolute;
            bottom: 6px;
            right: 6px;
            padding: 2px 6px;
            font-size: 11px;
            color: #fff;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 20px;
            pointer-events: none;
            user-select: none;
            letter-spacing: -1px;
        }
    }
}
</style>