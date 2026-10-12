<template>
    <defaultLayout>sssssssss</defaultLayout>
</template>
<script lang="ts" setup>
import defaultLayout from "./default/Layout.vue";
import { useAppStore } from "@/store";
import { storeToRefs } from "pinia";

const { layout } = storeToRefs(useAppStore());

</script>