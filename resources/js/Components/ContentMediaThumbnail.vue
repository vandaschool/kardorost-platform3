<script setup>
import { ref, watch } from "vue";
const props = defineProps({
    mediaKey: String,
    source: String,
    alt: { type: String, default: "" },
});
const failed = ref(false);
watch(
    () => [props.mediaKey, props.source],
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
        :src="
            source &&
            source.startsWith('/') &&
            !source.startsWith('//') &&
            !source.includes('\\')
                ? source
                : `/admin/media/${encodeURIComponent(mediaKey)}/preview`
        "
        :alt="alt"
        loading="lazy"
        @error="failed = true"
    />
</template>
