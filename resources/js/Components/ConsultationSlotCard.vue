<script setup>
import { useForm } from "@inertiajs/vue3";
import { money, dateOnly } from "../finance";
import { dateTime, providers } from "../dates";
const props = defineProps({
    slot: Object,
    role: String,
    gatewayEnabled: Boolean,
    grants: { type: Array, default: () => [] },
});
const book = useForm({ topic: "", grant_id: "" });
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
    <article class="panel glass consultation-card" :data-slot-id="slot.id">
        <h3>{{ slot.coach_name }}</h3>
        <p>{{ dateTime(slot.starts_at) }} تا {{ dateTime(slot.ends_at) }}</p>
        <p class="muted">
            {{ providers[slot.provider] }} ·
            {{ slot.price ? money(slot.price) : "رایگان" }} · زمان شماره
            {{ slot.id }}
        </p>
        <p v-if="slot.price > 0 && !book.grant_id" class="muted">
            رزرو با پرداخت قطعی می‌شود. لغو پس از پرداخت برای بررسی بازپرداخت
            ثبت می‌شود.
        </p>
        <p
            v-if="
                role === 'student' &&
                slot.price > 0 &&
                !gatewayEnabled &&
                !book.grant_id
            "
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
            ><label
                v-if="
                    slot.price > 0 &&
                    grants.some((g) => (slot.grant_ids || []).includes(g.id))
                "
                >روش رزرو<select v-model="book.grant_id" name="booking_grant">
                    <option value="">پرداخت تعرفه جلسه</option>
                    <option
                        v-for="g in grants.filter((g) =>
                            (slot.grant_ids || []).includes(g.id),
                        )"
                        :key="g.id"
                        :value="g.id"
                    >
                        {{ g.title }} · مانده {{ g.remaining }} جلسه · تا
                        {{ dateOnly(g.valid_until) }}
                    </option>
                </select></label
            >
            <p v-if="book.grant_id" class="muted">
                یک جلسه از پکیج مصرف می‌شود و پرداخت جداگانه ندارد. لغو پیش از
                شروع، سهمیه را برمی‌گرداند؛ عدم حضور آن را مصرف می‌کند.
            </p>
            <button
                class="btn primary"
                :disabled="
                    book.processing ||
                    (slot.price > 0 && !gatewayEnabled && !book.grant_id)
                "
            >
                {{
                    book.grant_id
                        ? "رزرو با سهمیه پکیج"
                        : slot.price > 0
                          ? "رزرو موقت و پرداخت"
                          : "رزرو این زمان"
                }}
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
