<script setup>
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
const props = defineProps({
    cohort: Object,
    coaches: Array,
    coursePrice: Number,
});
const open = ref(false);
const benefit = computed(() => props.cohort.consultation_benefit);
const form = useForm({
    revision: benefit.value?.revision || 0,
    enabled: !!benefit.value?.enabled,
    coach_id: benefit.value?.coach_id || "",
    title: benefit.value?.title || "مشاوره همراه دوره",
    sessions: benefit.value?.sessions || 1,
    session_minutes: benefit.value?.session_minutes || 60,
    valid_days: benefit.value?.valid_days || 90,
    activation: benefit.value?.activation || "",
    reviewed: false,
});
function save() {
    form.patch(`/admin/cohorts/${props.cohort.id}/consultation-benefit`, {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.cohort.consultation_benefit?.revision || 0;
            form.reviewed = false;
            open.value = false;
        },
    });
}
</script>
<template>
    <details
        class="billing-plan-editor"
        :open="open"
        @toggle="open = $event.target.open"
    >
        <summary>
            مشاوره همراه دوره · {{ cohort.title }}
            <span class="badge" :class="benefit?.enabled ? 'teal' : 'gold'">{{
                benefit?.enabled ? "فعال" : "غیرفعال"
            }}</span>
        </summary>
        <form class="form-stack" @submit.prevent="save">
            <p class="caption muted">
                این شرایط فقط برای خریدهای شخصی بعدی این نوبت ثبت می‌شود.
                ثبت‌نام رایگان و سهمیه سازمانی مشمول نیست.
            </p>
            <label class="check-label"
                ><input
                    v-model="form.enabled"
                    type="checkbox"
                    :disabled="coursePrice <= 0"
                />مشاوره همراه خرید فعال باشد</label
            >
            <template v-if="form.enabled">
                <label
                    >عنوان سهمیه<input
                        v-model="form.title"
                        maxlength="160"
                        required
                /></label>
                <label
                    >مربی مشاوره<select v-model="form.coach_id" required>
                        <option value="" disabled>انتخاب مربی</option>
                        <option
                            v-for="coach in coaches"
                            :key="coach.id"
                            :value="coach.id"
                        >
                            {{ coach.name }}
                        </option>
                    </select></label
                >
                <label
                    >تعداد جلسه<input
                        v-model="form.sessions"
                        type="number"
                        min="1"
                        max="100"
                        required
                /></label>
                <label
                    >مدت هر جلسه (دقیقه)<input
                        v-model="form.session_minutes"
                        type="number"
                        min="15"
                        max="180"
                        required
                /></label>
                <label
                    >مهلت استفاده از روز صدور (روز)<input
                        v-model="form.valid_days"
                        type="number"
                        min="1"
                        max="365"
                        required
                /></label>
                <label
                    >زمان فعال‌سازی<select v-model="form.activation" required>
                        <option value="" disabled>انتخاب شرط پرداخت</option>
                        <option value="first_payment">پس از پرداخت اول</option>
                        <option value="paid_in_full">پس از تسویه کامل</option>
                    </select></label
                >
                <label class="check-label"
                    ><input
                        v-model="form.reviewed"
                        type="checkbox"
                        required
                    />شرط پرداخت، مربی، تعداد، مدت و مهلت را تأیید می‌کنم. لغو
                    جلسه پیش از شروع، سهمیه همان قرارداد را برمی‌گرداند.</label
                >
            </template>
            <p class="caption muted">
                تغییر یا غیرفعال کردن این تنظیم، تعهد خریدهای قبلی را تغییر
                نمی‌دهد. برای ارائه جلسات، زمان‌های مربی را نیز در بخش مشاوره
                تعریف کنید.
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn primary" :disabled="form.processing">
                ذخیره شرایط مشاوره
            </button>
        </form>
    </details>
</template>
