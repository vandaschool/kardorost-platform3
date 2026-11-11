<script setup>
import Icon from "./Icon.vue";
defineProps({
    icon: { type: String, default: "Sprout" },
    title: String,
    text: String,
});
</script>
<template>
    <div class="empty">
        <span class="empty-icon"><Icon :name="icon" :size="30" /></span>
        <h3>{{ title }}</h3>
        <p>{{ text }}</p>
        <slot />
    </div>
</template>
