<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ request: Object });
const form = useForm({
    revision: props.request.revision,
    action: "issue",
    note: "",
    reviewed: false,
});
</script>
<template>
    <form
        class="form-stack"
        @submit.prevent="
            form.post('/admin/certificate-requests/' + request.id + '/review', {
                preserveScroll: true,
            })
        "
        :data-certificate-review="request.id"
    >
        <label
            >تصمیم<select v-model="form.action">
                <option value="issue">صدور گواهی</option>
                <option value="reject">بازگشت برای اصلاح</option>
            </select></label
        ><label
            >یادداشت بررسی<textarea
                v-model="form.note"
                minlength="3"
                maxlength="2000"
                required
            /></label
        ><label v-if="form.action === 'issue'" class="check-label"
            ><input v-model="form.reviewed" type="checkbox" required />نام
            متقاضی و شواهد آموزشی این درخواست را بررسی و صدور این گواهی را تأیید
            می‌کنم.</label
        >
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <button
            class="btn primary"
            :disabled="
                form.processing ||
                (form.action === 'issue' && !request.evidence.eligible)
            "
        >
            ثبت نتیجه بررسی
        </button>
    </form>
</template>
