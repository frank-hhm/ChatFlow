<template>
    <div class="message-content-video">
        <!-- ① 自己发送：上传中（微信式：缩略图 + 环形进度百分比） -->
        <div v-if="isSelf && msg.uploadStatus === 'uploading'" class="video-box">
            <video class="video-cover" :src="coverUrl" muted preload="metadata" @loadedmetadata="onMetadata" />
            <div class="mask">
                <a-progress type="circle" :percent="uploadPercent / 100" :width="56" :stroke-width="5"
                    :color="'#ffffff'" :trail-color="'rgba(255,255,255,0.25)'" />
            </div>
            <div class="cancel-btn icon icon-cuo" @click="cancelUpload(msg.id)"></div>
        </div>

        <!-- ② 自己发送：失败（红点，点击重试） -->
        <div v-else-if="isSelf && msg.uploadStatus === 'failed'" class="video-box">
            <video class="video-cover" :src="coverUrl" muted preload="metadata" @loadedmetadata="onMetadata" />
            <div class="mask mask-click" @click="retryUpload(msg)">
                <div class="failed-icon icon icon-tishi"></div>
                <span class="mask-text">发送失败，点击重试</span>
            </div>
        </div>

        <!-- ③ 就绪：接收的消息 / 自己已发送成功 —— 点击才下载，下载中显示进度，完成就地播放 -->
        <div v-else class="video-box">
            <!-- 已下载：就地播放 -->
            <video v-if="downloadState === 'done' && objectUrl" class="video-player" :src="objectUrl" controls
                autoplay playsinline @loadedmetadata="onMetadata" @error="onPlayError"></video>

            <!-- 未下载：封面 + 中央按钮（微信：不自动下载） -->
            <template v-else>
                <video ref="videoCoverRef" class="video-cover" :src="coverUrl" muted preload="metadata"
                    @loadedmetadata="onMetadata" @error="onCoverError" />
                <div class="center-overlay" @click="startPlayOrDownload">
                    <!-- 下载中：环形进度 -->
                    <a-progress v-if="downloadState === 'downloading'" type="circle" :percent="downloadPercent / 100"
                        :width="56" :stroke-width="5" :color="'#ffffff'" :trail-color="'rgba(255,255,255,0.25)'" />
                    <!-- 下载失败：重试 -->
                    <template v-else-if="downloadState === 'error'">
                        <div class="failed-icon icon icon-tishi"></div>
                    </template>
                    <!-- 待下载：播放/下载按钮 -->
                    <div v-else class="play-btn icon icon-bofang"></div>
                </div>
                <!-- 接收提示（微信在角落显示大小/时长；这里显示时长） -->
            </template>

            <span v-if="durationText" class="video-duration-tag">{{ durationText }}</span>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        msg?: any;
    }>(),
    { msg: () => ({}) }
);

const emit = defineEmits(['change']);

/* ================= 基础判定 ================= */
const isSelf = computed(() => props.msg.sender_type === 'client');
const remoteUrl = computed(() => props.msg.file_url || props.msg.content || '');

/* ================= 发送进度 ================= */
const uploadPercent = computed(() => {
    const p = Number(props.msg.progress || 0);
    return Math.max(0, Math.min(100, Math.round(p)));
});

/* ================= 封面 ================= */
// 自己发送时优先用本地文件即时生成封面/预览；接收时使用远程地址（仅预载首帧）
const selfFileUrl = ref<string>('');
const coverUrl = computed(() => selfFileUrl.value || remoteUrl.value);

const videoCoverRef = ref<HTMLVideoElement | null>(null);
const duration = ref<number>(0);
const durationText = computed(() => formatTime(duration.value));
const onMetadata = (e?: any) => {
    const el = (e && e.target) || videoCoverRef.value;
    if (el && el.duration && !isNaN(el.duration)) {
        duration.value = el.duration;
    } else if (!duration.value && props.msg.duration) {
        duration.value = props.msg.duration;
    }
};
const onCoverError = () => {
    // 封面加载失败（远程不可达）时保持占位，不影响点击下载
};
const onPlayError = () => {
    console.error('视频播放失败', remoteUrl.value);
};

const formatTime = (seconds: number) => {
    if (!seconds || isNaN(seconds)) return '';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
};

/* ================= 接收下载状态机（模仿微信：点击才下载 + 进度） ================= */
type DownloadState = 'idle' | 'downloading' | 'done' | 'error';
const downloadState = ref<DownloadState>('idle');
const downloadPercent = ref<number>(0);
const objectUrl = ref<string>('');

const startPlayOrDownload = async () => {
    // 自己发送成功且有本地文件：直接本地播放，无需下载
    if (isSelf.value && selfFileUrl.value) {
        objectUrl.value = selfFileUrl.value;
        downloadState.value = 'done';
        return;
    }
    if (!remoteUrl.value) return;

    downloadState.value = 'downloading';
    downloadPercent.value = 0;

    try {
        const resp = await fetch(remoteUrl.value);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);

        const total = Number(resp.headers.get('content-length')) || 0;

        // 无流（旧环境）降级：整体读取
        if (!resp.body) {
            const blob = await resp.blob();
            objectUrl.value = URL.createObjectURL(blob);
            downloadPercent.value = 100;
            downloadState.value = 'done';
            return;
        }

        const reader = resp.body.getReader();
        const chunks: Uint8Array[] = [];
        let received = 0;
        // eslint-disable-next-line no-constant-condition
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            if (value) {
                chunks.push(value);
                received += value.length;
                if (total) {
                    // 预留 100% 给拼装完成，避免“假到 100  yet 仍在拼”
                    downloadPercent.value = Math.min(99, Math.floor((received / total) * 100));
                }
            }
        }

        const mime = resp.headers.get('content-type') || 'video/mp4';
        const blob = new Blob(chunks as unknown as BlobPart[], { type: mime });
        objectUrl.value = URL.createObjectURL(blob);
        downloadPercent.value = 100;
        downloadState.value = 'done';
    } catch (e) {
        console.error('视频下载失败', e);
        downloadState.value = 'error';
    }
};

/* ================= 发送控制 ================= */
const cancelUpload = (msgId: any) => {
    emit('change', { event: 'cancel', msgId });
};
const retryUpload = (msg: any) => {
    emit('change', { event: 'retry', msgId: msg.id });
};

/* ================= 生命周期 ================= */
// 兼容 index.vue 成功回调中的 init() 调用
const init = () => { };

onMounted(() => {
    if (isSelf.value && props.msg.file) {
        try {
            selfFileUrl.value = URL.createObjectURL(props.msg.file);
        } catch (e) {
            /* ignore */
        }
    }
});

onBeforeUnmount(() => {
    if (videoCoverRef.value) {
        videoCoverRef.value.pause?.();
    }
    if (selfFileUrl.value) URL.revokeObjectURL(selfFileUrl.value);
    if (objectUrl.value && objectUrl.value !== selfFileUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
    }
});

defineExpose({ init });
</script>

<style scoped lang="less">
.message-content-video {
    position: relative;
    display: flex;
    align-items: flex-end;

    .video-box {
        position: relative;
        width: 180px;
        max-width: 320px;
        min-height: 100px;
        border-radius: var(--base-radius-default);
        overflow: hidden;
        background: #000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .video-cover,
    .video-player {
        display: block;
        width: 100%;
        max-height: 240px;
        object-fit: cover;
    }

    /* 上传中/失败的半透明遮罩 */
    .mask {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        background: rgba(0, 0, 0, 0.4);

        &.mask-click {
            cursor: pointer;
        }
    }

    .mask-text {
        color: #fff;
        font-size: 12px;
    }

    .failed-icon {
        font-size: 28px;
        color: #f56c6c;
    }

    /* 取消按钮（右上角） */
    .cancel-btn {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 22px;
        height: 22px;
        line-height: 22px;
        text-align: center;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.45);
        color: #fff;
        font-size: 12px;
        cursor: pointer;
        user-select: none;

        &:hover {
            background: rgba(0, 0, 0, 0.7);
        }
    }

    /* 未下载时的中央按钮 */
    .center-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        background: rgba(0, 0, 0, 0.15);
    }

    .play-btn {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.5);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }

    /* 时长标签 */
    .video-duration-tag {
        position: absolute;
        right: 8px;
        bottom: 8px;
        padding: 2px 6px;
        font-size: 11px;
        color: #fff;
        background: rgba(0, 0, 0, 0.55);
        border-radius: 4px;
        pointer-events: none;
        user-select: none;
    }
}
</style>
