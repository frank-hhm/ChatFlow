<template>
    <div class="message-content-audio">
        <div class="upload-cancel icon icon-cuo" v-if="msg.uploadStatus === 'uploading'" @click="cancelUpload(msg.id)">
        </div>
        <div class="upload-retry icon icon-shuaxin" v-if="msg.uploadStatus === 'failed'" @click="retryUpload(msg)">
        </div>
        <div class="message-content-audio-box">
            <div v-if="true" class="audio-success-wrapper">
                <div class="audio-progress-wrap">
                    <div class="icon icon-yinpin"></div>
                    <div class="audio-progress-bar">
                        <div class="audio-progress-fill" :style="{ width: progressPercent + '%' }"></div>
                    </div>
                    <div class="audio-time">{{ currentTimeText }} / {{ durationText }}</div>
                </div>
            </div>


            <div v-else>
                <!-- 上传中 -->
                <div v-if="msg.uploadStatus === 'uploading'" class="audio-upload-wrapper">
                    <div class="audio-icon icon icon-yinpin"></div>
                    <div class="audio-upload-info">
                        <!-- <a-progress type="circle" status="warning" :percent="parseFloat(msg.progress || 0) / 100" /> -->
                        <a-progress :percent="parseFloat(msg.progress || 0) / 100" />
                        <span class="uploading-text">上传中...</span>
                    </div>
                </div>
                <!-- 上传失败 -->
                <div v-else-if="msg.uploadStatus === 'failed'" class="audio-failed-wrapper">
                    <div class="failed-icon icon icon-tishi"></div>
                    <div class="failed-text">上传失败</div>
                </div>
                <!-- 上传成功 -->
                <div v-else class="audio-success-wrapper">
                    <div class="audio-player" @click="togglePlay">
                        <div class="audio-icon" :class="{ playing: isPlaying }">
                            <span v-if="!isPlaying" class="icon icon-bofang"></span>
                            <span v-else class="icon icon-zanting"></span>
                        </div>
                        <div class="audio-progress-wrap">
                            <div class="audio-progress-bar">
                                <div class="audio-progress-fill" :style="{ width: progressPercent + '%' }"></div>
                            </div>
                            <div class="audio-time">{{ currentTimeText }} / {{ durationText }}</div>
                        </div>
                    </div>
                    <span v-if="fileSizeText" class="audio-size-tag">{{ fileSizeText }}</span>
                </div>
            </div>

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

/* ================= 音频播放逻辑 ================= */
const audioElement = ref<HTMLAudioElement | null>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(0);

const progressPercent = computed(() => {
    if (!duration.value) return 0;
    return (currentTime.value / duration.value) * 100;
});

const currentTimeText = computed(() => formatTime(currentTime.value));
const durationText = computed(() => formatTime(duration.value));

const togglePlay = () => {
    if (!audioElement.value) return;
    if (isPlaying.value) {
        audioElement.value.pause();
    } else {
        audioElement.value.play();
    }
};

const onTimeUpdate = () => {
    currentTime.value = audioElement.value?.currentTime || 0;
};

const onLoadedMetadata = () => {
    duration.value = audioElement.value?.duration || 0;
};

const onEnded = () => {
    isPlaying.value = false;
    currentTime.value = 0;
};

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
            console.warn('获取音频大小失败', url, e2);
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
    const url = props.msg.content;
    if (url) {
        // 销毁旧音频实例
        if (audioElement.value) {
            audioElement.value.pause();
            audioElement.value.removeEventListener('timeupdate', onTimeUpdate);
            audioElement.value.removeEventListener('loadedmetadata', onLoadedMetadata);
            audioElement.value.removeEventListener('ended', onEnded);
            audioElement.value = null;
        }
        const audio = new Audio(url);
        audio.addEventListener('timeupdate', onTimeUpdate);
        audio.addEventListener('loadedmetadata', onLoadedMetadata);
        audio.addEventListener('ended', onEnded);
        audioElement.value = audio;

        // 获取文件大小
        fetchFileSize(url);
    }
};

onMounted(() => {
    init();
});

onBeforeUnmount(() => {
    if (audioElement.value) {
        audioElement.value.pause();
        audioElement.value.removeEventListener('timeupdate', onTimeUpdate);
        audioElement.value.removeEventListener('loadedmetadata', onLoadedMetadata);
        audioElement.value.removeEventListener('ended', onEnded);
        audioElement.value = null;
    }
});

defineExpose({ init });
</script>

<style scoped lang="less">
.message-content-audio {
    position: relative;
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    width: 360px;

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

    .message-content-audio-box {
        max-width: 60%;
        border-radius: var(--base-radius-default);
        display: inline-block;
    }

    /* 上传中 */
    .audio-upload-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: var(--color-bg-1);
        border-radius: var(--base-radius-default);

        .audio-icon {
            font-size: 24px;
            color: var(--color-text-2);
        }

        .audio-upload-info {
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
    .audio-failed-wrapper {
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
    .audio-success-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: var(--color-bg-1);
        border-radius: var(--base-radius-default);

        .audio-player {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            cursor: pointer;

            .audio-icon {
                font-size: 24px;
                color: var(--color-text-2);
                transition: color 0.2s;

                &.playing {
                    color: var(--color-primary);
                }
            }

            .audio-progress-wrap {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 2px;

                .audio-progress-bar {
                    width: 100%;
                    height: 4px;
                    background: var(--color-fill-3);
                    border-radius: 2px;
                    overflow: hidden;

                    .audio-progress-fill {
                        height: 100%;
                        background: var(--color-primary);
                        border-radius: 2px;
                        transition: width 0.1s linear;
                    }
                }

                .audio-time {
                    font-size: 11px;
                    color: var(--color-text-3);
                }
            }
        }

        .audio-size-tag {
            font-size: 11px;
            color: var(--color-text-3);
            white-space: nowrap;
        }
    }
}
</style>