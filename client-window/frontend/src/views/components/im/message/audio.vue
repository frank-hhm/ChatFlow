<template>
    <div class="message-content-audio" :class="[
        `sender-type-${messageObj.sender_type}`
    ]">
        <div class="upload-cancel icon icon-cuo" v-if="messageObj.uploadStatus === 'uploading'"
            @click="cancelUpload(messageObj.id)">
        </div>
        <div class="upload-retry icon icon-shuaxin" v-if="messageObj.uploadStatus === 'failed'"
            @click="retryUpload(msg)">
        </div>
        <div class="message-content-audio-box" v-if="true">

            <div class="audio-box" @click.stop="togglePlay">

                <!-- 上传中 -->
                <div v-if="messageObj.uploadStatus === 'uploading' || messageObj.uploadStatus === 'failed'"
                    class="audio-upload-wrapper">
                    <div class="audio-icon icon icon-yinpin"></div>
                    <div class="audio-uploading" v-if="messageObj.uploadStatus === 'uploading'">
                        <div class="audio-progress">{{ parseFloat(messageObj.progress || 0) }}%</div>
                        <div class="uploading-text">上传中</div>
                    </div>
                    <div class="audio-upload-error" v-if="messageObj.uploadStatus === 'failed'">
                        <div class="uploading-text">上传失败</div>
                    </div>
                </div>

                <!-- SVG 结构 -->
                <svg class="sound-icon" :class="{ 'animation': isPlaying }" viewBox="0 0 100 100">
                    <!-- 左侧的实心点 (使用 fill="currentColor" 继承父级颜色) -->
                    <circle cx="25" cy="50" r="7" fill="currentColor" stroke="none" />

                    <!-- 右侧的三条弧线（整体左移，间距缩小） -->
                    <!-- 第一条弧线：起点 x=40，半径 18 -->
                    <path class="wave wave-1" d="M 40,36 A 18,18 0 0,1 40,64" />

                    <!-- 第二条弧线：起点 x=50，半径 28 -->
                    <path class="wave wave-2" d="M 50,28 A 28,28 0 0,1 50,72" />

                    <!-- 第三条弧线：起点 x=60，半径 38 -->
                    <!-- <path class="wave wave-3" d="M 60,18 A 38,38 0 0,1 60,82" /> -->
                </svg>
                <div class="audio-time">{{ durationText }}</div>
            </div>
            <div class="audio-dot" v-if="messageObj.sender_type == 'visitor' && messageObj.is_read == 0"></div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = withDefaults(
    defineProps<{
        msg?: Object | any;
    }>(),
    {
        msg: () => ({}),
    }
);

const messageObj = ref<any>(props.msg);

const emit = defineEmits(['change']);

/* ================= 音频播放逻辑 ================= */
const audioElement = ref<HTMLAudioElement | null>(null);
const isPlaying = ref<boolean>(false);
const currentTime = ref<Number | any>(0);
const duration = ref<Number | any>(0);

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
        audioElement.value.currentTime = 0
        nextTick(() => {
            isPlaying.value = false;
        });
    } else {
        emit('change', { event: 'read', msgId: messageObj.value.id || 0 });
        isPlaying.value = true;
        audioElement.value.play();
    }
};

const onTimeUpdate = () => {
    currentTime.value = audioElement.value?.currentTime || 0;
};

const onLoadedMetadata = () => {
    duration.value = audioElement.value?.duration || 0;
};

const onPlay = () => {
    console.log('onPlay');
};

const onEnded = () => {
    isPlaying.value = false;
    currentTime.value = 0;
};

/* 新增：监听 pause 事件，确保浏览器主动暂停时状态同步 */
const onPause = () => {
    console.log('onPause');
    // isPlaying.value = false;
};

const onError = () => {
    console.log('onError');
}

const onCanplaythrough = () => {
    console.log('onCanplaythrough');
}

/* 修改点：时间格式改为 X秒 */
const formatTime = (seconds: number) => {
    if (!seconds || isNaN(seconds)) return '0s';
    const totalSecs = Math.floor(seconds);
    return totalSecs + 's';
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
    messageObj.value = props.msg;
    if (url) {
        // 销毁旧音频实例
        if (audioElement.value) {
            audioElement.value.pause();
            audioElement.value.removeEventListener('timeupdate', onTimeUpdate);
            audioElement.value.removeEventListener('loadedmetadata', onLoadedMetadata);
            audioElement.value.removeEventListener('play', onPlay);
            audioElement.value.removeEventListener('ended', onEnded);
            audioElement.value.removeEventListener('pause', onPause);
            audioElement.value.removeEventListener('canplaythrough', onCanplaythrough);
            audioElement.value.removeEventListener('error', onError);
            audioElement.value = null;
        }
        const audio = new Audio(url);
        audio.addEventListener('timeupdate', onTimeUpdate);
        audio.addEventListener('loadedmetadata', onLoadedMetadata);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('play', onPlay);
        audio.addEventListener('pause', onPause);
        audio.addEventListener('canplaythrough', onCanplaythrough)
        audio.addEventListener('error', onError)
        audio.play().catch((err) => {
            // console.error('播放失败', err)
        })
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
    align-items: center;
    justify-content: flex-end;
    width: 360px;

    .upload-cancel,
    .upload-retry {
        margin-right: 10px;
        height: 20px;
        line-height: 20px;
        width: 20px;
        font-size: 12px;
        text-align: center;
        color: #000;
        background: #fff;
        border-radius: 20px;
        cursor: pointer;
        user-select: none;

        &:hover {}
    }

    .message-content-audio-box {
        max-width: 60%;
        border-radius: var(--base-radius-default);
        display: flex;
        align-items: center;
        gap: 10px;

        .audio-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: var(--base-size-small);
            padding: 6px 12px;
            min-width: 80px;
            background: var(--color-bg-1);
            border-radius: var(--base-radius-default);
            user-select: none;
            cursor: pointer;
            position: relative;
        }

        .audio-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: red;
        }
    }

    &.sender-type-visitor {
        justify-content: flex-start;
    }

    &.sender-type-client {
        .message-content-audio-box {
            .audio-box {
                order: 2;

                .audio-time {
                    order: 2;
                }

                .sound-icon {
                    order: 3;
                    transform: scaleX(-1);
                }

                /* 上传失败 */
                .audio-failed {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: rgba(255, 255, 255, 0.8);
                    border-radius: var(--base-radius-default);
                    position: absolute;
                    z-index: 2;
                    width: 100%;
                    height: 100%;
                    left: 0;
                    top: 0;

                    .failed-icon {
                        font-size: var(--base-size-default);
                        color: #f56c6c;
                    }

                    .failed-text {
                        margin-left: 4px;
                        font-size: var(--base-size-small);
                        color: #f56c6c;
                    }
                }

            }

            .audio-dot {
                order: 1;
            }
        }
    }

    /* 上传中 */
    .audio-upload-wrapper {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 12px;
        background: var(--color-bg-1);
        border-radius: var(--base-radius-default);
        position: absolute;
        z-index: 1;
        width: calc(100% - 24px);
        height: 100%;
        left: 0;
        top: 0;

        .audio-icon {
            font-size: 24px;
            height: 24px;
            line-height: 24px;
            color: var(--color-text-1);
        }

        .audio-uploading {
            text-align: right;
            color: var(--color-text-3);
            font-size: var(--base-size-small);

            .audio-progress {}

            .uploading-text {}
        }

        .audio-upload-error {
            text-align: right;
            color: #f56c6c;

            .uploading-text {
                font-size: var(--base-size-small);
            }
        }
    }
}


/* 设置图标的基础大小和颜色 */
.sound-icon {
    width: 30px;
    /* 控制图标大小 */
    height: 30px;
    fill: none;
    /* 不需要填充颜色 */
    stroke: #333;
    /* 线条颜色 */
    stroke-width: 6px;
    /* 线条粗细 */
    stroke-linecap: round;
    /* 让线条两端变得圆润 */
    stroke-linejoin: round;
}

/* 基础动画类 */
.wave {
    fill: none;
    /* 弧线内部不填充颜色 */
    /* 周长设置为 15，配合 offset: 15 初始可以完全隐藏 */
    stroke-dasharray: 30;
    stroke-dashoffset: 30;
}

/* 定义弧线扩散的动画 */
@keyframes sound-wave {
    0% {
        stroke-dashoffset: 0;
        opacity: 0;
        /* 初始不可见 */
    }

    20% {
        opacity: 1;
        /* 迅速显现 */
    }

    100% {
        stroke-dashoffset: 0;
        /* 线条完全展开 */
        opacity: 0;
        /* 最终消失 */
    }
}

/* 第一圈弧线 */
.wave-1 {
    stroke-dashoffset: 0;
    /* 设置周长，需与 offset 匹配 */
}

.sound-icon.animation {
    .wave-1 {
        stroke-dasharray: 50;
        animation: sound-wave 1.5s ease-in-out infinite;
    }

    .wave-2 {
        stroke-dasharray: 80;
        stroke-dashoffset: 80;
        animation: sound-wave 1.5s ease-in-out 0.2s infinite;
    }
}

.wave-2 {

    stroke-dasharray: 0;
    stroke-dashoffset: 0;

    /* 初始偏移量等于周长，使其隐藏 */
}

/* 第三圈弧线 - 延迟 0.4秒 */
// .wave-3 {
//     stroke-dasharray: 110;
//     stroke-dashoffset: 110;
//     animation: sound-wave 1.5s ease-in-out 0.4s infinite;
// }
</style>