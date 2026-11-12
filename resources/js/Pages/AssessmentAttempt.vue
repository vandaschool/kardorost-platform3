<script setup>
import { watch, computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { fa } from "../dates";
const props = defineProps({
    attempt: Object,
    title: String,
    instructions: String,
    source: String,
    number: Number,
    options: Array,
    questions: Array,
    answers: [Object, Array],
    answered: Number,
    total: Number,
    page: Number,
    last: Number,
});
const form = useForm({
    revision: props.attempt.revision,
    answers: { ...props.answers },
    next_page: props.page,
});
const unsaved = computed(() =>
    props.questions.some(
        (q) => (form.answers[q.key] ?? null) !== (props.answers[q.key] ?? null),
    ),
);
const finish = useForm({ revision: props.attempt.revision });
watch(
    () => [props.attempt.revision, props.page],
    () => {
        form.revision = props.attempt.revision;
        form.answers = { ...props.answers };
        finish.revision = props.attempt.revision;
        form.defaults({
            revision: props.attempt.revision,
            answers: { ...props.answers },
            next_page: props.page,
        });
    },
);
function save(next) {
    form.next_page = next;
    form.post(`/assessment-attempts/${props.attempt.id}/answers`, {
        preserveScroll: false,
    });
}
</script>
<template>
    <Head :title="title" /><Shell title="پاسخ به پرسشنامه" role="student"
        ><Link href="/assessments" class="text-link">بازگشت به خودشناسی</Link>
        <section class="page-intro">
            <div>
                <h1>{{ title }}</h1>
                <p class="muted">
                    نسخه {{ fa(number) }} · {{ fa(answered) }} پاسخ ذخیره‌شده از
                    {{ fa(total) }}
                </p>
            </div>
        </section>
        <p class="preserve-lines">{{ instructions }}</p>
        <p class="muted preserve-lines">منبع: {{ source }}</p>
        <progress
            :value="answered"
            :max="total"
            class="assessment-progress"
            :aria-label="'تعداد پاسخ ذخیره‌شده ' + answered + ' از ' + total"
        ></progress>
        <form
            class="stack-form"
            @submit.prevent="save(Math.min(page + 1, last))"
        >
            <fieldset
                v-for="(q, i) in questions"
                :key="q.key"
                class="panel glass consultation-card assessment-question"
            >
                <legend>{{ fa((page - 1) * 10 + i + 1) }}. {{ q.text }}</legend>
                <label
                    v-for="(label, j) in options"
                    :key="j"
                    class="assessment-option"
                    ><input
                        type="radio"
                        :name="q.key"
                        :value="j + 1"
                        v-model="form.answers[q.key]"
                    />{{ label }}</label
                >
            </fieldset>
            <div class="assessment-actions">
                <button
                    v-if="page > 1"
                    type="button"
                    class="btn secondary"
                    :disabled="form.processing"
                    @click="save(page - 1)"
                >
                    ذخیره و صفحه قبل</button
                ><button
                    type="submit"
                    class="btn primary"
                    :disabled="form.processing"
                >
                    {{
                        page < last
                            ? "ذخیره و ادامه"
                            : "ذخیره پاسخ‌های این صفحه"
                    }}
                </button>
            </div>
        </form>
        <p class="muted">
            صفحه {{ fa(page) }} از {{ fa(last) }}. تغییرات این صفحه با دکمه
            ذخیره ثبت می‌شوند.
        </p>
        <form
            v-if="answered === total"
            class="panel glass consultation-card stack-form consultation-section"
            @submit.prevent="
                finish.post(`/assessment-attempts/${attempt.id}/finish`)
            "
        >
            <p>
                پس از ثبت نهایی، پاسخ‌ها قابل ویرایش نیستند و گزارش از پاسخ‌های
                ذخیره‌شده ساخته می‌شود.
            </p>
            <button
                class="btn primary"
                :disabled="finish.processing || form.processing || unsaved"
            >
                ثبت نهایی و مشاهده نتیجه
            </button>
            <p v-if="unsaved" class="muted">
                ابتدا تغییرات پاسخ‌ها را ذخیره کنید.
            </p>
        </form></Shell
    >
</template>
