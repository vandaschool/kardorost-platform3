<script setup>
import { watch } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    ticket: Object,
    messages: Object,
    events: Object,
    admin: Boolean,
    own: Boolean,
    can_reply: Boolean,
    can_change: Boolean,
    admins: Array,
    role: String,
});
const statuses = {
    waiting_staff: "منتظر پاسخ پشتیبانی / مدرس",
    waiting_requester: "منتظر پاسخ درخواست‌کننده",
    resolved: "حل‌شده",
    closed: "بسته‌شده",
};
const roles = { requester: "درخواست‌کننده", admin: "پشتیبانی", coach: "مدرس" };
const actions = {
    resolve: "حل‌شده",
    close: "بستن",
    reopen: "بازگشایی",
    assign: "ارجاع داخلی",
};
const reply = useForm({
    body: "",
    file: null,
    internal_note: false,
    request_key: crypto.randomUUID(),
});
const change = useForm({
    revision: props.ticket.revision,
    action: "resolve",
    reason: "",
    assigned_to: props.ticket.assigned_to || "",
});
watch(
    () => props.ticket.status,
    (status) => {
        change.action = ["resolved", "closed"].includes(status)
            ? "reopen"
            : "resolve";
    },
    { immediate: true },
);
function send() {
    reply.post("/support/" + props.ticket.id + "/messages", {
        onSuccess: () => {
            reply.reset();
            reply.request_key = crypto.randomUUID();
            const input = document.querySelector("input[name=file]");
            if (input) input.value = "";
        },
    });
}
function update() {
    change.revision = props.ticket.revision;
    change.post("/support/" + props.ticket.id + "/change", {
        onSuccess: () => change.reset("reason"),
    });
}
</script>
<template>
    <Head :title="ticket.subject" /><Shell title="درخواست خصوصی" :role="role"
        ><Link
            class="btn secondary"
            :href="admin ? '/support?view=admin' : '/support'"
            >فهرست درخواست‌ها</Link
        >
        <section class="page-intro">
            <div>
                <h1>{{ ticket.subject }}</h1>
                <p>شماره {{ fa(ticket.id) }} · {{ statuses[ticket.status] }}</p>
                <p>
                    {{ ticket.owner_name }}
                    <span v-if="ticket.cohort_title"
                        >· {{ ticket.cohort_title }}</span
                    >
                </p>
            </div>
        </section>
        <p class="muted">
            {{
                ticket.cohort_title
                    ? "گفتگوی خصوصی با مدرس فعلی دوره و ادمین"
                    : "گفتگوی خصوصی با تیم ادمین"
            }}
        </p>
        <section class="glass panel-card consultation-section">
            <h2>پیام‌ها</h2>
            <article
                v-for="message in messages.data"
                :key="message.id"
                class="consultation-section"
                :data-message="message.id"
            >
                <h3>
                    {{ message.author_name }} · {{ roles[message.author_role] }}
                </h3>
                <p v-if="message.internal_note" class="badge">
                    یادداشت داخلی؛ فقط ادمین
                </p>
                <p class="muted">{{ dateTime(message.created_at) }}</p>
                <p style="white-space: pre-wrap; overflow-wrap: anywhere">
                    {{ message.body }}
                </p>
                <a
                    v-if="message.file_url"
                    :href="message.file_url"
                    class="btn secondary"
                    >دریافت پیوست: {{ message.file_name }}</a
                >
            </article>
            <Pagination :page="messages" />
        </section>
        <section
            v-if="can_reply || (admin && ticket.status !== 'closed')"
            class="glass panel-card consultation-section"
        >
            <h2>پاسخ تازه</h2>
            <form @submit.prevent="send" class="form-grid">
                <label v-if="admin"
                    ><input
                        v-model="reply.internal_note"
                        type="checkbox"
                        name="internal_note"
                    />
                    یادداشت داخلی (فقط ادمین)</label
                >
                <p v-if="!can_reply && !reply.internal_note" class="muted">
                    برای پاسخ عمومی ابتدا درخواست را بازگشایی کنید.
                </p>
                <label
                    >پیام<textarea
                        v-model="reply.body"
                        name="body"
                        rows="5"
                        maxlength="12000"
                    /></label
                ><label
                    >پیوست؛ حداکثر ۵ مگابایت<input
                        type="file"
                        name="file"
                        accept=".pdf,.jpg,.jpeg,.png,.docx,.txt"
                        @change="reply.file = $event.target.files[0] || null"
                /></label>
                <p
                    v-for="error in reply.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <progress
                    v-if="reply.progress"
                    :value="reply.progress.percentage"
                    max="100"
                /><button
                    class="btn primary"
                    :disabled="
                        reply.processing || (!can_reply && !reply.internal_note)
                    "
                >
                    {{
                        reply.internal_note ? "ثبت یادداشت داخلی" : "ارسال پاسخ"
                    }}
                </button>
            </form>
        </section>
        <section
            v-if="can_change"
            class="glass panel-card consultation-section"
        >
            <h2>پیگیری درخواست</h2>
            <form class="form-grid" @submit.prevent="update">
                <label
                    >عملیات<select v-model="change.action" name="action">
                        <option
                            v-if="
                                !['resolved', 'closed'].includes(ticket.status)
                            "
                            value="resolve"
                        >
                            حل‌شده
                        </option>
                        <option
                            v-if="
                                admin &&
                                !['resolved', 'closed'].includes(ticket.status)
                            "
                            value="close"
                        >
                            بستن درخواست
                        </option>
                        <option
                            v-if="
                                ticket.status === 'resolved' ||
                                (admin && ticket.status === 'closed')
                            "
                            value="reopen"
                        >
                            بازگشایی
                        </option>
                        <option v-if="admin" value="assign">
                            ارجاع به ادمین
                        </option>
                    </select></label
                ><label v-if="change.action === 'assign'"
                    >مسئول<select
                        v-model="change.assigned_to"
                        name="assigned_to"
                    >
                        <option value="">بدون مسئول مشخص</option>
                        <option v-for="person in admins" :value="person.id">
                            {{ person.name }}
                        </option>
                    </select></label
                ><label
                    >{{
                        change.action === "assign"
                            ? "توضیح داخلی ارجاع"
                            : "توضیح قابل مشاهده برای طرفین"
                    }}<textarea
                        v-model="change.reason"
                        name="reason"
                        required
                        maxlength="2000"
                    />
                </label>
                <p
                    v-for="error in change.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn secondary" :disabled="change.processing">
                    ثبت تغییر
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>تاریخچه پیگیری</h2>
            <p v-if="!events.data.length" class="muted">تغییری ثبت نشده است.</p>
            <article v-for="(event, index) in events.data" :key="index">
                <h3>
                    {{ actions[event.action] }}
                    <span v-if="event.internal">· داخلی</span>
                </h3>
                <p>{{ event.reason }}</p>
                <small>{{ dateTime(event.created_at) }}</small>
            </article>
            <Pagination :page="events" />
        </section>
    </Shell>
</template>
