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
                        <div class="item-name">{{ item.visitor_id }}</div>
                        <div class="item-time">{{ item.friendly_time }}</div>
                    </div>
                    <div class="item-content">{{ item.last_message }}</div>
                </div>
            </div>
        </div>
        <div class="conversation-refresh-btn icon icon-shuaxin" @click="onRefresh"></div>

        <div class="page-container-right">
            <!-- <input ref="imageInputRef" type="file" accept="image/*" multiple style="display: none;"
                @change="onImageSelect" /> -->
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
                        <a-avatar v-if="msg.sender_type !== 'client'" class="message-avatar" :size="36">游客</a-avatar>

                        <!-- 图片消息 -->
                        <imMessageImageComponent :ref="`imMessageImageComponentRef${msg.id}`"
                            v-if="msg.msg_type == 'image'" :msg="msg" @change="onChangeMessageImage">
                        </imMessageImageComponent>

                        <!-- 音频消息 -->
                        <imMessageAudioComponent :ref="`imMessageAudioComponentRef${msg.id}`"
                            v-else-if="msg.msg_type === 'audio'" :msg="msg" @change="onChangeMessageAudio">
                        </imMessageAudioComponent>

                        <!-- 视频消息 -->
                        <imMessageVideoComponent :ref="`imMessageVideoComponentRef${msg.id}`"
                            v-else-if="msg.msg_type === 'video'" :msg="msg" @change="onChangeMessageVideo">
                        </imMessageVideoComponent>

                        <imMessageTextComponent v-else :content="msg.content" :sender="msg.sender_type">
                        </imMessageTextComponent>

                        <a-avatar v-if="msg.sender_type === 'client'" class="message-avatar" :size="36"
                            :imageUrl="msg.avatar"></a-avatar>
                    </div>
                </div>
            </div>

            <div class="page-container-bottom" v-if="itemConversation">
                <div class="bottom-toolbar">
                    <div class="toolbar-item">
                        <imToolbarEmojiPickerComponent ref="imToolbarEmojiPickerComponentRef"
                            @change="onChangeToolbarEmoji" />
                    </div>
                    <div class="toolbar-item">
                        <imToolbarImageComponent ref="imToolbarImageComponentRef" @change="onChangeToolbarImage" />
                    </div>
                    <!-- 音频工具 -->
                    <div class="toolbar-item">
                        <imToolbarAudioComponent ref="imToolbarAudioComponentRef" @change="onChangeToolbarAudio" />
                    </div>
                    <!-- 视频工具 -->
                    <div class="toolbar-item">
                        <imToolbarVideoComponent ref="imToolbarVideoComponentRef" @change="onChangeToolbarVideo" />
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
import imToolbarImageComponent from "@/views/components/im/toolbar/image.vue"
import imMessageTextComponent from "@/views/components/im/message/text.vue"
import imMessageImageComponent from "@/views/components/im/message/image.vue"
import imToolbarAudioComponent from "@/views/components/im/toolbar/audio.vue"
import imToolbarVideoComponent from "@/views/components/im/toolbar/video.vue"
import imMessageAudioComponent from "@/views/components/im/message/audio.vue"
import imMessageVideoComponent from "@/views/components/im/message/video.vue"

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

/* ================= 监听会话盒子滚动 ================= */
const onMessageScroll = () => {
    const container = messageContainerRef.value;
    if (!container) return;

    if (container.scrollTop < 50) {
        loadHistoryMessages();
    }

    const { scrollTop, scrollHeight, clientHeight } = container;
    autoScrollBottom.value = scrollHeight - scrollTop - clientHeight < 80;
};

/* ================= 会话盒子滚动到底部 ================= */
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

    messageList.value.push(createMessageObject({
        content,
        msgType: "text",
        senderType: "client"
    }));

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




/**
 * 统一的消息对象工厂函数
 * @param {Object} options - 配置项
 * @param {string} options.msgType - 消息类型: 'text' | 'image' | 'file' | ...
 * @param {string} options.content - 文本内容（msgType 为 text 时使用）
 * @param {string} options.fileUrl - 文件地址（msgType 为 image/file 时使用）
 * @param {string} options.senderType - 发送方: 'client' | 'agent' | 'system'
 * @param {Object} options.extra - 额外字段，会直接合并到消息对象上
 * @param {Object} options.baseMsg - 基础消息对象（可选，有值时会以其为模板扩展）
 * @returns {Object} 消息对象
 */
const createMessageObject = ({
    msgType = 'text',
    content = '',
    fileUrl = null,
    file = null,
    senderType = 'client',
    extra = {},
    baseMsg = null,
}: any = {}) => {
    const msgObj = baseMsg ? { ...baseMsg } : {
        id: Date.now() + Math.random(),
    };
    msgObj.avatar = msgObj.avatar || accountInfo.value.avatar || '';
    msgObj.sender_type = senderType;
    msgObj.msg_type = msgType;
    msgObj.showTime = msgObj.showTime ?? true;

    if (msgType === 'text') {
        msgObj.content = content;
    } else {
        msgObj.file = file;
        msgObj.file_url = fileUrl;
        msgObj.content = msgObj.content ?? '';
    }
    console.log(msgObj)
    if (extra && typeof extra === 'object') {
        Object.assign(msgObj, extra);
    }

    return msgObj;
};


/* ================= 会话工具-事件回调 表情 ================= */
const onChangeToolbarEmoji = (emoji: String) => {
    newMessageText.value += emoji;
    textareaRef.value?.focus?.();
};


/* ================= 统一工具栏事件处理（图片/音频/视频共用） ================= */
/**
 * 创建工具栏事件处理函数
 * @param {string} msgType - 消息类型: 'image' | 'audio' | 'video'
 */
const createToolbarHandler = (msgType, callback: any | unknown = {
    successCallback: Function
}) => {
    return (res) => {
        if (!res.event) return;

        let msg;
        switch (res.event) {
            case "start":
                messageList.value.push(createMessageObject({
                    msgType,
                    senderType: "client",
                    file_url: res.file_url ?? "",
                    file: res.file ?? null,
                    content: res.content ?? "",
                    baseMsg: {
                        id: res.msgId || Date.now() + Math.random(),
                        avatar: accountInfo.value.avatar,
                        friendly_time: "刚刚",
                        uploadStatus: "uploading",
                        progress: 0,
                    }
                }));
                autoScrollBottom.value = true;
                scrollToBottom();
                break;

            case "progress":
                msg = messageList.value.find((m) => m.id === res.msgId);
                if (msg) msg.progress = Number(res.progress || 0);
                break;

            case "error":
                msg = messageList.value.find((m) => m.id === res.msgId);
                // return;
                if (msg) {
                    msg.uploadStatus = "failed";
                    msg.progress = 0;
                }
                break;

            case "cancel":
                messageList.value = messageList.value.filter((m) => m.id !== res.msgId);
                break;

            case "success":
                // return;
                // 兼容不同资源类型：imageUrl / audioUrl / videoUrl
                const url = res.result?.[msgType + 'Url'] || "";
                msg = messageList.value.find((m) => m.id === res.msgId);
                if (msg) {
                    msg.content = url;
                    msg.uploadStatus = "success";
                    msg.progress = 100;
                }
                // 图片类型需要初始化组件（获取文件大小等）
                if (callback && callback?.successCallback) {
                    callback.successCallback(res.msgId);
                }
                // 通过 WebSocket 发送消息
                useWebsocketStore().sendMessage({
                    type: "client_reply",
                    content: url,
                    conversation_id: itemConversation.value.id,
                    msg_type: msgType,
                });
                initConversationList();
                break;

        }
    };
};

/* ================= 统一消息事件处理（取消/重试 共用） ================= */
/**
 * 创建消息事件处理函数
 * @param {string} toolbarRefName - 工具栏组件 ref 名称
 * @returns {Function} 事件处理函数
 */
const createMessageHandler = (toolbarRefName, callback: any | unknown = {
    readCallback: Function
}) => {
    return (res) => {
        let msg: any = {};
        switch (res.event) {
            case "cancel":
                proxy?.$refs[toolbarRefName]?.cancelUpload(res.msgId);
                break;
            case "retry":
                msg = messageList.value.find((m) => m.id === res.msgId);
                console.log(msg)
                const file = msg?.file;
                if (!file) {
                    console.warn("文件已失效，请重新选择");
                    msg.uploadStatus = "failed";
                    return;
                }
                msg.uploadStatus = "uploading";
                msg.progress = 0;
                proxy?.$refs[toolbarRefName]?.retryUpload(msg.id, file);
                break;
            case "read":
                msg = messageList.value.find((m) => m.id === res.msgId);
                if (msg) {
                    msg.is_read = 1;
                }
                if (callback && callback?.readCallback) {
                    callback.readCallback(msg);
                }
                break;
        }
    };
};

/** 图片工具栏回调 */
const onChangeToolbarImage = createToolbarHandler("image", {
    successCallback: (msgId: String | Number | unknown) => {
        nextTick(() => {
            const refName = `imMessageImageComponentRef${msgId}`;
            if (proxy?.$refs[refName] && proxy?.$refs[refName][0]) {
                proxy?.$refs[refName][0].init();
            }
        });
    }
});

/** 音频工具栏回调 */
const onChangeToolbarAudio = createToolbarHandler("audio", {
    successCallback: (msgId: String | Number | unknown) => {
        nextTick(() => {
            const refName = `imMessageAudioComponentRef${msgId}`;
            if (proxy?.$refs[refName] && proxy?.$refs[refName][0]) {
                proxy?.$refs[refName][0].init();
            }
        });
    }
});

/** 视频工具栏回调 */
const onChangeToolbarVideo = createToolbarHandler("video", {
    successCallback: (msgId: String | Number | unknown) => {
        nextTick(() => {
            const refName = `imMessageVideoComponentRef${msgId}`;
            if (proxy?.$refs[refName] && proxy?.$refs[refName][0]) {
                proxy?.$refs[refName][0].init();
            }
        });
    }
});

/** 图片消息回调 */
const onChangeMessageImage = createMessageHandler("imToolbarImageComponentRef");

/** 音频消息回调 */
const onChangeMessageAudio = createMessageHandler("imToolbarAudioComponentRef", {
    readCallback: (msg: any) => {
        nextTick(() => {
            const refName = `imMessageAudioComponentRef${msg.id}`;
            if (proxy?.$refs[refName] && proxy?.$refs[refName][0]) {
                proxy?.$refs[refName][0].init(msg);
            }
        });
    }
});

/** 视频消息回调 */
const onChangeMessageVideo = createMessageHandler("imToolbarVideoComponentRef");

/* ================= 会话框的拖动事件 ================= */

const isDragOver = ref<boolean>(false)

const onDragOver = () => {
    isDragOver.value = true;
};

const onDragLeave = () => {
    isDragOver.value = false;
};
const onDrop = (event: any) => {
    proxy?.$refs["imToolbarImageComponentRef"]?.onDrop(event);

}


/* ================= 获取消息数 ================= */
const getMessageCount = ((item) => {
    if (item?.is_read == 0) {
        return item?.message_count;
    }
    return item?.new_message_count || 0;
});


/* =================创建 webSocket 监听 ================= */
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
/* ================= WebSocket 监听回调 消息处理 ================= */
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
                avatar: msg.avatar,
                last_message: msg.content,
                last_message_time: msg.created_at || msg.friendly_time,
                new_message_count: 1,
                status: "open",
            };
            conversationList.value.unshift(newConv);
        }

        if (itemConversation.value.id === convId) {
            messageList.value.push(createMessageObject({
                msgType: msg.msg_type || "text",
                senderType: msg.sender_type || "client",
                content: msg.content,
                baseMsg: msg
            }));
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

onBeforeUnmount(() => {
    if (messageTimer.value) {
        clearInterval(messageTimer.value)
        messageTimer.value = null
    }
    proxy?.$refs["imToolbarImageComponentRef"]?.beforeUnmount();
    proxy?.$refs["imToolbarAudioComponentRef"]?.beforeUnmount();
    proxy?.$refs["imToolbarVideoComponentRef"]?.beforeUnmount();
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

            .item-name {
                font-weight: bold;
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
        user-select: none;
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