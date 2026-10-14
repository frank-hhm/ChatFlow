<template>
    <div class="emoji-picker-wrapper">
        <div class="emoji-trigger" @click="togglePicker">
            <slot>
                <div class="emoji-btn icon icon-biaoqing_3"></div>
            </slot>
        </div>

        <transition name="emoji-fade">
            <div v-if="visible" class="emoji-popover" @click.stop>
                <div class="emoji-picker">
                    <div v-for="emoji in emojiList" :key="emoji" class="emoji-item" @click="handleSelect(emoji)">
                        {{ emoji }}
                    </div>
                </div>
                <div v-if="visible" class="emoji-close-btn icon icon-cuo" @click="close"></div>
            </div>
        </transition>

        <!-- 点击外部关闭 -->
        <!-- <transition name="emoji-fade">
            <div v-if="visible" class="emoji-overlay" @click="close"></div>
        </transition> -->
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';

const emit = defineEmits(['change']);

const visible = ref(false);

const emojiList = [
    '😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆',
    '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗',
    '🤔', '🤨', '😐', '😑', '😶', '🙄', '😏', '😣',
    '😥', '😮', '🤐', '😯', '😪', '😫', '😴', '😌',
    '😛', '😜', '😝', '🤤', '😒', '😓', '😔', '😕',
    '🙃', '🤑', '😲', '☹️', '🙁', '😖', '😞', '😟',
    '😤', '😢', '😭', '😦', '😧', '😨', '😩', '🤯',
    '😬', '😰', '😱', '🥵', '🥶', '😳', '🤪', '😵',
    '🥴', '😠', '😡', '🤬', '😷', '🤒', '🤕', '🤢',
    '👍', '👎', '👏', '🙏', '💪', '❤️', '💔', '🔥',
    '⭐', '🎉', '🎊', '✅', '❌', '❓', '💬', '👀',
];

const togglePicker = () => {
    visible.value = !visible.value;
};

const close = () => {
    visible.value = false;
};

const handleSelect = (emoji: string) => {
    emit('change', emoji);
};

// 点击页面其他地方关闭
const handleClickOutside = (e: MouseEvent) => {
    const wrapper = document.querySelector('.emoji-picker-wrapper');
    if (wrapper && !wrapper.contains(e.target as Node)) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});

defineExpose({ close });
</script>

<style lang="less" scoped>
.emoji-picker-wrapper {
    position: relative;
    display: inline-block;
}

.emoji-trigger {
    cursor: pointer;

    &:hover {
        .emoji-btn {
            opacity: 0.7;
        }
    }
}

.emoji-btn {
    font-size: var(--base-size-large);
    transition: opacity 0.2s;
}

/* popover 面板 - 从上方弹出 + 淡入 */
.emoji-popover {
    position: absolute;
    bottom: 100%;
    left: 0;
    margin-bottom: 8px;
    padding: 6px 0 6px 6px;
    background: var(--color-bg-1);
    border: 1px solid var(--color-border);
    border-radius: var(--base-radius-default);
    z-index: 1000;
    transform-origin: bottom left;
}

.emoji-picker {
    width: 320px;
    max-height: 216px;
    border-radius: var(--base-radius-default);
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    overflow-y: auto;
}

.emoji-item {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    cursor: pointer;
    border-radius: 4px;
    user-select: none;
    transition: background-color 0.15s, transform 0.15s;

    &:hover {
        background: var(--color-fill-2);
    }
}

/* 遮罩层 */
.emoji-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 999;
}

/* 动画过渡 */
.emoji-fade-enter-active,
.emoji-fade-leave-active {
    transition: all 0.2s ease-out;
}

.emoji-fade-enter-from,
.emoji-fade-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.95);
}

.emoji-fade-enter-to,
.emoji-fade-leave-from {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.emoji-close-btn {
    position: absolute;
    bottom: 10px;
    right: 10px;
    background: var(--color-fill-2);
    height: 20px;
    width: 20px;
    text-align: center;
    line-height: 20px;
    border-radius: var(--base-radius-small);
    font-size: var(--base-size-small);
    :hover{
        background: var(--color-fill-1);
    }
}
</style>