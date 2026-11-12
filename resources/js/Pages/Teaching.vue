<script setup>
import { ref, computed } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import ReviewForm from "../Components/ReviewForm.vue";
import {
    fa,
    dateTime,
    tehranInput,
    providers,
    attendanceStatuses,
} from "../dates";
const props = defineProps({
    course: Object,
    role: String,
    sections: Array,
    cohorts: Array,
    cohort: Object,
    sessions: Array,
    assignments: Array,
    members: Array,
    submissions: Array,
    attendances: Array,
});
const tab = ref("lessons");
const lessonId = ref(null);
const sessionId = ref(null);
const assignmentId = ref(null);
const formOpen = ref(false);
const sessionOpen = ref(false);
const assignmentOpen = ref(false);
const section = useForm({ title: "", position: props.sections.length + 1 });
const lesson = useForm({
    course_section_id: props.sections[0]?.id || "",
    title: "",
    body: "",
    media_kind: "none",
    media_url: "",
    duration_seconds: 0,
    position: 1,
    is_published: false,
    available_at: "",
});
const live = useForm({
    title: "",
    provider: "meet",
    meeting_url: "",
    starts_at: "",
    ends_at: "",
    is_published: false,
    recording_kind: "none",
    recording_url: "",
});
const assignment = useForm({
    title: "",
    instructions: "",
    max_score: 100,
    is_published: false,
    available_at: "",
    due_at: "",
    closes_at: "",
});
const attendance = useForm({ enrollment_id: null, status: "present" });
const fixedMax = computed(
    () =>
        !!assignmentId.value &&
        (props.assignments.find((a) => a.id === assignmentId.value)
            ?.submissions_count || 0) > 0,
);
const prefix = computed(() => `/teaching/${props.course.id}`);
function sectionSave() {
    section.post(prefix.value + "/sections", {
        preserveScroll: true,
        onSuccess: () => {
            section.reset("title");
            section.position = props.sections.length + 1;
        },
    });
}
function newLesson() {
    lesson.reset();
    lesson.course_section_id = props.sections[0]?.id || "";
    lessonId.value = null;
    formOpen.value = true;
}
function editLesson(l) {
    for (const key of Object.keys(lesson.data())) lesson[key] = l[key] ?? "";
    lesson.available_at = tehranInput(l.available_at);
    lessonId.value = l.id;
    formOpen.value = true;
}
function saveLesson() {
    lesson[lessonId.value ? "patch" : "post"](
        prefix.value +
            "/lessons" +
            (lessonId.value ? "/" + lessonId.value : ""),
        { preserveScroll: true, onSuccess: () => (formOpen.value = false) },
    );
}
function editSession(s = null) {
    live.reset();
    sessionId.value = s?.id || null;
    if (s) {
        for (const key of Object.keys(live.data())) live[key] = s[key] ?? "";
        live.starts_at = tehranInput(s.starts_at);
        live.ends_at = tehranInput(s.ends_at);
    }
    sessionOpen.value = true;
}
function saveSession() {
    live[sessionId.value ? "patch" : "post"](
        `${prefix.value}/cohorts/${props.cohort.id}/sessions${sessionId.value ? "/" + sessionId.value : ""}`,
        { preserveScroll: true, onSuccess: () => (sessionOpen.value = false) },
    );
}
function editAssignment(a = null) {
    assignment.reset();
    assignmentId.value = a?.id || null;
    if (a) {
        for (const key of Object.keys(assignment.data()))
            assignment[key] = a[key] ?? "";
        for (const key of ["available_at", "due_at", "closes_at"])
            assignment[key] = tehranInput(a[key]);
    }
    assignmentOpen.value = true;
}
function saveAssignment() {
    assignment[assignmentId.value ? "patch" : "post"](
        `${prefix.value}/cohorts/${props.cohort.id}/assignments${assignmentId.value ? "/" + assignmentId.value : ""}`,
        {
            preserveScroll: true,
            onSuccess: () => (assignmentOpen.value = false),
        },
    );
}
function markAttendance(session, id, status) {
    if (!status) return;
    attendance.enrollment_id = id;
    attendance.status = status;
    attendance.post(`${prefix.value}/sessions/${session}/attendance`, {
        preserveScroll: true,
    });
}
const attendanceOf = (session, enrollment) =>
    props.attendances.find(
        (a) => a.live_session_id === session && a.enrollment_id === enrollment,
    )?.status || "";
const nameOf = (id) =>
    props.members.find((m) => m.enrollment_id === id)?.name || "دانشجو";
const assignmentOf = (id) => props.assignments.find((a) => a.id === id);
</script>
<template>
    <Head :title="'مدیریت ' + course.title" /><Shell
        title="مدیریت آموزش"
        :role="role"
        ><Link
            :href="role === 'admin' ? '/admin/courses' : '/coach'"
            class="text-link"
            ><Icon name="ArrowRight" :size="16" />بازگشت به دوره‌ها</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">فضای تدریس</span>
                <h1>{{ course.title }}</h1>
                <p class="muted">
                    محتوای درس‌ها بین نوبت‌ها مشترک است؛ کلاس، تمرین و اعضا به
                    نوبت انتخاب‌شده تعلق دارند.
                </p>
            </div>
            <label v-if="cohorts.length"
                >نوبت برگزاری<select
                    :value="cohort?.id"
                    @change="
                        router.get(prefix, { cohort: $event.target.value })
                    "
                >
                    <option v-for="c in cohorts" :key="c.id" :value="c.id">
                        {{ c.title }}
                    </option>
                </select></label
            >
        </section>
        <nav class="learning-tabs" aria-label="مدیریت دوره">
            <button
                v-for="[key, label, icon] in [
                    ['lessons', 'درس‌ها', 'BookOpen'],
                    ['sessions', 'کلاس و ضبط', 'Video'],
                    ['assignments', 'تمرین و بازخورد', 'FileText'],
                    ['members', 'اعضا و حضور', 'Users'],
                ]"
                :key="key"
                :class="{ active: tab === key }"
                @click="tab = key"
                :aria-pressed="tab === key"
            >
                <Icon :name="icon" :size="17" />{{ label }}
            </button>
        </nav>
        <section v-if="tab === 'lessons'" class="glass panel-card">
            <div class="section-heading">
                <h2>سرفصل و درس</h2>
                <button
                    class="btn primary"
                    @click="newLesson"
                    :disabled="!sections.length"
                >
                    <Icon name="Plus" :size="17" />درس جدید
                </button>
            </div>
            <form class="inline-form" @submit.prevent="sectionSave">
                <label
                    >عنوان سرفصل<input
                        v-model="section.title"
                        required
                        maxlength="150" /></label
                ><label class="position-input"
                    >ترتیب<input
                        type="number"
                        v-model.number="section.position"
                        min="0"
                        max="10000"
                        required /></label
                ><button class="btn secondary" :disabled="section.processing">
                    افزودن سرفصل
                </button>
            </form>
            <p v-for="(v, k) in section.errors" :key="k" class="field-error">
                {{ v }}
            </p>
            <form
                v-if="formOpen"
                class="form-grid editor-form"
                @submit.prevent="saveLesson"
            >
                <h3 class="span-all">
                    {{ lessonId ? "ویرایش درس" : "درس جدید" }}
                </h3>
                <label
                    >سرفصل<select v-model="lesson.course_section_id" required>
                        <option v-for="s in sections" :key="s.id" :value="s.id">
                            {{ s.title }}
                        </option>
                    </select></label
                ><label
                    >عنوان درس<input
                        v-model="lesson.title"
                        required
                        maxlength="150" /></label
                ><label class="span-all"
                    >متن درس<textarea
                        v-model="lesson.body"
                        rows="6"
                        maxlength="50000"
                    ></textarea></label
                ><label
                    >نوع ویدیو<select v-model="lesson.media_kind">
                        <option value="none">بدون ویدیو</option>
                        <option value="mp4">لینک MP4</option>
                        <option value="hls">لینک HLS آروان</option>
                        <option value="embed">نمایشگر آروان</option>
                    </select></label
                ><label v-if="lesson.media_kind !== 'none'"
                    >لینک ویدیو<input
                        v-model="lesson.media_url"
                        type="url"
                        dir="ltr"
                        required
                        placeholder="https://…"
                        maxlength="2000" /></label
                ><label
                    >مدت ویدیو (ثانیه)<input
                        v-model.number="lesson.duration_seconds"
                        type="number"
                        min="0"
                        max="86400"
                        required /></label
                ><label
                    >ترتیب نمایش<input
                        v-model.number="lesson.position"
                        type="number"
                        min="0"
                        max="10000"
                        required /></label
                ><label
                    >دسترسی از تاریخ (اختیاری؛ ساعت تهران)<input
                        v-model="lesson.available_at"
                        type="datetime-local" /></label
                ><label class="check-label"
                    ><input
                        v-model="lesson.is_published"
                        type="checkbox"
                    />منتشر شود</label
                >
                <div class="span-all">
                    <p
                        v-for="(v, k) in lesson.errors"
                        :key="k"
                        class="field-error"
                        role="alert"
                    >
                        {{ v }}
                    </p>
                    <div class="learning-actions">
                        <button
                            class="btn primary"
                            :disabled="lesson.processing"
                        >
                            ذخیره درس</button
                        ><button
                            type="button"
                            class="btn secondary"
                            @click="formOpen = false"
                        >
                            انصراف
                        </button>
                    </div>
                </div>
            </form>
            <Empty
                v-if="!sections.length"
                icon="BookOpen"
                title="از اولین سرفصل شروع کنید"
                text="بعد از ساخت سرفصل می‌توانید درس، متن و لینک ویدیو را اضافه کنید."
            />
            <section v-for="s in sections" :key="s.id" class="teaching-section">
                <h3>{{ s.title }}</h3>
                <div v-for="l in s.lessons" :key="l.id" class="simple-row">
                    <Icon
                        :name="l.media_kind === 'none' ? 'FileText' : 'Play'"
                    />
                    <div class="grow">
                        <strong>{{ l.title }}</strong
                        ><small
                            >{{ fa(l.duration_seconds) }} ثانیه · ترتیب
                            {{ fa(l.position)
                            }}<span v-if="l.available_at">
                                · از {{ dateTime(l.available_at) }}</span
                            ></small
                        >
                    </div>
                    <span
                        :class="['badge', l.is_published ? 'teal' : 'gold']"
                        >{{ l.is_published ? "منتشرشده" : "پیش‌نویس" }}</span
                    ><button class="text-link" @click="editLesson(l)">
                        ویرایش
                    </button>
                </div>
            </section>
        </section>
        <section v-if="tab === 'sessions'" class="glass panel-card">
            <div class="section-heading">
                <h2>کلاس‌های {{ cohort?.title }}</h2>
                <button
                    class="btn primary"
                    @click="editSession()"
                    :disabled="!cohort"
                >
                    <Icon name="Plus" :size="17" />جلسه جدید
                </button>
            </div>
            <form
                v-if="sessionOpen"
                class="form-grid editor-form"
                @submit.prevent="saveSession"
            >
                <label
                    >عنوان جلسه<input
                        v-model="live.title"
                        required
                        maxlength="150" /></label
                ><label
                    >بستر برگزاری<select v-model="live.provider">
                        <option
                            v-for="(label, key) in providers"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label class="span-all"
                    >لینک ورود به جلسه<input
                        v-model="live.meeting_url"
                        type="url"
                        dir="ltr"
                        placeholder="https://…"
                        required
                        maxlength="2000" /></label
                ><label
                    >شروع (ساعت تهران)<input
                        v-model="live.starts_at"
                        type="datetime-local"
                        required /></label
                ><label
                    >پایان (ساعت تهران)<input
                        v-model="live.ends_at"
                        type="datetime-local"
                        required /></label
                ><label
                    >ضبط جلسه<select v-model="live.recording_kind">
                        <option value="none">هنوز ضبط ندارد</option>
                        <option value="mp4">لینک MP4</option>
                        <option value="hls">لینک HLS آروان</option>
                        <option value="embed">نمایشگر آروان</option>
                    </select></label
                ><label v-if="live.recording_kind !== 'none'"
                    >لینک ضبط<input
                        v-model="live.recording_url"
                        type="url"
                        dir="ltr"
                        required
                        maxlength="2000" /></label
                ><label class="check-label"
                    ><input v-model="live.is_published" type="checkbox" />جلسه
                    برای دانشجویان منتشر شود</label
                >
                <p class="caption muted">
                    ضبط جلسه پس از پایان زمان جلسه قابل مشاهده است.
                </p>
                <div class="span-all">
                    <p
                        v-for="(v, k) in live.errors"
                        :key="k"
                        class="field-error"
                        role="alert"
                    >
                        {{ v }}
                    </p>
                    <div class="learning-actions">
                        <button class="btn primary" :disabled="live.processing">
                            ذخیره جلسه</button
                        ><button
                            type="button"
                            class="btn secondary"
                            @click="sessionOpen = false"
                        >
                            انصراف
                        </button>
                    </div>
                </div>
            </form>
            <Empty
                v-if="!sessions.length"
                icon="Video"
                title="جلسه‌ای ثبت نشده است"
                text="زمان کلاس و لینک گوگل میت، اسکای‌روم یا زوم را وارد کنید."
            />
            <div v-for="s in sessions" :key="s.id" class="simple-row">
                <Icon name="Video" />
                <div class="grow">
                    <strong>{{ s.title }}</strong
                    ><small
                        >{{ providers[s.provider] }} ·
                        {{ dateTime(s.starts_at) }}</small
                    >
                </div>
                <span :class="['badge', s.is_published ? 'teal' : 'gold']">{{
                    s.is_published ? "منتشرشده" : "پیش‌نویس"
                }}</span
                ><button class="text-link" @click="editSession(s)">
                    ویرایش و ضبط
                </button>
            </div>
        </section>
        <section v-if="tab === 'assignments'" class="glass panel-card">
            <div class="section-heading">
                <h2>تمرین و بازخورد</h2>
                <button
                    class="btn primary"
                    @click="editAssignment()"
                    :disabled="!cohort"
                >
                    <Icon name="Plus" :size="17" />تمرین جدید
                </button>
            </div>
            <form
                v-if="assignmentOpen"
                class="form-grid editor-form"
                @submit.prevent="saveAssignment"
            >
                <p v-if="fixedMax" class="caption muted span-all">
                    پس از دریافت اولین پاسخ، سقف نمره ثابت می‌ماند تا نمره‌های
                    قبلی تغییر معنا ندهند.
                </p>
                <label
                    >عنوان<input
                        v-model="assignment.title"
                        required
                        maxlength="150" /></label
                ><label
                    >سقف نمره<input
                        v-model.number="assignment.max_score"
                        :disabled="fixedMax"
                        type="number"
                        min="1"
                        max="1000"
                        required /></label
                ><label class="span-all"
                    >راهنمای تمرین<textarea
                        v-model="assignment.instructions"
                        required
                        maxlength="20000"
                        rows="4"
                    ></textarea></label
                ><label
                    >نمایش از تاریخ (ساعت تهران)<input
                        v-model="assignment.available_at"
                        type="datetime-local" /></label
                ><label
                    >موعد پیشنهادی تحویل (ساعت تهران)<input
                        v-model="assignment.due_at"
                        type="datetime-local" /></label
                ><label
                    >بستن ارسال در تاریخ (اختیاری)<input
                        v-model="assignment.closes_at"
                        type="datetime-local" /></label
                ><label class="check-label"
                    ><input
                        v-model="assignment.is_published"
                        type="checkbox"
                    />منتشر شود</label
                >
                <p class="muted caption span-all">
                    موعد پیشنهادی به‌تنهایی ارسال را نمی‌بندد. فقط تاریخ «بستن
                    ارسال» جلوی پاسخ جدید را می‌گیرد.
                </p>
                <div class="span-all">
                    <p
                        v-for="(v, k) in assignment.errors"
                        :key="k"
                        class="field-error"
                        role="alert"
                    >
                        {{ v }}
                    </p>
                    <div class="learning-actions">
                        <button
                            class="btn primary"
                            :disabled="assignment.processing"
                        >
                            ذخیره تمرین</button
                        ><button
                            type="button"
                            class="btn secondary"
                            @click="assignmentOpen = false"
                        >
                            انصراف
                        </button>
                    </div>
                </div>
            </form>
            <Empty
                v-if="!assignments.length"
                icon="FileText"
                title="هنوز تمرینی تعریف نشده"
                text="تمرین نوبت انتخاب‌شده را بسازید و بعد پاسخ‌های دانشجویان را بررسی کنید."
            />
            <div v-for="a in assignments" :key="a.id" class="simple-row">
                <Icon name="FileText" />
                <div class="grow">
                    <strong>{{ a.title }}</strong
                    ><small
                        >از {{ fa(a.max_score) }} ·
                        {{ a.is_published ? "منتشرشده" : "پیش‌نویس" }}</small
                    >
                </div>
                <button class="text-link" @click="editAssignment(a)">
                    ویرایش
                </button>
            </div>
            <div class="section-heading review-heading">
                <h2>آخرین پاسخ دانشجویان</h2>
                <span class="small-chip"
                    >{{
                        fa(
                            submissions.filter((s) => s.status === "submitted")
                                .length,
                        )
                    }}
                    منتظر بررسی</span
                >
            </div>
            <p v-if="!submissions.length" class="muted caption">
                هنوز پاسخی ثبت نشده است.
            </p>
            <ReviewForm
                v-for="s in submissions"
                :key="s.id"
                :submission="s"
                :assignment="assignmentOf(s.assignment_id)"
                :member-name="nameOf(s.enrollment_id)"
            />
        </section>
        <section v-if="tab === 'members'" class="glass panel-card">
            <div class="section-heading">
                <h2>اعضا و حضور</h2>
                <span class="small-chip"
                    >{{ fa(members.length) }} ثبت‌نام فعال</span
                >
            </div>
            <Empty
                v-if="!members.length"
                icon="Users"
                title="هنوز ثبت‌نام فعالی وجود ندارد"
                text="دانشجویان این نوبت بعد از تأیید ثبت‌نام نمایش داده می‌شوند."
            />
            <article
                v-for="m in members"
                :key="m.enrollment_id"
                class="teaching-member"
            >
                <div class="section-heading">
                    <h3>{{ m.name }}</h3>
                    <span class="badge teal"
                        >{{ fa(m.progress.percent) }}٪ درس‌ها تکمیل شده</span
                    >
                </div>
                <div v-for="s in sessions" :key="s.id" class="attendance-row">
                    <span
                        >{{ s.title
                        }}<small>{{ dateTime(s.starts_at) }}</small></span
                    ><label
                        ><span class="sr-only"
                            >وضعیت حضور {{ m.name }} در {{ s.title }}</span
                        ><select
                            :value="attendanceOf(s.id, m.enrollment_id)"
                            :disabled="attendance.processing"
                            @change="
                                markAttendance(
                                    s.id,
                                    m.enrollment_id,
                                    $event.target.value,
                                )
                            "
                        >
                            <option value="">ثبت نشده</option>
                            <option
                                v-for="(label, key) in attendanceStatuses"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select></label
                    >
                </div>
            </article>
        </section>
    </Shell>
</template>
