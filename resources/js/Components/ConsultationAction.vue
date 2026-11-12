<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ booking: Object, manager: Boolean });
const form = useForm({
    version: props.booking.version,
    action: "cancel",
    reason: "",
    shared_summary: "",
});
function submit() {
    form.version = props.booking.version;
    form.patch(`/consultations/${props.booking.id}`, { preserveScroll: true });
}
</script>
<template>
    <form
        v-if="booking.can_cancel || (manager && booking.can_finish)"
        @submit.prevent="submit"
        class="stack-form"
    >
        <label v-if="manager && booking.can_finish"
            >نتیجه جلسه<select v-model="form.action" required>
                <option disabled value="cancel">انتخاب کنید</option>
                <option value="completed">برگزار شد</option>
                <option value="no_show">عدم حضور دانشجو</option>
            </select></label
        >
        <label v-if="booking.can_cancel"
            >دلیل لغو<textarea
                v-model="form.reason"
                required
                minlength="3"
                maxlength="1500"
            ></textarea>
        </label>
        <label v-else
            >خلاصه قابل مشاهده برای دانشجو<textarea
                v-model="form.shared_summary"
                maxlength="5000"
            ></textarea>
        </label>
        <button class="btn secondary" :disabled="form.processing">
            {{ booking.can_cancel ? "لغو جلسه و آزادسازی زمان" : "ثبت نتیجه" }}
        </button>
    </form>
</template>
