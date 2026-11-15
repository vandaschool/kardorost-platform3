<script setup>
import { Link } from "@inertiajs/vue3";
import { fa } from "../dates";
defineProps({ page: Object, links: Array });
function label(link, index, total) {
    if (index === 0) return "صفحه قبل";
    if (index === total - 1) return "صفحه بعد";
    return /^\d+$/.test(link.label) ? fa(Number(link.label)) : "…";
}
</script>
<template>
    <nav
        v-if="page && page.last_page > 1"
        class="finance-pagination"
        aria-label="صفحه‌بندی"
    >
        <Link
            v-if="page.prev_page_url"
            :href="page.prev_page_url"
            class="btn secondary"
            >صفحه قبل</Link
        ><span class="caption muted"
            >صفحه {{ fa(page.current_page) }} از {{ fa(page.last_page) }}</span
        ><Link
            v-if="page.next_page_url"
            :href="page.next_page_url"
            class="btn secondary"
            >صفحه بعد</Link
        >
    </nav>
    <nav
        v-else-if="!page && links && links.length > 3"
        class="finance-pagination"
        style="flex-wrap: wrap"
        aria-label="صفحه‌بندی"
    >
        <template v-for="(link, index) in links" :key="index"
            ><Link
                v-if="link.url"
                :href="link.url"
                class="btn"
                :class="link.active ? 'primary' : 'secondary'"
                :aria-current="link.active ? 'page' : undefined"
                >{{ label(link, index, links.length) }}</Link
            ><span v-else class="caption muted">{{
                label(link, index, links.length)
            }}</span></template
        >
    </nav>
</template>
