<script setup>
import { computed } from "vue";
const props = defineProps({ tags: Array });
const labels = computed(() =>
    (Array.isArray(props.tags) ? props.tags : [])
        .slice(0, 8)
        .filter((tag) => typeof tag === "string" && tag.trim() !== ""),
);
</script>
<template>
    <ul
        v-if="labels.length"
        class="content-tags-preview"
        data-content-tags-preview
    >
        <li v-for="tag in labels" :key="tag">{{ tag }}</li>
    </ul>
</template>
<style scoped>
.content-tags-preview {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0;
}
li {
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 0.3rem 0.6rem;
    max-width: 100%;
    overflow-wrap: anywhere;
}
</style>
