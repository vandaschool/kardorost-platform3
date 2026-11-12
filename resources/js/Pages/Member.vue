<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Icon from "../Components/Icon.vue";
import FeedbackHistory from "../Components/FeedbackHistory.vue";
import { fa, submissionStatuses, attendanceStatuses } from "../dates";
defineProps({
    member: Object,
    organization: Object,
    enrollments: Array,
    learningReports: Object,
});
</script>
<template>
    <Head :title="'گزارش ' + member.name" /><Shell
        title="گزارش فردی"
        role="org_admin"
        ><Link href="/organization" class="text-link"
            ><Icon name="ArrowRight" :size="17" />بازگشت به اعضا</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{ organization.name }}</span>
                <h1>{{ member.name }}</h1>
                <p class="muted" dir="ltr">{{ member.phone }}</p>
            </div>
            <span class="small-chip"
                ><Icon name="ShieldCheck" :size="16" />عضو مجموعه شما</span
            >
        </section>
        <section class="glass panel-card">
            <h2>ثبت‌نام‌های آموزشی</h2>
            <Empty
                v-if="!enrollments.length"
                icon="BookOpen"
                title="هنوز ثبت‌نامی وجود ندارد"
                text="ثبت‌نام‌های این عضو پس از انتخاب دوره نمایش داده می‌شوند."
            />
            <div
                v-for="e in enrollments"
                :key="e.id"
                class="member-learning-card"
            >
                <Icon name="BookOpen" />
                <div class="grow">
                    <strong>{{ e.course.title }}</strong
                    ><small>{{ e.cohort.title }}</small>
                </div>
                <span class="badge teal">{{
                    e.status === "active" ? "فعال" : "در انتظار پرداخت"
                }}</span>
                <div class="member-learning-details">
                    <p>
                        درس‌ها:
                        {{ fa(learningReports[e.id]?.progress.completed) }} از
                        {{ fa(learningReports[e.id]?.progress.total) }} ·
                        {{ fa(learningReports[e.id]?.progress.percent) }}٪
                    </p>
                    <progress
                        :value="learningReports[e.id]?.progress.percent || 0"
                        max="100"
                        aria-label="پیشرفت آموزشی عضو"
                    ></progress>
                    <p class="caption muted">
                        حضور:
                        {{
                            fa(
                                learningReports[e.id]?.attendance.filter(
                                    (a) => a.status === "present",
                                ).length,
                            )
                        }}
                        جلسه حاضر ·
                        {{
                            fa(
                                learningReports[e.id]?.attendance.filter(
                                    (a) => a.status === "absent",
                                ).length,
                            )
                        }}
                        جلسه غایب ·
                        {{
                            fa(
                                learningReports[e.id]?.attendance.filter(
                                    (a) => a.status === "excused",
                                ).length,
                            )
                        }}
                        غیبت موجه
                    </p>
                    <div
                        v-for="answer in learningReports[e.id]?.submissions"
                        :key="answer.id"
                        class="member-assignment"
                    >
                        <strong
                            >{{ answer.title }} · نسخه
                            {{ fa(answer.version) }}</strong
                        ><span class="badge teal">{{
                            submissionStatuses[answer.status]
                        }}</span>
                        <p v-if="answer.body" class="lesson-text caption">
                            {{ answer.body }}
                        </p>
                        <a
                            v-if="answer.download_url"
                            :href="answer.download_url"
                            class="text-link"
                            ><Icon name="Download" :size="15" />{{
                                answer.file_name
                            }}</a
                        >
                        <p v-if="answer.score !== null" class="caption">
                            نمره {{ fa(answer.score) }} از
                            {{ fa(answer.max_score) }}
                        </p>
                        <p
                            v-if="answer.feedback"
                            class="lesson-text caption muted"
                        >
                            {{ answer.feedback }}
                        </p>
                        <FeedbackHistory :reviews="answer.reviews || []" />
                    </div>
                </div>
            </div>
        </section>
        <p class="muted caption">
            گزارش آزمون‌های شغلی با تکمیل ماژول خودشناسی به این پرونده متصل
            می‌شود.
        </p></Shell
    >
</template>
