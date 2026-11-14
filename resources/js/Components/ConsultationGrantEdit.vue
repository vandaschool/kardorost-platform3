<script setup>
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
const props = defineProps({ grant: Object });
const form = useForm({
    revision: props.grant.revision,
    total_sessions: props.grant.total_sessions,
    valid_until: props.grant.valid_until,
    active: props.grant.active,
});
function save() {
    form.patch(`/admin/consultation-grants/${props.grant.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.grant.revision;
        },
    });
}
</script>
<template>
    <details>
        <summary>مدیریت سهمیه</summary>
        <form @submit.prevent="save" class="stack-form consultation-section">
            <label
                >سقف کل جلسات<input
                    v-model="form.total_sessions"
                    type="number"
                    min="1"
                    max="100"
                    required /></label
            ><JalaliDateInput
                v-model="form.valid_until"
                label="آخرین روز پایان جلسه"
                required
            /><label
                ><input type="checkbox" v-model="form.active" />رزرو تازه از این
                سهمیه فعال باشد.</label
            >
            <p class="muted">
                دانشجو، مربی، مدت، عنوان و منبع سهمیه ثابت‌اند. رزرو قطعی قبلی
                با تغییر سهمیه لغو نمی‌شود.
            </p>
            <button class="btn secondary" :disabled="form.processing">
                ذخیره سهمیه
            </button>
        </form>
    </details>
</template>
