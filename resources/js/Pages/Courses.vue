<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import CohortCheckout from "../Components/CohortCheckout.vue";
const props = defineProps({ courses: Array, query: String });
const q = ref(props.query);
const stages = ["", "خودشناسی شغلی", "تصمیم‌گیری شغلی", "بازنگری شغلی"];
const money = (n) =>
    n ? new Intl.NumberFormat("fa-IR").format(n / 10) + " تومان" : "رایگان";
const type = { live: "کلاس زنده", recorded: "ویدیویی", hybrid: "ترکیبی" };
function search() {
    router.get(
        "/courses",
        { q: q.value },
        { preserveState: true, replace: true },
    );
}
</script>
<template>
    <Head title="کشف دوره‌ها" /><Shell title="دوره‌ها"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">یادگیری، برای مسیر شما</span>
                <h1>قدم بعدی را آگاهانه انتخاب کنید</h1>
                <p class="muted">
                    دوره‌های کاردرست را ببینید و از جایی که هستید شروع کنید.
                </p>
            </div>
        </section>
        <form class="search-bar glass" @submit.prevent="search">
            <Icon name="Search" /><input
                v-model="q"
                aria-label="جست‌وجوی دوره"
                placeholder="دنبال چه دوره‌ای می‌گردید؟"
            /><button class="btn secondary">جست‌وجو</button>
        </form>
        <Empty
            v-if="!courses.length"
            icon="Compass"
            :title="
                query ? 'دوره‌ای با این عنوان پیدا نشد' : 'دوره تازه در راه است'
            "
            :text="
                query
                    ? 'عنوان دیگری جست‌وجو کنید.'
                    : 'دوره‌های منتشرشده اینجا نمایش داده خواهند شد.'
            "
        />
        <section class="course-grid">
            <article
                v-for="course in courses"
                :key="course.id"
                class="course-card glass"
            >
                <div class="course-visual" :class="'stage-' + course.stage">
                    <Icon
                        :name="
                            course.stage === 1
                                ? 'Fingerprint'
                                : course.stage === 2
                                  ? 'Signpost'
                                  : 'RefreshCw'
                        "
                        :size="60"
                    /><span class="small-chip">{{ stages[course.stage] }}</span>
                </div>
                <div class="course-body">
                    <h2>{{ course.title }}</h2>
                    <p>{{ course.description }}</p>
                    <CohortCheckout
                        v-for="cohort in course.cohorts"
                        :key="cohort.id"
                        :cohort="cohort"
                    />
                    <div class="course-price">{{ money(course.price) }}</div>
                    <span v-if="!course.cohorts.length" class="muted caption"
                        >ثبت‌نام این دوره فعلاً بسته است.</span
                    >
                </div>
            </article>
        </section></Shell
    >
</template>
