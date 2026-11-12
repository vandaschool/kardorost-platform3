<script setup>
import { useForm } from "@inertiajs/vue3";
import Icon from "./Icon.vue";
import { money, dateOnly } from "../finance";
import { fa } from "../dates";
defineProps({ cohort: Object });
const form = useForm({ payment_plan: "full" });
const formats = { live: "زنده", recorded: "ویدیویی", hybrid: "ترکیبی" };
</script>
<template>
    <div class="cohort-checkout">
        <div class="cohort-option">
            <span
                ><Icon name="CalendarDays" :size="16" />{{ cohort.title }} ·
                {{ formats[cohort.format] }}</span
            ><button
                class="btn secondary"
                :disabled="form.processing"
                @click="form.post('/cohorts/' + cohort.id + '/enroll')"
            >
                ثبت‌نام<Icon name="ArrowLeft" :size="16" />
            </button>
        </div>
        <p v-if="cohort.available_seats !== undefined" class="caption muted">
            ظرفیت آزاد برای ثبت‌نام تازه: {{ fa(cohort.available_seats) }}
        </p>
        <label v-if="cohort.installment_enabled"
            >روش پرداخت<select v-model="form.payment_plan">
                <option value="full">یکجا</option>
                <option value="installments">
                    اقساطی؛ فعال‌سازی با پرداخت قسط اول
                </option>
            </select></label
        >
        <details v-if="cohort.installment_enabled" class="checkout-schedule">
            <summary>مبلغ و سررسید اقساط</summary>
            <p v-for="part in cohort.installment_plan" :key="part.id">
                <strong
                    >قسط {{ fa(part.sequence) }} ·
                    {{ money(part.amount) }}</strong
                ><span>{{
                    part.sequence === 1
                        ? "هنگام ثبت‌نام"
                        : dateOnly(part.due_date)
                }}</span>
            </p>
        </details>
        <p
            v-for="(v, k) in form.errors"
            :key="k"
            class="field-error"
            role="alert"
        >
            {{ v }}
        </p>
    </div>
</template>
