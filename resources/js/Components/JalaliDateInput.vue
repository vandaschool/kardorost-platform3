<script setup>
import { ref, computed, watch } from "vue";
import { fa } from "../dates";
import { toJalali, fromJalali, monthDays } from "../finance";
const props = defineProps({
    modelValue: { type: String, default: "" },
    required: Boolean,
    label: { type: String, default: "تاریخ" },
});
const emit = defineEmits(["update:modelValue"]);
const year = ref(""),
    month = ref(""),
    day = ref("");
watch(
    () => props.modelValue,
    (value) => {
        const j = toJalali(value);
        year.value = j?.year || "";
        month.value = j?.month || "";
        day.value = j?.day || "";
    },
    { immediate: true },
);
const months = [
    "فروردین",
    "اردیبهشت",
    "خرداد",
    "تیر",
    "مرداد",
    "شهریور",
    "مهر",
    "آبان",
    "آذر",
    "دی",
    "بهمن",
    "اسفند",
];
const years = Array.from({ length: 201 }, (_, i) => 1300 + i);
const days = computed(() => monthDays(year.value, month.value));
const needed = computed(
    () => props.required || !!year.value || !!month.value || !!day.value,
);
function change() {
    if (year.value && month.value && day.value) {
        day.value = Math.min(Number(day.value), days.value);
        emit(
            "update:modelValue",
            fromJalali(year.value, month.value, day.value),
        );
    }
}
function clear() {
    year.value = "";
    month.value = "";
    day.value = "";
    emit("update:modelValue", "");
}
</script>
<template>
    <span class="jalali-date-input" role="group" :aria-label="label"
        ><select
            v-model="day"
            @change="change"
            :required="needed"
            :aria-label="'روز ' + label"
        >
            <option value="">روز</option>
            <option v-for="d in days" :key="d" :value="d">
                {{ fa(d) }}
            </option></select
        ><select
            v-model="month"
            @change="change"
            :required="needed"
            :aria-label="'ماه ' + label"
        >
            <option value="">ماه</option>
            <option v-for="(name, i) in months" :key="name" :value="i + 1">
                {{ name }}
            </option></select
        ><select
            v-model="year"
            @change="change"
            :required="needed"
            :aria-label="'سال ' + label"
        >
            <option value="">سال</option>
            <option v-for="y in years" :key="y" :value="y">
                {{ fa(y) }}
            </option></select
        ><button
            v-if="!required"
            type="button"
            @click="clear"
            class="text-link"
            :aria-label="'پاک کردن ' + label"
        >
            پاک
        </button></span
    >
</template>
