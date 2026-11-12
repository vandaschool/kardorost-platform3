<script setup>
import { ref, watch, onBeforeUnmount } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import VideoPlayer from "../Components/VideoPlayer.vue";
import AssignmentCard from "../Components/AssignmentCard.vue";
import { fa, dateTime, providers, attendanceStatuses } from "../dates";
const props = defineProps({
    enrollment: Object,
    course: Object,
    cohort: Object,
    sections: Array,
    selected: Object,
    recording: Object,
    progress: Object,
    sessions: Array,
    assignments: Array,
});
const initial = new URLSearchParams(window.location.search).get("tab");
const tab = ref(
    ["lessons", "sessions", "assignments"].includes(initial)
        ? initial
        : "lessons",
);
const saving = ref(false);
const saveError = ref("");
const heartbeat = new Map();
let disposed = false;
function selectLesson(id) {
    router.get(
        `/learning/${props.enrollment.id}`,
        { lesson: id, tab: "lessons" },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (tab.value = "lessons"),
        },
    );
}
function openRecording(id) {
    router.get(
        `/learning/${props.enrollment.id}`,
        { recording: id, tab: "sessions" },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (tab.value = "sessions"),
        },
    );
}
function complete() {
    saving.value = true;
    router.post(
        `/learning/${props.enrollment.id}/lessons/${props.selected.id}/progress`,
        { complete: true },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}
async function savePosition({ id, seconds }) {
    if (disposed || !id || heartbeat.has(id)) return;
    heartbeat.set(id, true);
    try {
        const cookie = document.cookie
            .split("; ")
            .find((x) => x.startsWith("XSRF-TOKEN="));
        const token = cookie
            ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length))
            : "";
        const result = await fetch(
            `/learning/${props.enrollment.id}/lessons/${id}/progress`,
            {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": token,
                },
                body: JSON.stringify({ position_seconds: seconds }),
            },
        );
        if (!result.ok) throw new Error("save");
        saveError.value = "";
    } catch {
        if (!disposed)
            saveError.value =
                "ذخیره محل توقف ویدیو انجام نشد. اتصال را بررسی کنید.";
    } finally {
        heartbeat.delete(id);
    }
}
watch(
    () => props.enrollment.id,
    () => {
        tab.value = "lessons";
    },
);
onBeforeUnmount(() => {
    disposed = true;
});
</script>
<template>
    <Head :title="course.title" /><Shell title="دوره من"
        ><Link href="/learning" class="text-link"
            ><Icon name="ArrowRight" :size="17" />بازگشت به یادگیری من</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{ cohort.title }}</span>
                <h1>{{ course.title }}</h1>
                <p class="muted">
                    درس‌ها، کلاس‌ها و تمرین‌های این دوره، همه در همین‌جا.
                </p>
            </div>
            <div class="room-progress">
                <strong>{{ fa(progress.percent) }}٪</strong
                ><span
                    >درس‌های تکمیل‌شده: {{ fa(progress.completed) }} از
                    {{ fa(progress.total) }}</span
                ><progress
                    :value="progress.percent"
                    max="100"
                    aria-label="پیشرفت درس‌های دوره"
                ></progress>
            </div>
        </section>
        <nav class="learning-tabs" aria-label="بخش‌های دوره">
            <button
                v-for="[key, label, icon] in [
                    ['lessons', 'درس‌ها', 'BookOpen'],
                    ['sessions', 'کلاس‌ها و ضبط‌ها', 'Video'],
                    ['assignments', 'تمرین‌ها', 'FileText'],
                ]"
                :key="key"
                :class="{ active: tab === key }"
                :aria-pressed="tab === key"
                @click="tab = key"
            >
                <Icon :name="icon" :size="18" />{{ label
                }}<span v-if="key === 'assignments' && assignments.length">{{
                    fa(assignments.length)
                }}</span>
            </button>
        </nav>
        <section v-if="tab === 'lessons'" class="classroom-grid">
            <aside class="glass curriculum">
                <div class="section-heading">
                    <h2>مسیر یادگیری</h2>
                    <Icon name="Route" :size="19" />
                </div>
                <Empty
                    v-if="!sections.length"
                    icon="BookOpen"
                    title="درس تازه در راه است"
                    text="مدرس هنوز درسی منتشر نکرده است."
                />
                <section
                    v-for="section in sections"
                    :key="section.id"
                    class="curriculum-section"
                >
                    <h3>{{ section.title }}</h3>
                    <button
                        v-for="lesson in section.lessons"
                        :key="lesson.id"
                        :class="[
                            'lesson-button',
                            {
                                selected: selected?.id === lesson.id,
                                done: lesson.completed,
                            },
                        ]"
                        :disabled="lesson.locked"
                        @click="selectLesson(lesson.id)"
                    >
                        <Icon
                            :name="
                                lesson.locked
                                    ? 'LockKeyhole'
                                    : lesson.completed
                                      ? 'CircleCheck'
                                      : 'Play'
                            "
                            :size="18"
                        /><span
                            >{{ lesson.title
                            }}<small v-if="lesson.locked"
                                >از {{ dateTime(lesson.available_at) }}</small
                            ><small v-else-if="lesson.duration_seconds"
                                >{{
                                    fa(Math.ceil(lesson.duration_seconds / 60))
                                }}
                                دقیقه</small
                            ></span
                        ><Icon
                            v-if="selected?.id === lesson.id"
                            name="ChevronLeft"
                            :size="15"
                        />
                    </button>
                </section>
            </aside>
            <article class="glass lesson-panel">
                <template v-if="selected"
                    ><span class="eyebrow">درس جاری</span>
                    <h2>{{ selected.title }}</h2>
                    <VideoPlayer
                        v-if="selected.media_url"
                        :key="'lesson-' + selected.id"
                        :url="selected.media_url"
                        :kind="selected.media_kind"
                        :title="selected.title"
                        :resume-at="selected.position_seconds"
                        :identity="selected.id"
                        @position="savePosition"
                    />
                    <p v-if="saveError" class="field-error" role="status">
                        {{ saveError }}
                    </p>
                    <div v-if="selected.body" class="lesson-text">
                        {{ selected.body }}
                    </div>
                    <div class="lesson-finish">
                        <span v-if="selected.completed" class="badge teal"
                            ><Icon name="CircleCheck" :size="16" />این درس را
                            تکمیل کرده‌اید</span
                        ><button
                            v-else
                            class="btn primary"
                            :disabled="saving"
                            @click="complete"
                        >
                            <Icon name="Check" :size="18" />درس را تکمیل کردم</button
                        ><span class="caption muted"
                            >تکمیل درس با تأیید خود شما ثبت می‌شود.</span
                        >
                    </div></template
                ><Empty
                    v-else
                    icon="BookOpen"
                    title="درس آماده‌ای برای نمایش نیست"
                    text="با انتشار یا رسیدن زمان دسترسی درس، آن را در همین صفحه می‌بینید."
                />
            </article>
        </section>
        <section v-if="tab === 'sessions'" class="glass panel-card">
            <template v-if="recording"
                ><div class="section-heading">
                    <h2>{{ recording.title }} · ضبط جلسه</h2>
                </div>
                <VideoPlayer
                    :key="'recording-' + recording.id"
                    :url="recording.media_url"
                    :kind="recording.media_kind"
                    :title="recording.title" /></template
            ><Empty
                v-if="!sessions.length"
                icon="Video"
                title="هنوز کلاسی ثبت نشده"
                text="زمان کلاس‌ها و ضبط جلسه‌ها در این بخش قرار می‌گیرند."
            />
            <article
                v-for="session in sessions"
                :key="session.id"
                class="live-session-card"
            >
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">{{
                            providers[session.provider]
                        }}</span>
                        <h3>{{ session.title }}</h3>
                    </div>
                    <span v-if="session.attendance" class="badge teal">{{
                        attendanceStatuses[session.attendance]
                    }}</span>
                </div>
                <p class="muted caption">
                    <Icon name="CalendarDays" :size="16" />{{
                        dateTime(session.starts_at)
                    }}
                    تا {{ dateTime(session.ends_at) }}
                </p>
                <div class="learning-actions">
                    <a
                        v-if="session.joinable"
                        :href="`/learning/${enrollment.id}/sessions/${session.id}/join`"
                        class="btn primary"
                        target="_blank"
                        rel="noopener noreferrer"
                        >ورود به کلاس<Icon name="ExternalLink" :size="16" /></a
                    ><small v-else class="muted"
                        >ورود از ۱۵ دقیقه پیش از شروع تا پایان جلسه فعال
                        است.</small
                    ><button
                        v-if="session.has_recording"
                        class="btn secondary"
                        @click="openRecording(session.id)"
                    >
                        <Icon name="Play" :size="16" />مشاهده ضبط
                    </button>
                </div>
                <p class="muted caption">
                    باز کردن لینک به‌تنهایی به معنی ثبت حضور نیست.
                </p>
            </article>
        </section>
        <section v-if="tab === 'assignments'" class="glass panel-card">
            <div class="section-heading">
                <h2>تمرین و بازخورد</h2>
                <span class="caption muted"
                    >هر نسخه پاسخ و بازخورد آن نگهداری می‌شود.</span
                >
            </div>
            <Empty
                v-if="!assignments.length"
                icon="FileText"
                title="تمرین تازه‌ای منتشر نشده"
                text="تمرین‌های دوره پس از انتشار در این بخش نمایش داده می‌شوند."
            /><AssignmentCard
                v-for="a in assignments"
                :key="a.id"
                :assignment="a"
                :enrollment-id="enrollment.id"
            />
        </section>
    </Shell>
</template>
