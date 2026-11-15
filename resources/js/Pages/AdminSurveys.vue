<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa } from "../dates";
defineProps({ surveys: Object, cohorts: Array });
const form = useForm({ cohort_id: "", title: "" });
</script>
<template>
    <Head title="نظرسنجی دوره‌ها" /><Shell title="نظرسنجی دوره‌ها" role="admin"
        ><section class="page-intro"><h1>نظرسنجی دوره‌ها</h1></section>
        <details class="glass panel-card">
            <summary>ساخت نظرسنجی</summary>
            <form
                class="form-stack"
                @submit.prevent="form.post('/admin/surveys')"
                data-survey-create
            >
                <label
                    >نوبت<select
                        v-model="form.cohort_id"
                        name="survey_cohort"
                        required
                    >
                        <option value="" disabled>انتخاب نوبت</option>
                        <option
                            v-for="cohort in cohorts"
                            :key="cohort.id"
                            :value="cohort.id"
                        >
                            {{ cohort.title }}
                        </option>
                    </select></label
                ><label
                    >عنوان<input
                        v-model="form.title"
                        name="survey_title"
                        maxlength="160"
                        required
                /></label>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="form.processing">
                    ساخت پیش‌نویس
                </button>
            </form>
        </details>
        <p v-if="!surveys.data.length" class="muted">نظرسنجی ثبت نشده است.</p>
        <article
            v-for="survey in surveys.data"
            :key="survey.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ survey.title }}</h2>
            <p>
                {{ survey.course_title }} · {{ survey.cohort_title }} · نسخه
                {{ fa(survey.current_version) }}
            </p>
            <Link :href="'/admin/surveys/' + survey.id" class="btn secondary"
                >مدیریت و گزارش</Link
            >
        </article>
        <Pagination :links="surveys.links"
    /></Shell>
</template>
