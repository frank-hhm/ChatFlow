<template>
    <div>
        <div class="toolbar-image-btn" :class="{ disabled: disabled }" @click="triggerScreenClick">
            <div class="icon icon-jiandao"></div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { ScreenshotService } from "bindings/ChatFlow/tools/index";


const disabled = ref(false);

const triggerScreenClick = () => {
    if (disabled.value) return;

    disabled.value = true;

    // 需要的返回格式：'png' 或 'jpg'（'jpeg' 亦可）；图片同时已写入剪贴板，可直接 Ctrl+V 粘贴
    const format = 'png';

    ScreenshotService.CaptureSelectedRegion(format)
        .then(res => {
            if (!res) {
                // 用户取消（ESC / 右键 / 未形成有效选区）
                return;
            }
            console.log('截图结果', res);
            // res 为 data:image/png;base64 或 data:image/jpeg;base64 数据
        })
        .catch(err => {
            console.error('调起截图失败', err);
        })
        .finally(() => {
            // 覆盖层关闭后恢复按钮可用
            disabled.value = false;
        });
};

/**
 * 截图覆盖层关闭后调用，恢复按钮可用状态
 */
</script>