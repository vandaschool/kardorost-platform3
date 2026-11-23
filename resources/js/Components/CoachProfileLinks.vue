<script setup>
import { computed } from "vue";
import { safeContentLink } from "../content-document";
const props = defineProps({ links: Array });
const safeLinks = computed(() =>
    (Array.isArray(props.links) ? props.links : [])
        .slice(0, 5)
        .flatMap((link) => {
            if (
                !link ||
                typeof link.label !== "string" ||
                typeof link.url !== "string" ||
                !/^https?:\/\//i.test(link.url.trim())
            )
                return [];
            const url = safeContentLink(link.url);
            return url ? [{ label: link.label, url }] : [];
        }),
);
</script>
<template>
    <ul
        v-if="safeLinks.length"
        class="coach-public-links"
        data-coach-profile-links
    >
        <li v-for="link in safeLinks" :key="link.url">
            <a
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer nofollow"
                >{{ link.label }} <span aria-hidden="true">↗</span></a
            >
        </li>
    </ul>
</template>
<style scoped>
.coach-public-links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    list-style: none;
    padding: 0;
    margin-block: 1rem;
}
li {
    min-width: 0;
    max-width: 100%;
}
a {
    display: inline-block;
    border: 1px solid rgba(16, 168, 159, 0.35);
    border-radius: 12px;
    padding: 0.5rem 0.8rem;
    overflow-wrap: anywhere;
}
</style>
