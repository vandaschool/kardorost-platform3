<script setup>
import { ref, watch } from "vue";
const props = defineProps({
    mediaKey: String,
    alt: { type: String, default: "" },
});
const failed = ref(false);
watch(
    () => props.mediaKey,
    () => {
        failed.value = false;
    },
);
</script>
<template>
    <span v-if="failed" class="muted" data-unavailable-media-preview
        >تصویر در دسترس نیست.</span
    ><img
        v-else
        :src="`/admin/media/${encodeURIComponent(mediaKey)}/preview`"
        :alt="alt"
        loading="lazy"
        @error="failed = true"
    />
</template>
