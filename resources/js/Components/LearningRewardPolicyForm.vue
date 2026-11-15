<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ cohort: Object });
const definition = props.cohort.policy?.definition;
const form = useForm({
    revision: props.cohort.policy?.revision || 0,
    enabled: !!props.cohort.policy?.enabled,
    lesson_points: definition?.lesson_points || "",
    badge_enabled: !!definition?.badge_goal,
    badge_goal: definition?.badge_goal || "",
    badge_title: definition?.badge_title || "",
    reviewed: false,
});
watch(
    () => props.cohort.policy?.revision,
    (revision) => {
        form.revision = revision;
        form.reviewed = false;
    },
);
</script>
<template>
    <form
        class="stack-form"
        :data-reward-policy="cohort.id"
        @submit.prevent="
            form.patch(`/admin/cohorts/${cohort.id}/learning-rewards`, {
                preserveScroll: true,
            })
        "
    >
        <label
            ><input
                type="checkbox"
                name="rewards_enabled"
                v-model="form.enabled"
            />امتیاز تکمیل‌های بعدی این نوبت فعال باشد</label
        >
        <template v-if="form.enabled"
            ><label
                >امتیاز هر درس<input
                    type="number"
                    name="lesson_points"
                    min="1"
                    max="100"
                    step="1"
                    v-model="form.lesson_points"
                    required /></label
            ><label
                ><input
                    type="checkbox"
                    name="badge_enabled"
                    v-model="form.badge_enabled"
                />نشان هدف یادگیری فعال باشد</label
            ><template v-if="form.badge_enabled"
                ><label
                    >امتیاز لازم برای نشان<input
                        type="number"
                        name="badge_goal"
                        min="1"
                        max="100000"
                        step="1"
                        v-model="form.badge_goal"
                        required /></label
                ><label
                    >نام نشان<input
                        name="badge_title"
                        v-model="form.badge_title"
                        minlength="3"
                        maxlength="100"
                        required /></label></template
        ></template>
        <p class="muted">
            هر حساب از هر درس فقط یک‌بار امتیاز می‌گیرد؛ تکرار درس در نوبت دیگر
            امتیاز تازه ندارد. سابقه و نشان قبلی با ویرایش قواعد تغییر نمی‌کنند.
            نشان هدف هر نوبت یک‌بار صادر می‌شود.
        </p>
        <label
            ><input
                type="checkbox"
                name="reward_policy_reviewed"
                v-model="form.reviewed"
                required
            />امتیازها و شرایط تکمیل خوداظهاری را بررسی کرده‌ام.</label
        ><button class="btn primary" :disabled="form.processing">
            ذخیره نسخه قواعد
        </button>
    </form>
</template>
