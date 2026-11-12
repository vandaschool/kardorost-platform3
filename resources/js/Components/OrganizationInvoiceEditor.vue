<script setup>
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import { money } from "../finance";
const props = defineProps({
    invoice: Object,
    organizations: Array,
    licenses: Array,
});
const form = useForm({
    organization_id: props.invoice?.organization_id || "",
    revision: props.invoice?.revision || 1,
    definition: {
        title: props.invoice?.title || "",
        contract_reference: props.invoice?.contract_reference || "",
        due_date: props.invoice?.due_date || "",
        organization_license_id: props.invoice?.organization_license_id || null,
        lines: props.invoice
            ? props.invoice.lines.map((l) => ({
                  ...l,
                  unit_price: l.unit_price / 10,
              }))
            : [{ title: "", quantity: 1, unit_price: "" }],
    },
});
const emit = defineEmits(["dirty"]);
const saved = ref(JSON.stringify(form.definition));
watch(
    () => JSON.stringify(form.definition),
    (value) => emit("dirty", value !== saved.value),
);
function savedDraft() {
    form.revision = props.invoice.revision;
    saved.value = JSON.stringify(form.definition);
    emit("dirty", false);
}
const total = computed(() =>
    form.definition.lines.reduce(
        (n, l) => n + Number(l.quantity || 0) * Number(l.unit_price || 0) * 10,
        0,
    ),
);
function submit() {
    if (props.invoice) {
        form.patch(`/admin/organization-invoices/${props.invoice.id}`, {
            preserveScroll: true,
            onSuccess: savedDraft,
        });
    } else {
        form.post("/admin/organization-invoices");
    }
}
</script>
<template>
    <form
        class="panel glass consultation-card stack-form organization-license-form"
        @submit.prevent="submit"
    >
        <h2>{{ invoice ? "ویرایش پیش‌نویس" : "پیش‌نویس صورتحساب داخلی" }}</h2>
        <label v-if="!invoice"
            >سازمان<select
                v-model="form.organization_id"
                name="organization_id"
                required
            >
                <option value="" disabled>انتخاب کنید</option>
                <option v-for="o in organizations" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select></label
        ><label
            >عنوان<input
                v-model="form.definition.title"
                name="invoice_title"
                required
                maxlength="160" /></label
        ><label
            >شناسه قرارداد<input
                v-model="form.definition.contract_reference"
                name="contract_reference"
                required
                maxlength="200" /></label
        ><JalaliDateInput
            v-model="form.definition.due_date"
            label="سررسید صورتحساب"
            required
        /><label v-if="invoice"
            >ارتباط با سهمیه (اختیاری)<select
                v-model="form.definition.organization_license_id"
            >
                <option :value="null">بدون ارتباط</option>
                <option v-for="l in licenses" :key="l.id" :value="l.id">
                    {{ l.title }}
                </option>
            </select></label
        >
        <fieldset
            v-for="(l, i) in form.definition.lines"
            :key="i"
            class="assessment-editor-item"
        >
            <legend>ردیف {{ i + 1 }}</legend>
            <label
                >شرح<input
                    v-model="l.title"
                    :name="`line_title_${i}`"
                    required
                    maxlength="200" /></label
            ><label
                >تعداد<input
                    type="number"
                    v-model="l.quantity"
                    min="1"
                    max="10000"
                    required /></label
            ><label
                >مبلغ واحد (تومان)<input
                    type="number"
                    :name="`line_price_${i}`"
                    v-model="l.unit_price"
                    min="1"
                    max="1000000000"
                    required /></label
            ><button
                v-if="form.definition.lines.length > 1"
                type="button"
                class="text-link"
                @click="form.definition.lines.splice(i, 1)"
            >
                حذف ردیف
            </button>
        </fieldset>
        <button
            type="button"
            class="btn secondary"
            :disabled="form.definition.lines.length >= 50"
            @click="
                form.definition.lines.push({
                    title: '',
                    quantity: 1,
                    unit_price: '',
                })
            "
        >
            افزودن ردیف</button
        ><strong>جمع صورتحساب: {{ money(total) }}</strong>
        <p class="muted">
            این سند، صورتحساب داخلی قرارداد است. پرداخت یا صدور آن به‌تنهایی
            سهمیه آموزشی را تغییر نمی‌دهد.
        </p>
        <button class="btn primary" :disabled="form.processing">
            ذخیره پیش‌نویس
        </button>
    </form>
</template>
