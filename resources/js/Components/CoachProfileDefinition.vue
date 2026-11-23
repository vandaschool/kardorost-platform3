<script setup>
import CoachProfileLinks from "./CoachProfileLinks.vue";
import ContentMediaThumbnail from "./ContentMediaThumbnail.vue";
defineProps({ definition: Object });
</script>
<template>
    <section class="assessment-editor-item">
        <ContentMediaThumbnail
            v-if="definition?.photo?.key"
            :source="`/coach/profile-photos/${encodeURIComponent(definition.photo.key)}/preview`"
            :alt="`عکس ${definition.display_name}`"
            class="coach-photo-definition"
        />

        <h3>{{ definition.display_name }}</h3>
        <p>{{ definition.headline }}</p>
        <p class="preserve-lines">{{ definition.bio }}</p>
        <p v-if="definition.city">شهر: {{ definition.city }}</p>
        <p>زمینه‌ها: {{ definition.focus_areas.join("، ") }}</p>
        <p>زبان‌ها: {{ definition.languages.join("، ") }}</p>
        <CoachProfileLinks :links="definition.links" />
    </section>
</template>

<style scoped>
.coach-photo-definition {
    max-width: 100%;
    width: 140px;
    max-height: 160px;
    object-fit: contain;
    border-radius: 18px;
    display: block;
    margin-block: 1rem;
}
</style>
