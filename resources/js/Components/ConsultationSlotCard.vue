<script setup>
import { useForm } from "@inertiajs/vue3";
import { money } from "../finance";
import { dateTime, providers } from "../dates";
const props = defineProps({
    slot: Object,
    role: String,
    gatewayEnabled: Boolean,
});
const book = useForm({ topic: "" });
const link = useForm({ meeting_url: props.slot.meeting_url || "" });
const visibility = useForm({ published: !props.slot.published });
function toggle() {
    visibility.published = !props.slot.published;
    visibility.patch(`/consultations/slots/${props.slot.id}`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <article class="panel glass consultation-card">
        <h3>{{ slot.coach_name }}</h3>
        <p>{{ dateTime(slot.starts_at) }} تا {{ dateTime(slot.ends_at) }}</p>
        <p class="muted">
            {{ providers[slot.provider] }} ·
            {{ slot.price ? money(slot.price) : "رایگان" }} · زمان شماره
            {{ slot.id }}
        </p>
        <p v-if="slot.price > 0" class="muted">
            رزرو با پرداخت قطعی می‌شود. لغو پس از پرداخت برای بررسی بازپرداخت
            ثبت می‌شود.
        </p>
        <p
            v-if="role === 'student' && slot.price > 0 && !gatewayEnabled"
            class="muted"
        >
            درگاه پرداخت فعلاً فعال نیست.
        </p>
        <form
            v-if="role === 'student'"
            @submit.prevent="
                book.post(`/consultations/slots/${slot.id}/book`, {
                    preserveScroll: true,
                    onSuccess: () => book.reset(),
                })
            "
            class="stack-form"
        >
            <label
                >موضوع مشاوره<textarea
                    v-model="book.topic"
                    required
                    minlength="3"
                    maxlength="3000"
                ></textarea></label
            ><button
                class="btn primary"
                :disabled="
                    book.processing || (slot.price > 0 && !gatewayEnabled)
                "
            >
                {{ slot.price > 0 ? "رزرو موقت و پرداخت" : "رزرو این زمان" }}
            </button>
        </form>
        <div v-else class="stack-form">
            <span class="status-badge">{{
                slot.booked ? "رزروشده" : slot.published ? "قابل رزرو" : "پنهان"
            }}</span
            ><button
                v-if="!slot.booked && new Date(slot.starts_at) > new Date()"
                class="btn secondary"
                @click="toggle"
                :disabled="visibility.processing"
            >
                {{ slot.published ? "پنهان‌کردن زمان" : "انتشار زمان" }}
            </button>
            <form
                v-if="new Date(slot.ends_at) > new Date()"
                @submit.prevent="
                    link.patch(`/consultations/slots/${slot.id}/meeting`, {
                        preserveScroll: true,
                    })
                "
                class="stack-form"
            >
                <label
                    >لینک {{ providers[slot.provider]
                    }}<input
                        v-model="link.meeting_url"
                        type="url"
                        dir="ltr"
                        required
                        maxlength="2000" /></label
                ><button class="btn secondary" :disabled="link.processing">
                    ذخیره لینک جلسه
                </button>
            </form>
        </div>
    </article>
</template>
