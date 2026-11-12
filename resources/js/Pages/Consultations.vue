<script setup>
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import ConsultationAction from "../Components/ConsultationAction.vue";
import ConsultationRequestAction from "../Components/ConsultationRequestAction.vue";
import ConsultationSlotCard from "../Components/ConsultationSlotCard.vue";
import { dateTime } from "../dates";
const props = defineProps({
    role: String,
    slots: Object,
    bookings: Object,
    requests: Object,
    coaches: Array,
});
const page = usePage();
const request = useForm({ topic: "", availability: "" });
const slot = useForm({
    coach_id: props.role === "coach" ? page.props.auth.user.id : "",
    date: "",
    time: "",
    minutes: 60,
    provider: "meet",
    meeting_url: "",
    free_confirmation: false,
});
const labels = {
    confirmed: "رزروشده",
    cancelled: "لغوشده",
    completed: "برگزارشده",
    no_show: "عدم حضور",
    pending: "منتظر هماهنگی",
    scheduled: "زمان تعیین شد",
    declined: "بسته‌شده",
    withdrawn: "انصراف",
};
</script>
<template>
    <Head title="مشاوره" /><Shell title="مشاوره" :role="role">
        <section class="page-intro">
            <div>
                <span class="eyebrow">گفت‌وگو برای قدم بعد</span>
                <h1>مشاوره</h1>
                <p class="muted">
                    زمان‌ها به وقت تهران هستند. زمان‌های قابل رزرو این مرحله
                    رایگان‌اند.
                </p>
            </div>
        </section>
        <div v-if="role === 'student'" class="panel glass consultation-card">
            <h2>برای انتخاب زمان کمک می‌خواهید؟</h2>
            <p class="muted">
                موضوع و زمان‌های مناسب خود را بنویسید؛ ادمین پس از هماهنگی، جلسه
                را در همین صفحه ثبت می‌کند.
            </p>
            <form
                @submit.prevent="
                    request.post('/consultations/requests', {
                        preserveScroll: true,
                        onSuccess: () => request.reset(),
                    })
                "
                class="stack-form"
            >
                <label
                    >موضوع مشاوره<textarea
                        v-model="request.topic"
                        minlength="3"
                        maxlength="3000"
                        required
                    ></textarea></label
                ><label
                    >روزها و ساعت‌های مناسب شما<textarea
                        v-model="request.availability"
                        minlength="3"
                        maxlength="1500"
                        required
                    ></textarea></label
                ><button class="btn primary" :disabled="request.processing">
                    درخواست هماهنگی
                </button>
            </form>
        </div>
        <details v-else class="panel glass consultation-card">
            <summary>تعریف زمان مشاوره</summary>
            <form
                @submit.prevent="
                    slot.post('/consultations/slots', {
                        preserveScroll: true,
                        onSuccess: () => {
                            slot.date = '';
                            slot.time = '';
                            slot.meeting_url = '';
                            slot.free_confirmation = false;
                        },
                    })
                "
                class="stack-form"
            >
                <label v-if="role === 'admin'"
                    >مربی<select v-model="slot.coach_id" required>
                        <option value="" disabled>انتخاب مربی</option>
                        <option v-for="c in coaches" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select></label
                ><JalaliDateInput
                    v-model="slot.date"
                    label="تاریخ جلسه"
                    required
                /><label
                    >شروع به وقت تهران<input
                        type="time"
                        v-model="slot.time"
                        required /></label
                ><label
                    >مدت (دقیقه)<input
                        type="number"
                        v-model="slot.minutes"
                        min="15"
                        max="180"
                        required /></label
                ><label
                    >بستر جلسه<select v-model="slot.provider">
                        <option value="meet">گوگل میت</option>
                        <option value="skyroom">اسکای‌روم</option>
                        <option value="zoom">زوم</option>
                    </select></label
                ><label
                    >لینک جلسه (قابل تکمیل تا قبل از جلسه)<input
                        type="url"
                        v-model="slot.meeting_url"
                        dir="ltr"
                        maxlength="2000" /></label
                ><label class="checkbox-label"
                    ><input
                        type="checkbox"
                        v-model="slot.free_confirmation"
                        required
                    />این زمان را به صورت رایگان ارائه می‌کنم.</label
                ><button class="btn primary" :disabled="slot.processing">
                    انتشار زمان
                </button>
            </form>
        </details>
        <section class="consultation-section">
            <h2>
                {{
                    role === "student"
                        ? "انتخاب زمان مستقیم"
                        : "زمان‌های مشاوره"
                }}
            </h2>
            <p v-if="!slots.data.length" class="muted">
                زمانی برای نمایش وجود ندارد.
            </p>
            <div class="consultation-grid">
                <ConsultationSlotCard
                    v-for="s in slots.data"
                    :key="s.id"
                    :slot="s"
                    :role="role"
                />
            </div>
            <Pagination :links="slots.links" />
        </section>
        <section class="consultation-section">
            <h2>
                {{ role === "student" ? "جلسه‌های من" : "جلسه‌ها و پیگیری" }}
            </h2>
            <p v-if="!bookings.data.length" class="muted">
                هنوز جلسه‌ای ثبت نشده است.
            </p>
            <div class="consultation-grid">
                <article
                    v-for="b in bookings.data"
                    :key="b.id"
                    class="panel glass consultation-card"
                >
                    <span class="status-badge">{{ labels[b.status] }}</span>
                    <h3>{{ b.coach_name }} · {{ b.student_name }}</h3>
                    <p>
                        {{ dateTime(b.starts_at) }} تا {{ dateTime(b.ends_at) }}
                    </p>
                    <p class="preserve-lines">{{ b.topic }}</p>
                    <p v-if="b.shared_summary" class="preserve-lines">
                        خلاصه جلسه: {{ b.shared_summary }}
                    </p>
                    <p v-if="b.cancellation_reason" class="muted">
                        دلیل لغو: {{ b.cancellation_reason }}
                    </p>
                    <a
                        v-if="b.can_join"
                        :href="`/consultations/${b.id}/join`"
                        class="btn primary"
                        target="_blank"
                        rel="noopener noreferrer"
                        >ورود به جلسه</a
                    >
                    <p v-else-if="b.status === 'confirmed'" class="muted">
                        ورود از ۱۵ دقیقه قبل از شروع تا پایان جلسه، پس از ثبت
                        لینک مربی فعال می‌شود.
                    </p>
                    <ConsultationAction
                        :booking="b"
                        :manager="role !== 'student'"
                    />
                </article>
            </div>
            <Pagination :links="bookings.links" />
        </section>
        <section v-if="requests" class="consultation-section">
            <h2>درخواست‌های هماهنگی</h2>
            <p v-if="!requests.data.length" class="muted">
                درخواستی ثبت نشده است.
            </p>
            <div class="consultation-grid">
                <article
                    v-for="r in requests.data"
                    :key="r.id"
                    class="panel glass consultation-card"
                >
                    <span class="status-badge">{{ labels[r.status] }}</span>
                    <h3>{{ r.student_name }}</h3>
                    <p>{{ dateTime(r.created_at) }}</p>
                    <p class="preserve-lines">{{ r.topic }}</p>
                    <p class="preserve-lines">
                        زمان‌های مناسب: {{ r.availability }}
                    </p>
                    <p v-if="r.resolution" class="preserve-lines">
                        {{ r.resolution }}
                    </p>
                    <ConsultationRequestAction
                        :item="r"
                        :admin="role === 'admin'"
                        :slots="slots.data"
                    />
                </article>
            </div>
            <Pagination :links="requests.links" />
        </section>
    </Shell>
</template>
