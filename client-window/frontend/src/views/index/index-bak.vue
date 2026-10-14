<template>
    <div class="page-container">
        <div class="page-container-left">
            <div class="conversation-item" :class="{ active: itemConversation.id === item.id }"
                v-for="(item, index) in conversationList" :key="item.id" @click="onSelectConversation(item)">
                <div class="item-left">
                    <a-badge :count="getMessageCount(item) || 0">
                        <a-avatar :size="40" shape="square">游客</a-avatar>
                    </a-badge>
                </div>
                <div class="item-right">
                    <div class="item-right-top">
                        <div>{{ item.visitor_id }}</div>
                        <div class="item-time">{{ item.friendly_time }}</div>
                    </div>
                    <div class="item-content">{{ item.last_message }}</div>
                </div>
            </div>
        </div>
        <div class="conversation-refresh-btn icon icon-shuaxin" @click="onRefresh"></div>

        <div class="page-container-right">
            <input ref="imageInputRef" type="file" accept="image/*" multiple style="display: none;"
                @change="onImageSelect" />
            <div ref="messageContainerRef" class="page-container-message" @scroll="onMessageScroll">
                <div v-if="historyLoading" class="message-load-more">
                    加载中...
                </div>
                <div v-else-if="noMoreHistory" class="message-load-more">
                    没有更多消息了
                </div>

                <div class="message-item" v-for="msg in messageList" :key="msg.id">
                    <div class="message-time" v-if="msg.showTime">
                        {{ msg.friendly_time }}
                    </div>

                    <div class="message-row"
                        :class="msg.sender_type === 'client' ? 'message-row-self' : 'message-row-other'">

                        <a-avatar v-if="msg.sender_type !== 'client'" class="message-avatar" :size="32"
                            shape="square">游客</a-avatar>

                        <!-- 图片消息：上传中/失败/成功 三种状态 -->
                        <div class="message-content-image" v-if="msg.msg_type == 'image'">

                            <div class="upload-cancel icon icon-cuo" v-if="msg.uploadStatus === 'uploading'"
                                @click="cancelUpload(msg.id)"></div>
                            <div v-else-if="msg.uploadStatus === 'failed'" class="upload-retry icon icon-shuaxin"
                                @click="retryUpload(msg)"></div>

                            <div class="message-content-image-box">
                                <!-- 上传中 -->
                                <div v-if="msg.uploadStatus === 'uploading'" class="image-upload-wrapper">
                                    <!-- <div class="upload-progress-bg">
                                        <div class="upload-progress-bar" :style="{ width: msg.progress + '%' }"></div>
                                    </div> -->
                                    <!-- <span class="upload-text">{{ msg.progress }}%</span> -->
                                    <a-progress type="circle" status='warning'
                                        :percent="parseFloat(msg.progress) / 100" />
                                </div>
                                <!-- 上传失败 -->
                                <div v-else-if="msg.uploadStatus === 'failed'"
                                    class="image-upload-wrapper image-upload-failed">
                                    <div>
                                        <div class="failed-icon icon icon-tishi"></div>
                                        <div class="failed-text">上传失败</div>
                                    </div>
                                </div>
                                <!-- 上传成功 -->
                                <img v-else :src="msg.content" class="message-image" @error="handleImageError" />
                            </div>
                        </div>

                        <imMessageTextComponent v-else :content="msg.content" :sender="msg.sender_type">
                        </imMessageTextComponent>

                        <a-avatar v-if="msg.sender_type === 'client'" class="message-avatar" :size="32" shape="square"
                            :imageUrl="msg.avatar"></a-avatar>
                    </div>
                </div>
            </div>

            <div class="page-container-bottom" v-if="itemConversation">
                <div class="bottom-toolbar">
                    <div class="toolbar-item">
                        <imToolbarEmojiPickerComponent ref="imToolbarEmojiPickerComponentRef" @change="insertEmoji" />
                    </div>

                    <div class="toolbar-item" @click="triggerImageSelect">
                        <div class="icon icon-img"></div>
                    </div>
                </div>
                <div class="bottom-input-wrap" :class="{ 'drag-over': isDragOver }" @dragover.prevent="onDragOver"
                    @dragleave.prevent="onDragLeave" @drop.prevent="onDrop">
                    <textarea class="bottom-input-wrap-textarea" ref="textareaRef" v-model="newMessageText"
                        placeholder="输入消息，Enter 发送" @keydown.enter.exact="onSendMessage" @keydown.enter.exact.prevent />
                    <div class="send-message-btn" :class="{ disabled: !newMessageText.trim() }" @click="onSendMessage">
                        发送</div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup lang="ts">
import { getConversationListApi, getMessageListApi, readMessageApi, uploadMediaApi } from "@/api/im";
import { useAccountStore, useAppStore, useEnumStore, useWebsocketStore } from "@/store";
import { Result, ResultError } from "@/types";
import { storeToRefs } from "pinia";
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import imToolbarEmojiPickerComponent from "@/views/components/im/toolbar/emoji.vue"
import imMessageTextComponent from "@/views/components/im/message/text.vue"

const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const { accountInfo } = storeToRefs(useAccountStore());

/* ================= 会话列表 ================= */
const conversationList = ref<any[]>([]);
const initConversationList = (isInit = false) => {
    getConversationListApi({})
        .then((res: Result) => {
            conversationList.value = res.data.data;
            if (isInit) {
                onSelectConversation(conversationList.value[0])
            }
        })
        .catch((err: ResultError) => {
            console.error(err);
        });
};

const onRefresh = () => {
    initConversationList();
};

const itemConversation = ref<any>({});

const onSelectConversation = (item: any) => {
    item.new_message_count = 0;
    itemConversation.value = item;
    readMessage(item.id, item);
    messagePage.value = 1;
    messageList.value = [];
    noMoreHistory.value = false;
    nextTick(() => {
        loadHistoryMessages();
    });
};

const readMessage = (conversationId = 0, item: any = {}) => {
    readMessageApi({
        id: conversationId
    }).then((res: Result) => {
        item.is_read = 1;
    }).catch((err: ResultError) => {
        console.error(err);
    });
}

/* ================= 消息列表 ================= */
const messageList = ref<any[]>([]);
const messageContainerRef = ref<HTMLElement | null>(null);
const historyLoading = ref(false);
const noMoreHistory = ref(false);
const messagePage = ref(1);
const PAGE_SIZE = 20;
const autoScrollBottom = ref(true);

const loadHistoryMessages = async () => {
    if (historyLoading.value || noMoreHistory.value) return;
    if (!itemConversation.value.id) return;

    historyLoading.value = true;

    const container = messageContainerRef.value;
    const oldScrollHeight = container ? container.scrollHeight : 0;

    try {
        const res = await getMessageListApi({
            conversation_id: itemConversation.value.id,
            page: messagePage.value,
            limit: PAGE_SIZE,
        });

        const list: any[] = res.data?.data?.list ?? res.data?.data ?? [];

        if (list.length === 0) {
            noMoreHistory.value = true;
        } else {
            messagePage.value += 1;
            const enriched = list.map((item: any) => ({
                ...item,
                showTime: true,
                friendly_time: item.friendly_time
            }));

            messageList.value = [...enriched, ...messageList.value];

            nextTick(() => {
                if (container && oldScrollHeight > 0) {
                    container.scrollTop = container.scrollHeight - oldScrollHeight;
                } else {
                    scrollToBottom();
                }
            });
        }
    } catch (e) {
        console.error(e);
    } finally {
        historyLoading.value = false;
    }
};

const onMessageScroll = () => {
    const container = messageContainerRef.value;
    if (!container) return;

    if (container.scrollTop < 50) {
        loadHistoryMessages();
    }

    const { scrollTop, scrollHeight, clientHeight } = container;
    autoScrollBottom.value = scrollHeight - scrollTop - clientHeight < 80;
};

const scrollToBottom = () => {
    nextTick(() => {
        const container = messageContainerRef.value;
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    });
};

/* ================= 发送消息 ================= */
const newMessageText = ref<string>("");
const textareaRef = ref<HTMLElement>();
const imageInputRef = ref<HTMLElement>();
const isDragOver = ref<boolean>(false);

const onSendMessage = () => {
    const content = newMessageText.value.trim();
    if (!content || !itemConversation.value.id) return;

    const messageObj = {
        type: "client_reply",
        content,
        conversation_id: itemConversation.value.id,
        msg_type: "text"
    };

    useWebsocketStore().sendMessage(messageObj);

    messageList.value.push({
        id: Date.now(),
        content,
        showTime: true,
        friendly_time: "刚刚",
        avatar: accountInfo.value.avatar,
        sender_type: "client",
        msg_type: "text"
    });

    newMessageText.value = "";
    proxy.$refs["imToolbarEmojiPickerComponentRef"]?.close();
    autoScrollBottom.value = true;
    scrollToBottom();
    initConversationList();
    nextTick(() => {
        if (textareaRef.value) {
            textareaRef.value.style.height = '60px';
        }
    });
};

/* ================= 图片上传发送（先上传再发送） ================= */

/** 用 messageId 作为 key 存储 AbortController，用于取消上传 */
const uploadAbortControllers = ref<Record<number, AbortController>>({});

const triggerImageSelect = () => {
    imageInputRef.value?.click();
};

const onImageSelect = (event: any) => {
    const files = event.target.files;
    if (!files || files.length === 0) return;
    handleImageFiles(Array.from(files));
    event.target.value = "";
};

const onDragOver = () => {
    isDragOver.value = true;
};

const onDragLeave = () => {
    isDragOver.value = false;
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
 * 处理图片文件：先上传到服务器获取 URL，再将 URL 通过 WebSocket 发送
 * @param files 图片文件列表
 */
const handleImageFiles = async (files) => {
    for (const file of files) {
        // 1. 先生成本地消息（上传中状态）
        const msgId = Date.now() + Math.random();
        const tempMsg = {
            id: msgId,
            content: "",
            showTime: true,
            friendly_time: "刚刚",
            avatar: accountInfo.value.avatar,
            sender_type: "client",
            msg_type: "image",
            uploadStatus: "uploading",
            progress: 0,
            file: file,
        };
        messageList.value.push(tempMsg);
        autoScrollBottom.value = true;
        scrollToBottom();

        // 2. 执行上传（复用统一的上传逻辑）
        try {
            const result = await uploadImageFile(
                file,
                msgId,
                (percent) => {
                    const msg = messageList.value.find((m) => m.id === msgId);
                    if (msg) msg.progress = percent;
                }
            );

            const continueNext = handleUploadResult(msgId, result, "图片上传");
            if (continueNext) continue;
        } catch (err) {
            console.error("图片上传失败:", err);
            handleUploadFail(msgId);
        }
    }
};

/**
 * 取消指定消息的上传
 * @param msgId 消息 ID
 */
const cancelUpload = (msgId) => {
    const controller = uploadAbortControllers.value[msgId];
    if (controller) {
        controller.abort();
        delete uploadAbortControllers.value[msgId];
        messageList.value = messageList.value.filter((m) => m.id !== msgId);
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

const insertEmoji = (emoji) => {
    newMessageText.value += emoji;

    textareaRef.value?.focus?.();
};

/* ================= 监听新消息自动滚动 ================= */
watch(
    () => messageList.value.length,
    () => {
        if (autoScrollBottom.value) {
            scrollToBottom();
        }
    }
);
watch(
    () => newMessageText.value,
    () => {
        nextTick(() => {
            const textarea = textareaRef.value;
            if (textarea) {
                textarea.style.height = "auto";
                textarea.style.height = "calc(" + textarea.scrollHeight + "px - 20px)";
                textarea.scrollTop = textarea.scrollHeight;
            }
        });
    }
)

onMounted(() => {
    initConversationList(true);
    webSocketOpenCallback();
});

/* ================= WebSocket 消息处理 ================= */
const webSocketMessageCallback = ({ detail }) => {
    console.log("ws message:", detail);

    if (detail?.type === "new_message") {
        const msg = detail.data;
        const convId = msg.conversation_id;

        const convIndex = conversationList.value.findIndex(
            (c) => c.id === convId
        );

        if (convIndex !== -1) {
            conversationList.value[convIndex].last_message = msg.content;
            conversationList.value[convIndex].last_message_time = msg.create_time || msg.friendly_time;
            conversationList.value[convIndex].sender_type = "visitor";
            conversationList.value[convIndex].new_message_count =
                (conversationList.value[convIndex].new_message_count || 0) + 1;
            const conv = conversationList.value.splice(convIndex, 1)[0];
            conversationList.value.unshift(conv);
        } else {
            const newConv = {
                id: convId,
                visitor_id: msg.visitor_id,
                visitor_name: msg.visitor_name || msg.nick_name || "访客",
                avatar: msg.avatar || "https://i.pravatar.cc/150?img=10",
                last_message: msg.content,
                last_message_time: msg.created_at || msg.friendly_time,
                new_message_count: 1,
                status: "open",
            };
            conversationList.value.unshift(newConv);
        }

        if (itemConversation.value.id === convId) {
            const enriched = {
                ...msg,
                showTime: true,
                friendly_time: msg.friendly_time,
                avatar:
                    msg.sender_type === "client"
                        ? "https://i.pravatar.cc/150?img=10"
                        : msg.avatar || "https://i.pravatar.cc/150?img=1",
                msg_type: msg.msg_type || "text",
            };
            messageList.value.push(enriched);
            scrollToBottom();

            const target = conversationList.value.find(
                (c) => c.id === convId
            );
            if (target) {
                target.new_message_count = 0;
            }
        }
    }
};
const messageTimer = ref(null)

const webSocketOpenCallback = () => {
    if (useWebsocketStore().ws) {
        $utils.eventsListener("webSocketMessage", webSocketMessageCallback);
    } else {
        setTimeout(() => {
            if (messageTimer.value) {
                clearInterval(messageTimer.value)
                messageTimer.value = null
            }
            webSocketOpenCallback()
        }, 5000)
    }
}

const handleImageError = (event) => {
    event.target.style.display = "none";
};

const getMessageCount = ((item) => {
    if (item?.is_read == 0) {
        return item?.message_count;
    }
    return item?.new_message_count || 0;
});

onBeforeUnmount(() => {
    if (messageTimer.value) {
        clearInterval(messageTimer.value)
        messageTimer.value = null
    }
    Object.values(uploadAbortControllers.value).forEach(controller => controller.abort());
    uploadAbortControllers.value = {};
})
</script>
<style scoped lang="less">
.page-container {
    display: flex;
    height: 100vh;
    position: relative;
}


.conversation-refresh-btn {
    height: 20px;
    width: 20px;
    border-radius: 4px;
    text-align: center;
    line-height: 20px;
    position: absolute;
    bottom: 10px;
    left: 10px;
    cursor: pointer;
    background: rgba(0, 0, 0, 0.1);

    &:hover {
        background: var(--color-fill-2);
    }
}

/* ================= 左侧会话 ================= */
.page-container-left {
    position: relative;
    width: 260px;
    height: calc(100vh - 20px);
    overflow-y: scroll;
    border-right: 1px solid var(--color-border-1);
    padding: 10px;

    .conversation-item {
        width: calc(100% - 20px);
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: var(--color-text-1);
        height: 40px;
        margin-bottom: 10px;
        background: var(--color-bg-1);
        border-radius: var(--base-radius-default);
        padding: 10px;
        cursor: pointer;
        user-select: none;

        .item-left {
            height: 40px;
            width: 40px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: var(--base-radius-small);

            .item-avatr {
                height: 40px;
                width: 40px;
            }
        }

        .item-right {
            width: calc(100% - 50px);

            .item-content {
                margin-top: 5px;
                font-size: var(--base-size-small);
                color: var(--color-text-3);
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 1;
            }

            .item-right-top {
                display: flex;
                justify-content: space-between;
            }

            .item-time {
                font-size: var(--base-size-small);
                color: var(--color-text-3);
            }
        }

        &:hover {
            background: var(--color-fill-2);
        }

        &.active {
            background: var(--color-fill-2);
        }
    }
}

/* ================= 右侧消息区 ================= */
.page-container-right {
    width: calc(100% - 240px);
    height: 100vh;
    position: relative;
    display: flex;
    flex-direction: column;

    .page-container-message {
        flex: 1;
        overflow-y: auto;
        padding: 12px 16px;
        display: flex;
        flex-direction: column;
    }

    .message-load-more {
        text-align: center;
        font-size: 12px;
        color: var(--color-text-3);
        padding: 8px 0;
        user-select: none;
    }

    .message-item {
        margin-bottom: 12px;
    }

    .message-time {
        text-align: center;
        font-size: 12px;
        color: var(--color-text-3);
        margin-bottom: 6px;
    }

    .message-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }

    .message-row-self {
        justify-content: flex-end;
        // flex-direction: row-reverse;
    }

    .message-avatar {
        width: 36px;
        height: 36px;
        border-radius: var(--base-radius-small);
        background: rgba(0, 0, 0, 0.1);
    }

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
                transition: transform 0.2s;

                &:hover {
                    transform: scale(1.05);
                }
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
        }
    }



    /* ================= 底部输入区 ================= */
    .page-container-bottom {
        padding: 6px 10px 10px;
        border-top: 1px solid var(--color-border-1);
        background: var(--color-bg-1);
        position: relative;
    }

    .bottom-toolbar {
        display: flex;
        align-items: center;

        .toolbar-item {
            cursor: pointer;
            margin-right: 10px;

            .icon {
                font-size: var(--base-size-large);
            }
        }
    }

    .bottom-input-wrap {
        margin-top: 5px;
        flex: 1;
        position: relative;
        z-index: 1;

        textarea {
            width: calc(100% - 20px);
            min-height: 60px;
            max-height: 100px;
            line-height: 20px;
            font-size: 14px;
            resize: none;
            overflow-y: auto;
            background: var(--color-fill-2);
            border: none;
            padding: 10px;
            border-radius: var(--base-radius-default);
            z-index: 1;
            outline: none;
            -ms-overflow-style: none;
            /* IE / Edge */
            scrollbar-width: none;

            /* Firefox */
            :focus,
            :active {
                z-index: 1;
                border: none;
            }

            ::-webkit-scrollbar {
                display: none;
                /* Chrome / Safari / Opera */
            }

        }

        /* 拖拽高亮效果 */
        &.drag-over {
            background: var(--color-fill-3);
            border: 2px dashed var(--color-primary);
            border-radius: var(--base-radius-default);
        }

        .send-message-btn {
            position: absolute;
            z-index: 999;
            right: 10px;
            bottom: 10px;
            padding: 5px 20px;
            text-align: center;
            background: rgba(0, 0, 0, 1);
            color: #fff;
            border-radius: var(--base-radius-small);
            font-size: var(--base-fs-default);
            cursor: pointer;
            user-select: none;

            &.disabled {
                opacity: 0.1;
                cursor: not-allowed;
            }

            &:hover:not(.disabled) {}
        }
    }

}
</style>