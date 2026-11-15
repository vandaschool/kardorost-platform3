<script setup>
defineProps({ definition: Object });
</script>
<template>
    <section class="assessment-editor-item">
        <h3>{{ definition.display_name }}</h3>
        <p>{{ definition.headline }}</p>
        <p class="preserve-lines">{{ definition.bio }}</p>
        <p v-if="definition.city">شهر: {{ definition.city }}</p>
        <p>زمینه‌ها: {{ definition.focus_areas.join("، ") }}</p>
        <p>زبان‌ها: {{ definition.languages.join("، ") }}</p>
    </section>
</template>
