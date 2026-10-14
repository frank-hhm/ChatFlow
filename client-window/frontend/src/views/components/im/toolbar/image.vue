<template>
    <!-- 图片消息：上传中/失败/成功 三种状态 -->
    <div class="toolbar-image-btn" @click.stop="triggerImageSelect">
        <div class="icon icon-img"></div>
        <input ref="imageInputRef" type="file" accept="image/*" multiple style="display: none;"
            @change="onImageSelect" />
    </div>
</template>
<script setup lang="ts">
import { uploadMediaApi } from '@/api/im';
import { ref, watch } from 'vue';

const emit = defineEmits(['select', 'progress', 'change']);

const imageInputRef = ref<HTMLElement>();

const isDragOver = ref<boolean>(false);

const triggerImageSelect = () => {
    imageInputRef.value?.click();
};

const onImageSelect = (event: any) => {
    const files = event.target.files;
    if (!files || files.length === 0) return;
    event.target.value = "";
    handleImageFiles(Array.from(files));
};

const onDrop = (event: any) => {
    isDragOver.value = false;
    const files = event.dataTransfer.files;
    if (!files || files.length === 0) return;

    const imageFiles = Array.from(files).filter((file: any) => file.type.startsWith("image/"));
    if (imageFiles.length === 0) {
        console.warn("未选择有效的图片文件");
        return;
    }
    handleImageFiles(imageFiles);
};


/**
 * 处理图片文件：先上传到服务器获取 URL，再将 URL 通过 WebSocket 发送
 * @param files 图片文件列表
 */
const handleImageFiles = async (files) => {
    for (const file of files) {
        // 1. 先生成本地消息（上传中状态）
        const msgId = Date.now() + Math.random();

        // 2. 执行上传（复用统一的上传逻辑）
        try {
            const result = await uploadImageFile(
                file,
                msgId,
                (percent) => {
                    emit('change', { event: "progress", msgId, progress: percent });
                }
            );

            const continueNext = handleUploadResult(msgId, result, "图片上传");
            if (continueNext) continue;
        } catch (err) {
            console.error("图片上传失败:", err);
            emit('change', { event: "error", msgId });
        }
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
    emit('change', { event: "abort", msgId, controller });

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
    }
};

/**
 * 处理上传完成后的统一逻辑（成功/取消/失败）
 * @returns { boolean } true=应继续处理下一个文件(cancelled)，false=正常结束
 */
const handleUploadResult = (msgId, result, logPrefix) => {
    if (result.cancelled) {
        // 将取消事件发给父组件
        emit('change', { event: "cancelled", msgId, result });
        console.log(logPrefix + ": 上传已取消");
        return true;
    }

    if (!result.imageUrl) {
        emit('change', { event: "error", msgId, result });
        return false;
    }

    //发送上传成功事件
    emit('change', { event: "sucess", msgId, result });
    return false;
};



defineExpose({ onDrop });
</script>