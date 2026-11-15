<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ cohort: Object });
const policy = props.cohort.policy;
const definition = policy?.definition;
const form = useForm({
    revision: policy?.revision || 0,
    enabled: !!policy?.enabled,
    title: definition?.title || "گواهی تکمیل دوره",
    issuer: definition?.issuer || "",
    lesson_ids: definition?.lessons.map((i) => i.id) || [],
    session_ids: definition?.sessions.map((i) => i.id) || [],
    assignment_ids: definition?.assignments.map((i) => i.id) || [],
    attendance_percent: definition?.attendance_percent || 100,
    score_percent: definition?.score_percent || 70,
    require_settled: !!definition?.require_settled,
    reviewed: false,
});
function options(field, items) {
    const key = {
        lesson_ids: "lessons",
        session_ids: "sessions",
        assignment_ids: "assignments",
    }[field];
    const prior = props.cohort.policy?.definition[key] || [];
    return [
        ...items,
        ...prior
            .filter((item) => !items.some((current) => current.id === item.id))
            .map((item) => ({
                ...item,
                title: item.title + " (انتخاب قبلی، منتشرنشده)",
            })),
    ];
}
function save() {
    form.patch("/admin/cohorts/" + props.cohort.id + "/certificate-policy", {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.cohort.policy?.revision || 0;
            form.reviewed = false;
        },
    });
}
</script>
<template>
    <form
        class="form-stack"
        @submit.prevent="save"
        :data-certificate-policy="cohort.id"
    >
        <label class="check-label"
            ><input
                v-model="form.enabled"
                name="certificate_enabled"
                type="checkbox"
            />درخواست گواهی فعال باشد</label
        >
        <template v-if="form.enabled">
            <label
                >عنوان گواهی<input
                    v-model="form.title"
                    name="certificate_title"
                    maxlength="160"
                    required
            /></label>
            <label
                >نام صادرکننده<input
                    v-model="form.issuer"
                    name="certificate_issuer"
                    maxlength="160"
                    required
            /></label>
            <p class="caption muted">
                حداقل یک معیار آموزشی انتخاب کنید. معیارهای درخواست‌های ثبت‌شده
                با تغییر این فرم عوض نمی‌شوند.
            </p>
            <fieldset
                v-for="[field, label, items] in [
                    ['lesson_ids', 'تکمیل همه درس‌های منتخب', cohort.lessons],
                    ['session_ids', 'حضور در جلسات منتخب', cohort.sessions],
                    [
                        'assignment_ids',
                        'نمره هر تمرین منتخب',
                        cohort.assignments,
                    ],
                ]"
                :key="field"
            >
                <legend>{{ label }}</legend>
                <p v-if="!items.length" class="muted caption">
                    محتوای منتشرشده‌ای موجود نیست.
                </p>
                <label
                    v-for="item in options(field, items)"
                    :key="item.id"
                    class="check-label"
                    ><input
                        v-model="form[field]"
                        type="checkbox"
                        :value="item.id"
                        :name="field"
                    />{{ item.title }}</label
                >
            </fieldset>
            <label v-if="form.session_ids.length"
                >حداقل حضور ثبت‌شده (درصد)<input
                    v-model="form.attendance_percent"
                    name="attendance_percent"
                    type="number"
                    min="1"
                    max="100"
                    required
            /></label>
            <label v-if="form.assignment_ids.length"
                >حداقل نمره هر تمرین (درصد)<input
                    v-model="form.score_percent"
                    name="score_percent"
                    type="number"
                    min="1"
                    max="100"
                    required
            /></label>
            <label class="check-label"
                ><input v-model="form.require_settled" type="checkbox" />تسویه
                کامل شهریه شخصی لازم باشد</label
            >
            <p class="caption muted">
                ثبت تکمیل درس خوداظهاری است. فقط «حاضر» در حضور ثبت‌شده حساب
                می‌شود و آخرین پاسخ هر تمرین باید نمره بررسی‌شده داشته باشد.
                صدور همیشه به بررسی ادمین نیاز دارد.
            </p>
            <label class="check-label"
                ><input
                    v-model="form.reviewed"
                    name="certificate_policy_reviewed"
                    type="checkbox"
                    required
                />معیارها، نام صادرکننده و عنوان گواهی را بررسی کردم.</label
            >
        </template>
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <button class="btn primary" :disabled="form.processing">
            ذخیره شرایط گواهی
        </button>
    </form>
</template>
