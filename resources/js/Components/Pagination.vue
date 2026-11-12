<script setup>
import { Link } from "@inertiajs/vue3";
import { fa } from "../dates";
defineProps({ page: Object });
</script>
<template>
    <nav
        v-if="page.last_page > 1"
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
</template>
