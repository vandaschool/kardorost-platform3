<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ item: Object, admin: Boolean, slots: Array });
const form = useForm({ version: props.item.version, reason: "", slot_id: "" });
function send(action) {
    form.version = props.item.version;
    form.post(`/consultations/requests/${props.item.id}/${action}`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <div v-if="item.status === 'pending'" class="stack-form">
        <form
            v-if="admin"
            @submit.prevent="send('schedule')"
            class="stack-form"
        >
            <label
                >زمان توافق‌شده با دانشجو<select
                    v-model="form.slot_id"
                    required
                >
                    <option value="" disabled>زمان را انتخاب کنید</option>
                    <option
                        v-for="slot in slots.filter(
                            (s) =>
                                s.published &&
                                !s.booked &&
                                new Date(s.starts_at) > new Date(),
                        )"
                        :key="slot.id"
                        :value="slot.id"
                    >
                        #{{ slot.id }} · {{ slot.coach_name }}
                    </option>
                </select></label
            >
            <p class="muted">
                شناسه زمان و جزئیات آن را در فهرست زمان‌ها بررسی کنید. رزرو پس
                از هماهنگی با دانشجو انجام شود.
            </p>
            <button class="btn primary" :disabled="form.processing">
                رزرو زمان توافق‌شده
            </button>
        </form>
        <form @submit.prevent="send('resolve')" class="stack-form">
            <label
                >{{ admin ? "دلیل بستن درخواست" : "دلیل انصراف"
                }}<textarea
                    v-model="form.reason"
                    required
                    minlength="3"
                    maxlength="1500"
                ></textarea></label
            ><button class="btn secondary" :disabled="form.processing">
                {{ admin ? "بستن با توضیح" : "انصراف از هماهنگی" }}
            </button>
        </form>
    </div>
</template>
