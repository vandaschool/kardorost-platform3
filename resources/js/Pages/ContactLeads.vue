<script setup>
import { watch } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime } from "../dates";
const props = defineProps({
    leads: Object,
    selected: Object,
    events: Object,
    filters: Object,
});
const statuses = {
    new: "تازه",
    working: "در حال پیگیری",
    qualified: "مناسب همکاری",
    closed: "بسته‌شده",
};
const audiences = {
    individual: "فرد",
    company: "سازمان یا شرکت",
    university: "دانشگاه",
};
const subjects = {
    training: "آموزش و دوره",
    consultation: "مشاوره",
    platform: "آشنایی و همکاری با کاردرست",
};
const search = useForm({ ...props.filters });
const follow = useForm({
    revision: props.selected?.revision,
    status: props.selected?.status || "working",
    note: "",
});
watch(
    () => props.selected?.revision,
    (revision) => {
        follow.revision = revision;
        follow.status = props.selected.status;
    },
);
function save() {
    follow.post(`/admin/contact-leads/${props.selected.id}/follow-up`, {
        preserveScroll: true,
        onSuccess: () => follow.reset("note"),
    });
}
</script>
<template>
    <Head title="درخواست‌های سایت" /><Shell
        title="درخواست‌های سایت"
        role="admin"
        ><section class="page-intro">
            <div>
                <h1>درخواست‌های سایت</h1>
                <p class="muted">
                    پیگیری دستی درخواست فرد، سازمان یا دانشگاه؛ شماره اعلام‌شده
                    در فرم تأیید موبایلی نشده است.
                </p>
            </div>
            <a href="/contact" class="btn secondary">فرم عمومی ارتباط</a>
        </section>
        <section
            v-if="selected"
            class="panel glass consultation-card consultation-section"
            data-contact-lead
        >
            <h2>{{ selected.name }}</h2>
            <p>
                <bdi>{{ selected.phone }}</bdi> · موبایل اعلام‌شده و تأییدنشده
            </p>
            <p>
                {{ audiences[selected.audience] }} ·
                {{ selected.organization_name }} ·
                {{ subjects[selected.subject] }}
            </p>
            <p class="preserve-lines">{{ selected.message }}</p>
            <p>رضایت پیگیری: {{ dateTime(selected.consented_at) }}</p>
            <form
                class="stack-form"
                data-contact-follow-up
                @submit.prevent="save"
            >
                <label
                    >وضعیت<select
                        name="lead_status"
                        v-model="follow.status"
                        :disabled="follow.processing"
                    >
                        <option
                            v-for="(label, key) in statuses"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >یادداشت پیگیری<textarea
                        name="lead_note"
                        v-model="follow.note"
                        required
                        minlength="3"
                        maxlength="2000"
                        :disabled="follow.processing"
                    ></textarea>
                </label>
                <p class="muted">
                    برای ایجاد سازمان و تعیین مدیر، از بخش «سازمان‌ها» استفاده
                    کنید.
                </p>
                <p
                    v-for="(error, key) in follow.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="follow.processing">
                    ثبت پیگیری
                </button>
            </form>
            <h3>سوابق پیگیری</h3>
            <article
                v-for="event in events.data"
                :key="event.revision"
                class="assessment-editor-item"
            >
                <p>{{ event.actor_name }} · {{ dateTime(event.created_at) }}</p>
                <p>
                    {{
                        event.from_status
                            ? event.from_status === event.to_status
                                ? "یادداشت پیگیری"
                                : statuses[event.from_status] +
                                  " ← " +
                                  statuses[event.to_status]
                            : "دریافت درخواست"
                    }}
                </p>
                <p class="preserve-lines">{{ event.note }}</p>
            </article>
            <Pagination :links="events.links" />
        </section>
        <form
            class="panel glass consultation-card stack-form consultation-section"
            @submit.prevent="search.get('/admin/contact-leads')"
        >
            <label
                >وضعیت<select name="lead_filter_status" v-model="search.status">
                    <option value="all">همه</option>
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label
                >درخواست‌دهنده<select
                    name="lead_filter_audience"
                    v-model="search.audience"
                >
                    <option value="all">همه</option>
                    <option
                        v-for="(label, key) in audiences"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label
                >موبایل دقیق (اختیاری)<input
                    name="lead_filter_phone"
                    v-model="search.phone"
                    inputmode="tel"
                    maxlength="30" /></label
            ><button class="btn secondary" :disabled="search.processing">
                نمایش فهرست
            </button>
        </form>
        <section class="consultation-section">
            <p v-if="!leads.data.length" class="muted">
                درخواستی با این فیلتر نیست.
            </p>
            <article
                v-for="lead in leads.data"
                :key="lead.id"
                class="panel glass consultation-card consultation-section"
                :data-contact-lead-row="lead.id"
            >
                <h2>{{ lead.name }}</h2>
                <p>
                    {{ statuses[lead.status] }} ·
                    {{ audiences[lead.audience] }} ·
                    {{ lead.organization_name }}
                </p>
                <p>
                    <bdi>{{ lead.phone }}</bdi> ·
                    {{ dateTime(lead.created_at) }}
                </p>
                <Link
                    :href="`/admin/contact-leads/${lead.id}`"
                    class="text-link"
                    >مشاهده و پیگیری</Link
                >
            </article>
            <Pagination :links="leads.links" /></section
    ></Shell>
</template>
