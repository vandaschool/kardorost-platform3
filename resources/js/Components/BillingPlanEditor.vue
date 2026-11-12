<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import Icon from "./Icon.vue";
import { money, latinDigits } from "../finance";
import { fa, tehranInput } from "../dates";
const props = defineProps({ cohort: Object, coursePrice: Number });
const open = ref(false);
const form = useForm({
    expected_revision: props.cohort.billing_revision || 0,
    installment_enabled: !!props.cohort.installment_enabled,
    manual_payment_enabled: !!props.cohort.manual_payment_enabled,
    parts: (props.cohort.installment_plan || []).map((p) => ({
        amount: p.amount / 10,
        due_date: p.due_date?.slice(0, 10) || "",
    })),
});
watch(
    () => form.installment_enabled,
    (enabled) => {
        if (enabled) {
            while (form.parts.length < 2)
                form.parts.push({ amount: "", due_date: "" });
        }
    },
);
const total = computed(() =>
    form.parts.reduce(
        (sum, p) => sum + (Number(latinDigits(p.amount)) || 0) * 10,
        0,
    ),
);
function add() {
    form.parts.push({ amount: "", due_date: "" });
}
function save() {
    form.transform((data) => ({
        ...data,
        parts: data.installment_enabled
            ? data.parts.map((p) => ({
                  ...p,
                  amount: Number(latinDigits(p.amount)),
              }))
            : [],
    })).patch(`/admin/cohorts/${props.cohort.id}/billing`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.expected_revision += 1;
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
            تنظیم پرداخت · {{ cohort.title
            }}<span
                class="badge"
                :class="cohort.installment_enabled ? 'teal' : 'gold'"
                >{{
                    cohort.installment_enabled ? "اقساط فعال" : "پرداخت یکجا"
                }}</span
            ><span v-if="cohort.manual_payment_enabled" class="badge teal"
                >کارت‌به‌کارت</span
            >
        </summary>
        <form @submit.prevent="save" class="form-stack">
            <div class="access-options">
                <label class="check-label"
                    ><input
                        v-model="form.installment_enabled"
                        type="checkbox"
                    />پرداخت اقساطی فعال باشد</label
                ><label class="check-label"
                    ><input
                        v-model="form.manual_payment_enabled"
                        type="checkbox"
                    />رسید کارت‌به‌کارت پذیرفته شود</label
                >
            </div>
            <template v-if="form.installment_enabled"
                ><p class="caption muted">
                    قیمت دوره: {{ money(coursePrice) }}. قسط اول هنگام ثبت‌نام
                    پرداخت می‌شود؛ برای باقی اقساط تاریخ شمسی تعیین کنید.
                </p>
                <div
                    v-for="(part, i) in form.parts"
                    :key="i"
                    class="billing-part-row"
                >
                    <strong>قسط {{ fa(i + 1) }}</strong
                    ><label
                        >مبلغ (تومان)<input
                            v-model="part.amount"
                            inputmode="numeric"
                            required /></label
                    ><label v-if="i > 0"
                        >سررسید<JalaliDateInput
                            v-model="part.due_date"
                            required
                            :label="'سررسید قسط ' + fa(i + 1)" /></label
                    ><span v-else class="muted caption">هنگام ثبت‌نام</span
                    ><button
                        type="button"
                        class="icon-btn"
                        @click="form.parts.splice(i, 1)"
                        :aria-label="'حذف قسط ' + fa(i + 1)"
                    >
                        <Icon name="X" :size="16" />
                    </button>
                </div>
                <button
                    type="button"
                    class="btn secondary"
                    @click="add"
                    :disabled="form.parts.length >= 12"
                >
                    <Icon name="Plus" :size="16" />افزودن قسط
                </button>
                <p
                    :class="[
                        'caption',
                        total === coursePrice ? 'muted' : 'field-error',
                    ]"
                >
                    جمع اقساط: {{ money(total) }} ·
                    {{
                        total === coursePrice
                            ? "با قیمت برابر است"
                            : "باید دقیقاً با قیمت دوره برابر شود"
                    }}
                </p></template
            >
            <p class="caption muted">
                تغییر این برنامه فقط بر ثبت‌نام‌های بعدی اثر دارد. اقساط
                دانشجویان قبلی تغییر نمی‌کند.
            </p>
            <p
                v-for="(v, k) in form.errors"
                :key="k"
                class="field-error"
                role="alert"
            >
                {{ v }}
            </p>
            <button class="btn primary" :disabled="form.processing">
                ذخیره تنظیمات پرداخت
            </button>
        </form>
    </details>
</template>
