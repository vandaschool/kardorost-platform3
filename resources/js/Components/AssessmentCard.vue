<script setup>
import { Link, useForm } from "@inertiajs/vue3";
import { fa } from "../dates";
const props = defineProps({ assessment: Object });
const form = useForm({ acknowledged: false });
</script>
<template>
    <article class="panel glass consultation-card">
        <h2>{{ assessment.title }}</h2>
        <p class="preserve-lines">{{ assessment.description }}</p>
        <p class="muted">
            {{ fa(assessment.question_count) }} پرسش · نسخه
            {{ fa(assessment.version) }} · رایگان
        </p>
        <p class="muted preserve-lines">منبع: {{ assessment.source }}</p>
        <Link
            v-if="assessment.attempt_id"
            :href="`/assessment-attempts/${assessment.attempt_id}${assessment.status === 'completed' ? '/report' : ''}`"
            class="btn primary"
            >{{
                assessment.status === "completed"
                    ? "مشاهده نتیجه"
                    : "ادامه پاسخ‌دادن"
            }}</Link
        >
        <form
            v-else
            class="stack-form"
            @submit.prevent="form.post(`/assessments/${assessment.id}/start`)"
        >
            <label
                ><input
                    type="checkbox"
                    v-model="form.acknowledged"
                    required
                />می‌دانم مدیر پلتفرم و مدیران سازمانی که عضو آن هستم، به گزارش
                نهایی دسترسی دارند. پاسخ‌های ناتمام برای خودم محفوظ
                می‌مانند.</label
            ><button class="btn primary" :disabled="form.processing">
                شروع پرسشنامه
            </button>
        </form>
    </article>
</template>
