<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { fa, dateTime } from "../dates";
const props = defineProps({
    response: Object,
    publication: Object,
    course_title: String,
});
const form = useForm({
    revision: props.response.revision,
    answers:
        props.response.answers && !Array.isArray(props.response.answers)
            ? { ...props.response.answers }
            : {},
});
function send(final) {
    form.post(
        "/survey-responses/" +
            props.response.id +
            (final ? "/submit" : "/save"),
        {
            preserveScroll: true,
            onSuccess: () => {
                form.revision = props.response.revision;
            },
        },
    );
}
</script>
<template>
    <Head :title="publication.definition.title" /><Shell
        title="پاسخ نظرسنجی"
        role="student"
        ><Link href="/surveys" class="text-link">نظرسنجی‌های من</Link>
        <section class="page-intro">
            <div>
                <h1>{{ publication.definition.title }}</h1>
                <p>{{ course_title }} · نسخه {{ fa(publication.number) }}</p>
                <p class="lesson-text">
                    {{ publication.definition.description }}
                </p>
            </div>
        </section>
        <section class="glass panel-card form-stack">
            <p>
                {{
                    publication.definition.mode === "identified"
                        ? "پاسخ نهایی همراه نام شما برای مدرس دوره و مدیریت گزارش می‌شود."
                        : "نام شما در گزارش نمایش داده نمی‌شود؛ ثبت‌نام برای جلوگیری از پاسخ تکراری در سامانه نگه‌داری می‌شود."
                }}
            </p>
            <p v-if="publication.definition.mode === 'aggregate'">
                نتیجهٔ هر پرسش پس از
                {{ fa(publication.definition.minimum_responses) }} پاسخ به همان
                پرسش نمایش داده می‌شود.
            </p>
            <p v-if="publication.closes_at">
                پایان دریافت: {{ dateTime(publication.closes_at) }}
            </p>
            <p v-if="response.status === 'submitted'" class="badge teal">
                پاسخ نهایی ثبت شده است · {{ dateTime(response.submitted_at) }}
            </p>
            <p v-else-if="!response.writable" class="muted">
                در حال حاضر امکان ذخیره یا ثبت نهایی فعال نیست؛ پاسخ ذخیره‌شده
                قابل مشاهده است.
            </p>
            <form
                class="form-stack"
                data-survey-response
                @submit.prevent="send(true)"
            >
                <label
                    v-for="(question, index) in publication.definition
                        .questions"
                    :key="question.key"
                    >{{ fa(index + 1) }}. {{ question.text }}
                    {{ question.required ? "· الزامی" : "· اختیاری"
                    }}<select
                        v-if="question.type === 'rating'"
                        v-model="form.answers[question.key]"
                        :name="question.key"
                        :disabled="!response.writable"
                    >
                        <option value="">انتخاب گزینه</option>
                        <option
                            v-for="(option, index) in publication.definition
                                .options"
                            :key="index"
                            :value="index + 1"
                        >
                            {{ fa(index + 1) }} · {{ option }}
                        </option></select
                    ><textarea
                        v-else
                        v-model="form.answers[question.key]"
                        :name="question.key"
                        maxlength="2000"
                        rows="4"
                        :disabled="!response.writable"
                    />
                </label>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <div v-if="response.writable" class="learning-actions">
                    <button
                        type="button"
                        class="btn secondary"
                        :disabled="form.processing"
                        @click="send(false)"
                    >
                        ذخیره و ادامه بعداً</button
                    ><button class="btn primary" :disabled="form.processing">
                        ثبت نهایی پاسخ
                    </button>
                </div>
            </form>
        </section></Shell
    >
</template>
