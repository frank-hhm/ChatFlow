<template>
    <div class="page-container">
        <div class="page-container-left">
            <div class="conversation-item" :class="{ active: itemConversation.id === item.id }"
                v-for="(item, index) in conversationList" :key="item.id" @click="onSelectConversation(item)">
                <div class="item-left">
                    <a-avatar :size="40" shape="square">游客</a-avatar>
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

                    <div class="message-row" :class="msg.isSelf ? 'message-row-self' : 'message-row-other'">
                        <img v-if="!msg.isSelf" class="message-avatar" :src="msg.avatar" />
                        <div class="message-bubble">{{ msg.content }}</div>
                        <img v-if="msg.isSelf" class="message-avatar" :src="msg.avatar" />
                    </div>
                </div>
            </div>

            <div class="page-container-bottom" v-if="itemConversation">
                <a-popover trigger="click" position="tl" content-class="message-emoji-popover">
                    <div class="bottom-toolbar">
                        <div class="emoji-btn icon icon-biaoqing_3" :class="showEmojiPicker ? 'active' : ''"
                            @click="showEmojiPicker = !showEmojiPicker"></div>
                    </div>
                    <template #content>
                        <div class="emoji-picker">
                            <div class="emoji-item" v-for="emoji in emojiList" :key="emoji" @click="insertEmoji(emoji)">
                                {{ emoji }}
                            </div>
                        </div>
                    </template>
                </a-popover>
                <div class="bottom-input-wrap">

                    <a-textarea class="bottom-input-wrap-textarea" ref="textareaRef" v-model="newMessageText"
                        placeholder="输入消息，Enter 发送" :auto-size="{ minRows: 1, maxRows: 4 }" :max-length="200"
                        allow-clear show-word-limit @keydown.enter.exact="onSendMessage" @keydown.enter.exact.prevent />
                </div>

                <div class="send-message-btn icon icon-fabu" :class="{ disabled: !newMessageText.trim() }"
                    @click="onSendMessage"></div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { getConversationListApi, getMessageListApi } from "@/api/im";
import { useAppStore, useEnumStore, useWebsocketStore } from "@/store";
import { Result, ResultError } from "@/types";
import { nextTick, onMounted, ref, watch } from "vue";

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
    itemConversation.value = item;
    messagePage.value = 1;
    messageList.value = [];
    noMoreHistory.value = false;
    nextTick(() => {
        loadHistoryMessages();
    });
};

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
                isSelf: item.sender_type === "client",
                showTime: true,
                friendly_time: item.friendly_time,
                avatar:
                    item.sender_type === "client"
                        ? "https://i.pravatar.cc/150?img=10"
                        : item.avatar || "https://i.pravatar.cc/150?img=1",
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
const newMessageText = ref("");
const textareaRef = ref();

const onSendMessage = () => {
    const content = newMessageText.value.trim();
    if (!content || !itemConversation.value.id) return;

    const messageObj = {
        type: "client_reply",
        content,
        conversation_id: itemConversation.value.id,
    };

    useWebsocketStore().sendMessage(messageObj);

    // 本地先追加一条，体验更即时
    messageList.value.push({
        id: Date.now(),
        content,
        isSelf: true,
        showTime: false,
        friendly_time: "",
        avatar: "https://i.pravatar.cc/150?img=10",
    });

    newMessageText.value = "";
    showEmojiPicker.value = false;
    autoScrollBottom.value = true;
    scrollToBottom();
    initConversationList();
};

/* ================= 表情 ================= */
const showEmojiPicker = ref(false);

const emojiList = [
    "😀", "😁", "😂", "🤣", "😃", "😄", "😅", "😆",
    "😉", "😊", "😋", "😎", "😍", "😘", "🥰", "😗",
    "🤔", "🤨", "😐", "😑", "😶", "🙄", "😏", "😣",
    "😥", "😮", "🤐", "😯", "😪", "😫", "😴", "😌",
    "😛", "😜", "😝", "🤤", "😒", "😓", "😔", "😕",
    "🙃", "🤑", "😲", "☹️", "🙁", "😖", "😞", "😟",
    "😤", "😢", "😭", "😦", "😧", "😨", "😩", "🤯",
    "😬", "😰", "😱", "🥵", "🥶", "😳", "🤪", "😵",
    "🥴", "😠", "😡", "🤬", "😷", "🤒", "🤕", "🤢",
    "👍", "👎", "👏", "🙏", "💪", "❤️", "💔", "🔥",
    "⭐", "🎉", "🎊", "✅", "❌", "❓", "💬", "👀",
];

const insertEmoji = (emoji: string) => {
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

onMounted(() => {
    initConversationList(true);
});
</script>
<style lang="less">
.message-emoji-popover {
    padding: 6px 0 6px 6px;

    .emoji-picker {
        width: 320px;
        max-height: 200px;
        overflow-y: auto;
        border-radius: var(--base-radius-default);
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        z-index: 10;
    }

    .emoji-item {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        cursor: pointer;
        border-radius: 4px;

        &:hover {
            background: var(--color-fill-2);
        }
    }
}

.bottom-input-wrap {
    textarea {
        line-height: 27px !important;
    }
}
</style>
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
            overflow: hidden;

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
        flex-direction: row-reverse;
    }

    .message-avatar {
        width: 36px;
        height: 36px;
        border-radius: var(--base-radius-small);
        background: rgba(0, 0, 0, 0.1);
    }

    .message-bubble {
        max-width: 60%;
        padding: 8px 12px;
        border-radius: var(--base-radius-default);
        background: var(--color-bg-1);
        word-break: break-word;
        white-space: pre-wrap;
    }

    .message-row-self .message-bubble {
        background: var(--color-primary-light-1);
        color: var(--color-primary);
    }

    /* ================= 底部输入区 ================= */
    .page-container-bottom {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        padding: 10px;
        border-top: 1px solid var(--color-border-1);
        background: var(--color-bg-1);
        position: relative;
    }

    .bottom-toolbar {
        display: flex;
        align-items: center;
    }

    .emoji-btn {
        width: 35px;
        height: 35px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-radius: var(--base-radius-small);
        font-size: 24px;

        &:hover {
            background: var(--color-fill-2);
        }
    }

    .bottom-input-wrap {
        flex: 1;
        display: flex;
        align-items: baseline;
        position: relative;

        .bottom-input-wrap-textarea {
            textarea {
                line-height: 27px;
            }
        }
    }

    .send-message-btn {
        width: 35px;
        height: 35px;
        text-align: center;
        line-height: 35px;
        background: rgba(0, 0, 0, 0.1);
        border-radius: var(--base-radius-small);
        cursor: pointer;

        &.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        &:hover:not(.disabled) {
            background: var(--color-fill-2);
        }
    }
}
</style>