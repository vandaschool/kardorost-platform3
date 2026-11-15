<script setup>
import { useForm } from "@inertiajs/vue3";
import { tehranInput } from "../dates";
const props = defineProps({ publication: Object });
const form = useForm({
    revision: props.publication.revision,
    accepting: props.publication.accepting,
    opens_at: tehranInput(props.publication.opens_at),
    closes_at: tehranInput(props.publication.closes_at),
});
function open(event) {
    if (event.target.open) {
        form.revision = props.publication.revision;
        form.accepting = props.publication.accepting;
        form.opens_at = tehranInput(props.publication.opens_at);
        form.closes_at = tehranInput(props.publication.closes_at);
        form.clearErrors();
    }
}
</script>
<template>
    <details @toggle="open">
        <summary>تنظیم یا تمدید مهلت دریافت</summary>
        <form
            class="form-stack"
            @submit.prevent="
                form.post(
                    '/admin/survey-publications/' +
                        publication.id +
                        '/receiving',
                    {
                        preserveScroll: true,
                        onSuccess: () => (form.revision = publication.revision),
                    },
                )
            "
        >
            <label class="check-label"
                ><input v-model="form.accepting" type="checkbox" />دریافت پاسخ
                مجاز باشد</label
            ><label
                >شروع دریافت، ساعت تهران<input
                    v-model="form.opens_at"
                    type="datetime-local" /></label
            ><label
                >پایان دریافت، ساعت تهران<input
                    v-model="form.closes_at"
                    type="datetime-local"
            /></label>
            <p class="caption muted">
                تغییر مهلت، پرسش‌ها، شیوه نمایش هویت و پاسخ‌های نهایی قبلی را
                تغییر نمی‌دهد.
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn secondary" :disabled="form.processing">
                ذخیره مهلت دریافت
            </button>
        </form>
    </details>
</template>
