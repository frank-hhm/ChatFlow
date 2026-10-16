<template>
    <div class="message-content-video">
        <!-- 上传中 -->
        <div v-if="msg.uploadStatus === 'uploading'" class="video-upload-wrapper">
            <div class="video-icon icon icon-shipin"></div>
            <div class="video-upload-info">
                <a-progress type="circle" status="warning" :percent="parseFloat(msg.progress || 0) / 100" />
                <span class="uploading-text">上传中...</span>
            </div>
            <div class="upload-cancel icon icon-cuo" @click="cancelUpload(msg.id)"></div>
        </div>
        <!-- 上传失败 -->
        <div v-else-if="msg.uploadStatus === 'failed'" class="video-failed-wrapper">
            <div class="failed-icon icon icon-tishi"></div>
            <div class="failed-text">上传失败</div>
            <div class="upload-retry icon icon-shuaxin" @click="retryUpload(msg)"></div>
        </div>
        <!-- 上传成功 -->
        <div v-else class="video-success-wrapper">
            <video ref="videoElementRef" :src="videoUrl" class="video-player" @click="togglePlay"
                @loadedmetadata="onLoadedMetadata" @timeupdate="onTimeUpdate" @ended="onEnded" @canplay="onCanPlay"
                @canplaythrough="onCanPlayThrough" @error="onError" preload="metadata"></video>
            <!-- 播放覆盖层（视频未播放时显示） -->
            <div v-if="!isPlaying && videoUrl" class="video-play-overlay" @click="togglePlay">
                <div class="play-icon icon icon-bofang"></div>
            </div>
            <!-- 时长标签 -->
            <span v-if="durationText" class="video-duration-tag">{{ durationText }}</span>
            <!-- 文件大小标签 -->
            <span v-if="fileSizeText" class="video-size-tag">{{ fileSizeText }}</span>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = withDefaults(
    defineProps<{
        msg?: Object | any;
    }>(),
    {
        msg: () => ({}),
    }
);

const emit = defineEmits(['change']);

/* ================= 视频播放逻辑 ================= */
const videoElementRef = ref<HTMLVideoElement | null>(null);
const isPlaying = ref<boolean>(false);
const currentTime = ref<Number>(0);
const duration = ref<any>(0);

const videoUrl = computed(() => props.msg.file_url || props.msg.content);

const durationText = computed(() => formatTime(duration.value));

const togglePlay = () => {
    if (!videoElementRef.value) return;
    if (isPlaying.value) {
        videoElementRef.value.pause();
    } else {
        videoElementRef.value.play();
    }
};

const onTimeUpdate = () => {
    currentTime.value = videoElementRef.value?.currentTime || 0;
};

const onLoadedMetadata = () => {
    duration.value = videoElementRef.value?.duration || 0;
    isPlaying.value = false;

    console.log('原始分辨率：', videoElementRef.value?.videoWidth, 'x', videoElementRef.value?.videoHeight)
};

const onEnded = () => {
    isPlaying.value = false;
    currentTime.value = 0;
};

const onCanPlay = () => {
    console.log('可以开始播放，但可能还会缓冲')
}

const onCanPlayThrough = () => {
    console.log('预计可流畅播放到结束')
}

const onError = () => {
    console.error('视频加载失败')
}
const formatTime = (seconds: number) => {
    if (!seconds || isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
};

/* ================= 文件大小格式化 ================= */
const fileSizeBytes = ref<number | null>(null);

const fetchFileSize = async (url: string) => {
    if (!url) return;
    try {
        const res = await fetch(url, { method: 'HEAD' });
        const size = res.headers.get('content-length');
        if (size) {
            fileSizeBytes.value = parseInt(size, 10);
        }
    } catch (e) {
        try {
            const res = await fetch(url);
            const blob = await res.blob();
            fileSizeBytes.value = blob.size;
        } catch (e2) {
            console.warn('获取视频大小失败', url, e2);
        }
    }
};

const fileSizeText = computed(() => {
    const size = fileSizeBytes.value;
    if (!size) return '';
    if (size < 1024) return size + ' B';
    if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB';
    return (size / (1024 * 1024)).toFixed(1) + ' MB';
});

/* ================= 上传控制 ================= */
const cancelUpload = (msgId: any) => {
    emit('change', { event: 'cancel', msgId });
};

const retryUpload = (msg: any) => {
    emit('change', { event: 'retry', msgId: msg.id });
};

/* ================= 生命周期 ================= */
const init = () => {
    const url = videoUrl.value;
    if (url) {
        fetchFileSize(url);
    }
};

onMounted(() => {
    init();
});

onBeforeUnmount(() => {
    if (videoElementRef.value) {
        videoElementRef.value.pause();
        videoElementRef.value = null;
    }
});

defineExpose({ init });
</script>

<style scoped lang="less">
.message-content-video {
    position: relative;
    display: flex;
    align-items: flex-end;
    max-width: 320px;

    .upload-cancel,
    .upload-retry {
        margin-left: 10px;
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

    /* 上传中 */
    .video-upload-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: var(--color-bg-1);
        border-radius: var(--base-radius-default);

        .video-icon {
            font-size: 24px;
            color: var(--color-text-2);
        }

        .video-upload-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;

            .uploading-text {
                font-size: 12px;
                color: var(--color-text-3);
            }
        }
    }

    /* 上传失败 */
    .video-failed-wrapper {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: #fef0f0;
        border: 1px solid #fde2e2;
        border-radius: var(--base-radius-default);

        .failed-icon {
            font-size: 20px;
            color: #f56c6c;
        }

        .failed-text {
            font-size: 12px;
            color: #f56c6c;
        }
    }

    /* 上传成功 */
    .video-success-wrapper {
        position: relative;
        display: inline-block;
        border-radius: var(--base-radius-default);
        overflow: hidden;
        background: var(--color-bg-1);

        .video-player {
            display: block;
            max-width: 320px;
            max-height: 240px;
            border-radius: var(--base-radius-default);
            cursor: pointer;
        }

        .video-play-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.3);
            cursor: pointer;

            .play-icon {
                font-size: 40px;
                color: #fff;
                opacity: 0.9;
            }
        }

        .video-duration-tag {
            position: absolute;
            bottom: 8px;
            left: 8px;
            padding: 2px 6px;
            font-size: 11px;
            color: #fff;
            background: rgba(0, 0, 0, 0.55);
            border-radius: 4px;
            pointer-events: none;
            user-select: none;
        }

        .video-size-tag {
            position: absolute;
            bottom: 8px;
            right: 8px;
            padding: 2px 6px;
            font-size: 11px;
            color: #fff;
            background: rgba(0, 0, 0, 0.55);
            border-radius: 4px;
            pointer-events: none;
            user-select: none;
        }
    }
}
</style>