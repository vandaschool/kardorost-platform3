<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa, dateTime } from "../dates";
const props = defineProps({
    role: String,
    publication: Object,
    course_title: String,
    cohort_title: String,
    report: Object,
    identified_rows: Object,
    comments: Object,
    selected_question: String,
});
function question(key) {
    router.get("/survey-publications/" + props.publication.id + "/report", {
        question: key,
    });
}
</script>
<template>
    <Head title="گزارش نظرسنجی" /><Shell title="گزارش نظرسنجی" :role="role"
        ><section class="page-intro">
            <div>
                <h1>{{ publication.definition.title }}</h1>
                <p>
                    {{ course_title }} · {{ cohort_title }} · نسخه
                    {{ fa(publication.number) }}
                </p>
                <p>
                    پاسخ نهایی: {{ fa(report.count) }} ·
                    {{
                        publication.definition.mode === "identified"
                            ? "گزارش با نام"
                            : "گزارش بدون نمایش نام"
                    }}
                </p>
            </div>
        </section>
        <p v-if="!report.unlocked" class="glass panel-card">
            هنوز حداقل {{ fa(publication.definition.minimum_responses) }} پاسخ
            نهایی برای نمایش نتیجه ثبت نشده است.
        </p>
        <template v-else
            ><article
                v-for="question in report.questions"
                :key="question.key"
                class="glass panel-card consultation-section form-stack"
                :data-survey-stat="question.key"
            >
                <h2>{{ question.text }}</h2>
                <p>تعداد پاسخ این پرسش: {{ fa(question.count) }}</p>
                <p v-if="!question.unlocked" class="muted">
                    تعداد پاسخ به حداقل گزارش نرسیده است.
                </p>
                <template v-else-if="question.type === 'rating'"
                    ><strong>میانگین: {{ fa(question.average) }} از ۵</strong>
                    <div
                        v-for="item in question.distribution"
                        :key="item.value"
                        class="survey-distribution"
                    >
                        <span
                            >{{ fa(item.value) }} ·
                            {{
                                publication.definition.options[item.value - 1]
                            }}</span
                        ><progress
                            :value="item.count"
                            :max="question.count"
                            :aria-label="'تعداد گزینه ' + item.value"
                        /><span>{{ fa(item.count) }}</span>
                    </div></template
                >
            </article>
            <section
                v-if="publication.definition.mode === 'aggregate'"
                class="consultation-section"
            >
                <h2>پاسخ‌های متنی بدون نمایش نام</h2>
                <label
                    >پرسش<select
                        :value="selected_question || ''"
                        @change="question($event.target.value)"
                    >
                        <option
                            v-for="q in publication.definition.questions.filter(
                                (q) => q.type === 'text',
                            )"
                            :key="q.key"
                            :value="q.key"
                        >
                            {{ q.text }}
                        </option>
                    </select></label
                >
                <p v-if="!comments?.total" class="muted">
                    پاسخ متنی کافی برای نمایش این پرسش ثبت نشده است.
                </p>
                <article
                    v-for="(comment, index) in comments?.data"
                    :key="index"
                    class="glass panel-card consultation-section lesson-text"
                >
                    {{ comment.body }}
                </article>
                <Pagination v-if="comments" :links="comments.links" />
            </section>
            <section v-else class="consultation-section">
                <h2>پاسخ‌های نهایی با نام</h2>
                <article
                    v-for="(row, index) in identified_rows?.data"
                    :key="index"
                    class="glass panel-card consultation-section form-stack"
                >
                    <strong>{{ row.respondent_name }}</strong>
                    <p>{{ dateTime(row.submitted_at) }}</p>
                    <div
                        v-for="q in publication.definition.questions"
                        :key="q.key"
                    >
                        <h3>{{ q.text }}</h3>
                        <p class="lesson-text">
                            {{ row.answers[q.key] ?? "بدون پاسخ" }}
                        </p>
                    </div>
                </article>
                <Pagination
                    v-if="identified_rows"
                    :links="identified_rows.links"
                /></section></template
    ></Shell>
</template>
<style>
.survey-distribution {
    display: grid;
    grid-template-columns: minmax(80px, 1fr) minmax(80px, 2fr) 32px;
    align-items: center;
    gap: 12px;
}
.survey-distribution progress {
    width: 100%;
}
</style>
