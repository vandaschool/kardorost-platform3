<script setup>
import { Head } from "@inertiajs/vue3";
import { fa } from "../dates";
defineProps({
    status: Number,
    title: String,
    description: String,
    retry_after: Number,
});
</script>
<template>
    <Head :title="title"
        ><meta name="robots" content="noindex,nofollow" /><meta
            name="referrer"
            content="no-referrer"
    /></Head>
    <div class="problem-page">
        <main class="problem-card" data-problem-page>
            <a class="problem-brand" href="/">کاردرست</a
            ><span class="problem-code" dir="ltr">{{ fa(status) }}</span>
            <h1>{{ title }}</h1>
            <p>{{ description }}</p>
            <p v-if="retry_after">
                زمان انتظار اعلام‌شده: {{ fa(retry_after) }} ثانیه
            </p>
            <nav class="problem-actions" aria-label="راه‌های ادامه">
                <a class="primary" href="/panel">رفتن به پنل</a
                ><a href="/">صفحه اصلی</a>
            </nav>
        </main>
    </div>
</template>
<style scoped>
.problem-page {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    background:
        radial-gradient(
            ellipse at 15% 10%,
            var(--bg-a, #1abcad15),
            transparent 55%
        ),
        var(--surface, #f5f7f8);
    color: var(--ink, #0f1b3d);
}
.problem-card {
    box-sizing: border-box;
    width: min(100%, 620px);
    padding: clamp(24px, 6vw, 48px);
    border: 1px solid var(--glass-border, #ffffffdd);
    border-radius: 28px;
    background: var(--glass-strong, #ffffffbd);
    box-shadow: var(--shadow);
    backdrop-filter: blur(18px);
    overflow-wrap: anywhere;
    min-width: 0;
}
.problem-brand {
    color: var(--teal-ink, #08766c);
    font-weight: 800;
    text-decoration: none;
}
.problem-code {
    display: block;
    font-size: clamp(40px, 10vw, 64px);
    line-height: 1.3;
    color: var(--teal-ink, #08766c);
    margin: 20px 0 12px;
}
.problem-card h1 {
    font-size: clamp(22px, 5vw, 30px);
    line-height: 1.7;
    margin: 0 0 14px;
}
.problem-card p {
    color: var(--muted, #647087);
    margin: 0 0 18px;
    line-height: 2;
}
.problem-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
}
.problem-actions a {
    padding: 10px 22px;
    border-radius: 14px;
    text-decoration: none;
    border: 1px solid var(--line, #e4e8ed);
    color: var(--ink, #0f1b3d);
    background: var(--surface-raised, #fff);
}
.problem-actions .primary {
    background: var(--action, #0f1b3d);
    color: var(--on-action, #fff);
    border-color: var(--action, #0f1b3d);
}
.problem-actions a:focus-visible {
    outline: 3px solid var(--teal, #1abcad);
    outline-offset: 4px;
}
</style>
