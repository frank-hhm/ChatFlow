<template>
    <!-- 图片消息：上传中/失败/成功 三种状态 -->
    <div class="message-content-image">

        <div class="upload-cancel icon icon-cuo" v-if="msg.uploadStatus === 'uploading'" @click="cancelUpload(msg.id)">
        </div>
        <div v-else-if="msg.uploadStatus === 'failed'" class="upload-retry icon icon-shuaxin" @click="retryUpload(msg)">
        </div>
        <div class="message-content-image-box">
            <!-- 上传中 -->
            <div v-if="msg.uploadStatus === 'uploading'" class="image-upload-wrapper">
                <!-- <div class="upload-progress-bg">
                                        <div class="upload-progress-bar" :style="{ width: msg.progress + '%' }"></div>
                                    </div> -->
                <!-- <span class="upload-text">{{ msg.progress }}%</span> -->
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
            <img v-else :src="msg.content" class="message-image" @error="handleImageError" />
        </div>
    </div>
</template>
<script setup lang="ts">
import { uploadMediaApi } from '@/api/im';
import { ref, watch } from 'vue';

const emit = defineEmits(['change']);

const triggerImageSelect = () => {
    imageInputRef.value?.click();
};

/* ================= 图片上传发送（先上传再发送） ================= */

/** 用 messageId 作为 key 存储 AbortController，用于取消上传 */
const uploadAbortControllers = ref<Record<number, AbortController>>({});

/**
 * 取消指定消息的上传
 * @param msgId 消息 ID
 */
const cancelUpload = (msgId) => {
    const controller = uploadAbortControllers.value[msgId];
    if (controller) {
        controller.abort();
        delete uploadAbortControllers.value[msgId];
        emit("change", msgId)
    }
};


/**
 * 上传图片文件（封装上传核心逻辑，支持进度和取消）
 * @returns { imageUrl: string, cancelled: boolean }
 */
const uploadImageFile = async (
    file,
    msgId,
    onProgress
) => {
    const controller = new AbortController();
    uploadAbortControllers.value[msgId] = controller;

    let stopProgressWatch = () => { };

    try {
        const formData = new FormData();
        formData.append("file", file);

        const { progress, request } = uploadMediaApi(
            formData,
            { signal: controller.signal }
        );

        stopProgressWatch = watch(
            () => progress.percent,
            (newPercent) => onProgress(newPercent)
        );

        const uploadRes = await request;

        // 兼容不同的响应结构
        const imageUrl = uploadRes.data?.file_url || uploadRes.data?.data?.url || uploadRes.data?.url;

        if (!imageUrl) {
            throw new Error("上传成功但未返回图片 URL");
        }

        return { imageUrl, cancelled: false };
    } catch (err: any) {
        if (err.code === "CANCELLED" || err.name === "CanceledError") {
            return { imageUrl: null, cancelled: true };
        }
        throw err;
    } finally {
        stopProgressWatch();
        delete uploadAbortControllers.value[msgId];
    }
};

/**
 * 重试上传失败的消息
 * @param msg 失败的消息对象
 */
const retryUpload = async (msg) => {
    console.log("重试上传:", msg.id);
    const file = msg.file;
    if (!file) {
        console.warn("文件已失效，请重新选择");
        msg.uploadStatus = "failed";
        return;
    }

    const msgId = msg.id;

    // 重试前恢复上传中状态，显示进度条
    msg.uploadStatus = "uploading";
    msg.progress = 0;

    try {
        const result = await uploadImageFile(
            file,
            msgId,
            (percent) => {
                const m = messageList.value.find((x) => x.id === msgId);

                if (m) m.progress = percent;
            }
        );

        const continueNext = handleUploadResult(msgId, result, "重试上传");
        if (continueNext) return;
    } catch (err) {
        console.error("重试上传失败:", err);
        handleUploadFail(msgId);
    }
};


/**
 * 处理上传完成后的统一逻辑（成功/取消/失败）
 * @returns { boolean } true=应继续处理下一个文件(cancelled)，false=正常结束
 */
const handleUploadResult = (msgId, result, logPrefix) => {
    if (result.cancelled) {
        messageList.value = messageList.value.filter((m) => m.id !== msgId);
        console.log(logPrefix + ": 上传已取消");
        return true;
    }

    if (!result.imageUrl) {
        handleUploadFail(msgId);
        return false;
    }

    handleUploadSuccess(msgId, result.imageUrl);
    return false;
};



/**
 * 上传成功后更新消息状态并发送 WebSocket 消息
 */
const handleUploadSuccess = (msgId, imageUrl) => {
    const msg = messageList.value.find((m) => m.id === msgId);
    if (msg) {
        msg.content = imageUrl;
        msg.uploadStatus = "success";
        msg.progress = 100;
    }

    useWebsocketStore().sendMessage({
        type: "client_reply",
        content: imageUrl,
        conversation_id: itemConversation.value.id,
        msg_type: "image",
    });
    initConversationList();
};

/**
 * 上传失败后标记消息为失败状态
 */
const handleUploadFail = (msgId) => {
    const msg = messageList.value.find((m) => m.id === msgId);
    if (msg) {
        msg.uploadStatus = "failed";
        msg.progress = 0;
    }
};

const handleImageError = (event) => {
    event.target.style.display = "none";
};
</script>