<template>
    <div class="toolbar-audio">
        <div class="toolbar-audio-btn" @click.stop="triggerAudioSelect">
            <div class="icon icon-yinpin"></div>
        </div>
        <input
            ref="audioInputRef"
            type="file"
            accept="audio/*"
            style="display: none;"
            @change="onAudioSelect"
        />
    </div>
</template>

<script setup lang="ts">
import { uploadMediaApi } from '@/api/im';
import { ref, watch } from 'vue';

const emit = defineEmits(['change']);

const audioInputRef = ref<HTMLElement>();

/** 用 messageId 作为 key 存储 AbortController */
const uploadAbortControllers = ref<Record<number, AbortController>>({});

const triggerAudioSelect = () => {
    audioInputRef.value?.click();
};

const onAudioSelect = (event: any) => {
    const files = event.target.files;
    if (!files || files.length === 0) return;

    handleAudioFiles(Array.from(files));
    event.target.value = '';
};

/**
 * 处理音频文件：先上传到服务器获取 URL，再将 URL 通过 WebSocket 发送
 * @param files 音频文件列表
 */
const handleAudioFiles = async (files: any[]) => {
    for (const file of files) {
        // 1. 先生成本地消息（上传中状态）
        const msgId = Date.now() + Math.random();
        console.log('开始处理音频文件:', file.name);
        emit('change', { event: 'start', msgId, content: file, progress: 0 });

        // 2. 执行上传
        try {
            const result = await uploadAudioFile(
                file,
                msgId,
                (percent: any) => {
                    emit('change', { event: 'progress', msgId, progress: percent });
                }
            );

            const continueNext = handleUploadResult(msgId, result, '音频上传');
            if (continueNext) continue;
        } catch (err) {
            console.error('音频上传失败:', err);
            emit('change', { event: 'error', msgId });
        }
    }
};

/**
 * 重试上传失败的消息
 * @param msgId 消息 ID
 * @param file 文件对象
 */
const retryUpload = async (msgId: number | string, file: File) => {
    try {
        const result = await uploadAudioFile(
            file,
            msgId,
            (percent: any) => {
                emit('change', { event: 'progress', msgId, progress: percent });
            }
        );

        const continueNext = handleUploadResult(msgId, result, '重试上传');
        if (continueNext) return;
    } catch (err) {
        console.error('重试上传失败:', err);
        emit('change', { event: 'error', msgId });
    }
};

/**
 * 上传音频文件（封装上传核心逻辑，支持进度和取消）
 */
const uploadAudioFile = async (file: any, msgId: any, onProgress: any) => {
    const controller = new AbortController();
    uploadAbortControllers.value[msgId] = controller;

    emit('change', { event: 'abort', msgId, controller });

    let stopProgressWatch = () => {};

    try {
        const formData = new FormData();
        formData.append('file', file);

        const { progress, request } = uploadMediaApi(formData, { signal: controller.signal });

        stopProgressWatch = watch(
            () => progress.percent,
            (newPercent: any) => onProgress(newPercent)
        );

        const uploadRes = await request;

        // 兼容不同的响应结构
        const audioUrl = uploadRes.data?.file_url || uploadRes.data?.data?.url || uploadRes.data?.url;

        if (!audioUrl) {
            throw new Error('上传成功但未返回音频 URL');
        }

        return { audioUrl, cancelled: false };
    } catch (err: any) {
        if (err.code === 'CANCELLED' || err.name === 'CanceledError') {
            return { audioUrl: null, cancelled: true };
        }
        throw err;
    } finally {
        stopProgressWatch();
        delete uploadAbortControllers.value[msgId];
    }
};

/**
 * 处理上传完成后的统一逻辑（成功/取消/失败）
 * @returns { boolean } true=应继续处理下一个文件(cancel)，false=正常结束
 */
const handleUploadResult = (msgId: any, result: any, logPrefix: string) => {
    if (result.cancelled) {
        emit('change', { event: 'cancel', msgId, result });
        console.log(logPrefix + ': 上传已取消');
        return true;
    }

    if (!result.audioUrl) {
        emit('change', { event: 'error', msgId, result });
        return false;
    }

    // 发送上传成功事件
    emit('change', { event: 'success', msgId, result });
    return false;
};

/**
 * 取消指定消息的上传
 * @param msgId 消息 ID
 */
const cancelUpload = (msgId: any) => {
    const controller = uploadAbortControllers.value[msgId];
    if (controller) {
        controller.abort();
        delete uploadAbortControllers.value[msgId];
        emit('change', { event: 'cancel', msgId });
    }
};

const beforeUnmount = () => {
    Object.values(uploadAbortControllers.value).forEach((controller: any) => controller.abort());
    uploadAbortControllers.value = {};
};

defineExpose({ retryUpload, cancelUpload, beforeUnmount });
</script>

<style scoped lang="less">
.toolbar-audio {
    display: inline-block;

    .toolbar-audio-btn {
        cursor: pointer;

        &:hover {
            .icon {
                opacity: 0.7;
            }
        }

        .icon {
            font-size: var(--base-size-large);
            transition: opacity 0.2s;
        }
    }
}
</style>