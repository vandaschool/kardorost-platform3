<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa, dateTime } from "../dates";
defineProps({ offers: Array, responses: Object });
const form = useForm({});
</script>
<template>
    <Head title="نظرسنجی دوره‌ها" /><Shell
        title="نظرسنجی دوره‌ها"
        role="student"
        ><section class="page-intro">
            <div>
                <h1>تجربهٔ شما از دوره</h1>
                <p class="muted">
                    پرسش‌های هر نسخه و شیوه گزارش آن پیش از پاسخ روشن است.
                </p>
            </div>
        </section>
        <p v-if="!offers.length" class="muted">
            نظرسنجی تازه‌ای برای شما فعال نیست.
        </p>
        <article
            v-for="offer in offers"
            :key="offer.id"
            class="glass panel-card consultation-section form-stack"
            :data-survey-offer="offer.id"
        >
            <h2>{{ offer.title }}</h2>
            <p>
                {{ offer.course_title }} · {{ offer.cohort_title }} · نسخه
                {{ fa(offer.number) }}
            </p>
            <p class="lesson-text">{{ offer.description }}</p>
            <p>
                {{
                    offer.mode === "identified"
                        ? "پاسخ نهایی همراه نام شما در گزارش مدرس دوره و مدیریت قرار می‌گیرد."
                        : "نام شما در گزارش نمایش داده نمی‌شود؛ ارتباط ثبت‌نام برای جلوگیری از تکرار در سامانه نگه‌داری می‌شود."
                }}
            </p>
            <p v-if="offer.mode === 'aggregate'" class="caption muted">
                گزارش هر پرسش پس از {{ fa(offer.minimum_responses) }} پاسخ به
                همان پرسش نمایش داده می‌شود.
            </p>
            <p v-if="offer.opens_at">شروع: {{ dateTime(offer.opens_at) }}</p>
            <p v-if="offer.closes_at">پایان: {{ dateTime(offer.closes_at) }}</p>
            <button
                class="btn primary"
                :disabled="form.processing || !offer.receiving"
                @click="
                    form.post('/survey-publications/' + offer.id + '/start')
                "
            >
                {{
                    offer.receiving
                        ? "شروع یا ادامه پاسخ"
                        : "دریافت پاسخ در این زمان فعال نیست"
                }}
            </button>
        </article>
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <section class="consultation-section">
            <h2>سوابق پاسخ من</h2>
            <article
                v-for="response in responses.data"
                :key="response.id"
                class="glass panel-card consultation-section"
            >
                <h3>{{ response.title }}</h3>
                <p>
                    {{ response.course_title }} · نسخه
                    {{ fa(response.number) }} ·
                    {{
                        response.status === "submitted"
                            ? "نهایی‌شده"
                            : "نیمه‌کاره"
                    }}
                </p>
                <Link
                    :href="'/survey-responses/' + response.id"
                    class="btn secondary"
                    >مشاهده پاسخ</Link
                >
            </article>
            <Pagination :links="responses.links" /></section
    ></Shell>
</template>
