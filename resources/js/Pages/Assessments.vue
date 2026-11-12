<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import AssessmentCard from "../Components/AssessmentCard.vue";
import { fa, dateTime } from "../dates";
defineProps({ assessments: Array, attempts: Object });
</script>
<template>
    <Head title="خودشناسی" /><Shell title="خودشناسی" role="student"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">یک قدم برای شناخت بهتر</span>
                <h1>خودشناسی</h1>
                <p class="muted">
                    پرسشنامه را با آرامش پاسخ دهید؛ می‌توانید پاسخ‌ها را ذخیره
                    کنید و بعداً ادامه دهید.
                </p>
            </div>
        </section>
        <p v-if="!assessments.length" class="muted">
            هنوز پرسشنامه‌ای منتشر نشده است. ابزارها پس از انتخاب و بررسی در این
            بخش قرار می‌گیرند.
        </p>
        <div class="consultation-grid">
            <AssessmentCard
                v-for="a in assessments"
                :key="a.id"
                :assessment="a"
            />
        </div>
        <section class="consultation-section">
            <h2>پاسخ‌ها و نتایج من</h2>
            <p v-if="!attempts.data.length" class="muted">
                هنوز پرسشنامه‌ای شروع نکرده‌اید.
            </p>
            <div class="consultation-grid">
                <article
                    class="panel glass consultation-card"
                    v-for="a in attempts.data"
                    :key="a.id"
                >
                    <h3>{{ a.title }}</h3>
                    <p>
                        نسخه {{ fa(a.number) }} ·
                        {{
                            a.status === "completed"
                                ? "نهایی‌شده"
                                : fa(a.answered) +
                                  " پاسخ ذخیره‌شده از " +
                                  fa(a.total)
                        }}
                    </p>
                    <p v-if="a.completed_at">{{ dateTime(a.completed_at) }}</p>
                    <Link
                        :href="`/assessment-attempts/${a.id}${a.status === 'completed' ? '/report' : ''}`"
                        class="btn secondary"
                        >{{
                            a.status === "completed"
                                ? "گزارش نتیجه"
                                : "ادامه پاسخ‌دادن"
                        }}</Link
                    >
                </article>
            </div>
            <Pagination :links="attempts.links" /></section
    ></Shell>
</template>
