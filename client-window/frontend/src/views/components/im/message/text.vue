<template>
    <div class="message-content-text" :class="{
        self: sender === 'client'
    }">
        {{ content }}
    </div>
</template>
<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        content: string;
        sender?: string;
    }>(),
    {
        content: "",
        sender: "client"
    }
);
</script>

<style scoped lang="less">
.message-content-text {
    max-width: 60%;
    padding: 8px 12px;
    line-height: 20px;
    border-radius: 36px;
    background: var(--color-bg-1);
    word-break: break-word;
    white-space: pre-wrap;
}

.message-content-text.self {
    background: var(--color-primary-light-1);
    color: var(--color-primary);
}
</style>