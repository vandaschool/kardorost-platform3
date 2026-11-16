<script setup>
defineProps({
    url: String,
    prefix: { type: String, default: "member-learning-export" },
});
const datasets = {
    summary: "خلاصه یادگیری (CSV)",
    attendance: "ریز حضور (CSV)",
    submissions: "نسخه‌های تکلیف (CSV)",
};
</script>
<template>
    <div class="organization-learning-exports">
        <a
            v-for="(label, key) in datasets"
            :key="key"
            :href="url + (key === 'summary' ? '' : '?dataset=' + key)"
            :data-testid="prefix + (key === 'summary' ? '' : '-' + key)"
            class="btn secondary"
            >{{ label }}</a
        >
    </div>
</template>
<style scoped>
.organization-learning-exports {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin: 0.75rem 0;
    min-width: 0;
}
</style>
