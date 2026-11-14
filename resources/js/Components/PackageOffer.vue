<script setup>
import { useForm } from "@inertiajs/vue3";
import { money } from "../finance";
import { fa } from "../dates";
const props = defineProps({ product: Object });
const form = useForm({ revision: props.product.revision, accepted: false });
</script>
<template>
    <article
        class="glass panel consultation-card"
        :data-package-offer="product.id"
    >
        <h2>{{ product.title }}</h2>
        <p class="lesson-text">{{ product.description }}</p>
        <p>
            {{ product.coach_name }} · {{ fa(product.sessions) }} جلسه
            {{ fa(product.session_minutes) }} دقیقه‌ای
        </p>
        <strong>{{ money(product.price) }}</strong>
        <p class="caption muted">
            مهلت استفاده {{ fa(product.valid_days) }} روز از صدور سهمیه است.
            زمان جلسه را از وقت‌های قابل رزرو مربی انتخاب می‌کنید؛ جلسه باید تا
            پایان مهلت تمام شود.
        </p>
        <form
            class="form-stack"
            @submit.prevent="
                form.post('/consultation-packages/' + product.id + '/purchase')
            "
        >
            <label class="check-label"
                ><input v-model="form.accepted" type="checkbox" required />شرایط
                پکیج را می‌پذیرم. لغو پیش از شروع، اعتبار همان جلسه را
                برمی‌گرداند.</label
            >
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn primary" :disabled="form.processing">
                ادامه خرید
            </button>
        </form>
    </article>
</template>
