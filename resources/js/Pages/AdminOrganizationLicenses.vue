<script setup>
import { computed } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import OrganizationLicenseEditor from "../Components/OrganizationLicenseEditor.vue";
import { fa } from "../dates";
const props = defineProps({
    licenses: Object,
    organizations: Array,
    cohorts: Array,
});
const form = useForm({
    organization_id: "",
    cohort_id: "",
    seat_limit: 1,
    expires_on: "",
    active: true,
    contract_reference: "",
    authorized: false,
});
const cohort = computed(() =>
    props.cohorts.find((c) => c.id === Number(form.cohort_id)),
);
</script>
<template>
    <Head title="سهمیه سازمان‌ها" /><Shell title="سهمیه سازمان‌ها" role="admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">ظرفیت و ثبت‌نام سازمانی</span>
                <h1>سهمیه سازمان‌ها</h1>
                <p class="muted">
                    صندلی‌ها از ظرفیت همان نوبت کنار گذاشته می‌شوند و با ثبت‌نام
                    فردی مشترک‌اند.
                </p>
            </div>
        </section>
        <details class="panel glass consultation-card">
            <summary>تخصیص سهمیه تازه</summary>
            <form
                class="stack-form organization-license-form"
                @submit.prevent="
                    form.post('/admin/organization-licenses', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <label
                    >سازمان<select
                        v-model="form.organization_id"
                        required
                        name="organization_id"
                    >
                        <option disabled value="">انتخاب سازمان</option>
                        <option
                            v-for="o in organizations"
                            :key="o.id"
                            :value="o.id"
                        >
                            {{ o.name }}
                        </option>
                    </select></label
                ><label
                    >دوره و نوبت<select
                        v-model="form.cohort_id"
                        required
                        name="cohort_id"
                    >
                        <option disabled value="">انتخاب نوبت</option>
                        <option v-for="c in cohorts" :key="c.id" :value="c.id">
                            {{ c.title }}
                        </option>
                    </select></label
                >
                <p v-if="cohort" class="muted">
                    ظرفیت آزاد فعلی: {{ fa(cohort.available) }} از
                    {{ fa(cohort.capacity) }}
                </p>
                <label
                    >تعداد صندلی<input
                        v-model="form.seat_limit"
                        name="seat_limit"
                        type="number"
                        min="1"
                        max="10000"
                        required /></label
                ><JalaliDateInput
                    v-model="form.expires_on"
                    label="پایان مهلت ثبت‌نام جدید"
                    required
                /><label
                    >شناسه قرارداد یا مجوز تخصیص<input
                        v-model="form.contract_reference"
                        name="contract_reference"
                        required
                        maxlength="200" /></label
                ><label
                    ><input
                        type="checkbox"
                        v-model="form.authorized"
                        required
                    />تخصیص سهمیه طبق قرارداد یا مجوز سازمان تأیید شده
                    است.</label
                >
                <p class="muted">
                    صدور صورتحساب سازمان و تأیید دریافت وجه در این فرم انجام
                    نمی‌شود. دانشجوی ثبت‌نام‌شده از این سهمیه بدهی شخصی ندارد.
                </p>
                <button class="btn primary" :disabled="form.processing">
                    رزرو سهمیه از ظرفیت نوبت
                </button>
            </form>
        </details>
        <section class="consultation-section">
            <p v-if="!licenses.data.length" class="muted">
                هنوز سهمیه‌ای تخصیص داده نشده است.
            </p>
            <div class="consultation-grid">
                <OrganizationLicenseEditor
                    v-for="l in licenses.data"
                    :key="l.id"
                    :license="l"
                />
            </div>
            <Pagination :links="licenses.links" /></section
    ></Shell>
</template>
