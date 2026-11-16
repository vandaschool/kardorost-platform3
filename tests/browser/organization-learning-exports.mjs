import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
const base = process.env.BROWSER_BASE_URL;
const password = process.env.BROWSER_PASSWORD;
assert(
    base &&
        password &&
        process.env.BROWSER_STUDENT_PHONE &&
        process.env.BROWSER_COACH_PHONE &&
        process.env.BROWSER_ADMIN_PHONE,
    "Supply isolated browser fixture credentials and BROWSER_BASE_URL.",
);
assert(
    ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
    "This workflow only mutates a local fixture.",
);
const target = await (
    await fetch(
        "http://127.0.0.1:9222/json/new?" + encodeURIComponent(base + "/login"),
        { method: "PUT" },
    )
).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve) =>
    socket.addEventListener("open", resolve, { once: true }),
);
let id = 0;
const pending = new Map();
const exceptions = [];
const activeRequests = new Set();
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.requestWillBeSent" &&
        ["XHR", "Fetch"].includes(m.params.type)
    )
        activeRequests.add(m.params.requestId);
    if (["Network.loadingFinished", "Network.loadingFailed"].includes(m.method))
        activeRequests.delete(m.params.requestId);
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            "console: " +
                m.params.args
                    .map((a) => a.description || a.value || "")
                    .join(" "),
        );
    if (m.method === "Runtime.exceptionThrown")
        exceptions.push(m.params.exceptionDetails.text);
    if (m.id) {
        const p = pending.get(m.id);
        if (m.error) p.reject(m.error);
        else p.resolve(m.result);
        pending.delete(m.id);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        socket.send(JSON.stringify({ id: n, method, params }));
    });
}
await send("Runtime.enable");
await send("Page.enable");
await send("Network.enable");
async function evaluate(expression) {
    const r = await send("Runtime.evaluate", {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
}
async function until(expression) {
    for (let i = 0; i < 100; i++) {
        if (await evaluate(expression)) return;
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error(
        "Timed out: " +
            expression +
            "\n" +
            (await evaluate("document.body.innerText")),
    );
}
async function goto(path) {
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main')`,
    );
}
async function fill(selector, value) {
    await evaluate(
        `(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
async function submit(selector) {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).requestSubmit()`,
    );
}
async function networkIdle() {
    let stable = 0;
    for (let index = 0; index < 100; index++) {
        stable = activeRequests.size === 0 ? stable + 1 : 0;
        if (stable >= 3) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(
        "Previous navigation did not finish before switching fixture accounts",
    );
}
async function login(role) {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8029" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-cohort-announcements-browser.sqlite",
    "Only extend the isolated local cohort fixture.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'||\App\Models\User::count()<8){throw new RuntimeException('Only the isolated existing fixture can be extended.');}if(\App\Models\Organization::where('name','گزارش خصوصی آزمایشی')->exists()){throw new RuntimeException('Learning export fixture already exists; inspect before rerunning.');}$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();$member=\App\Models\User::where('phone','989121234560')->firstOrFail();$outsider=\App\Models\User::where('phone','989121234566')->firstOrFail();$otherManager=\App\Models\User::factory()->create(['phone'=>'989122220000','password'=>'Browser-Only-2026']);$otherManager->roles()->attach(\App\Models\Role::where('key','org_admin')->value('id'));$organization=\App\Models\Organization::create(['name'=>'گزارش خصوصی آزمایشی','type'=>'company','status'=>'active']);$other=\App\Models\Organization::create(['name'=>'دانشگاه جداگانه آزمایشی','type'=>'university','status'=>'active']);$organization->members()->attach($manager->id,['kind'=>'manager']);$organization->members()->attach($member->id,['kind'=>'member']);$other->members()->attach($otherManager->id,['kind'=>'manager']);$other->members()->attach($outsider->id,['kind'=>'member']);$enrollment=\App\Models\Enrollment::where('user_id',$member->id)->firstOrFail();$section=\App\Models\CourseSection::factory()->create(['course_id'=>$enrollment->course_id]);$lessons=\App\Models\Lesson::factory()->count(2)->create(['course_section_id'=>$section->id,'body'=>'PRIVATE-CSV-LESSON-BODY','is_published'=>true]);$draft=\App\Models\Lesson::factory()->create(['course_section_id'=>$section->id,'is_published'=>false]);foreach([$lessons[0],$draft] as $lesson){\App\Models\LessonProgress::updateOrCreate(['enrollment_id'=>$enrollment->id,'lesson_id'=>$lesson->id],['completed_at'=>now()]);}foreach(['present','absent','excused'] as $status){$session=\App\Models\LiveSession::factory()->create(['cohort_id'=>$enrollment->cohort_id,'meeting_url'=>'https://meet.google.com/private-export-token']);\App\Models\Attendance::factory()->create(['enrollment_id'=>$enrollment->id,'live_session_id'=>$session->id,'status'=>$status,'recorded_by'=>$manager->id]);}for($i=0;$i<26;$i++){$cohort=\App\Models\Cohort::factory()->create(['course_id'=>$enrollment->course_id,'title'=>'نوبت سابقه آزمایشی '.$i]);\App\Models\Enrollment::factory()->create(['user_id'=>$member->id,'cohort_id'=>$cohort->id,'status'=>$i===0?'expired':'active']);}echo json_encode(['organization_id'=>$organization->id,'other_id'=>$other->id,'member_id'=>$member->id,'enrollment_id'=>$enrollment->id,'member_phone'=>$member->phone]);`;
const fixture = JSON.parse(
    execFileSync(php, ["-r", code], {
        cwd: process.cwd(),
        env,
        encoding: "utf8",
    }),
);
async function download(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,bom:Array.from(b.slice(0,3)),text:new TextDecoder().decode(b),cache:r.headers.get('cache-control'),disposition:r.headers.get('content-disposition'),robots:r.headers.get('x-robots-tag')};})()`,
    );
}
function csv(text) {
    const rows = [];
    let row = [],
        cell = "",
        quote = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quote) {
            if (c === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (c === '"') quote = false;
            else cell += c;
        } else if (c === '"') quote = true;
        else if (c === ",") {
            row.push(cell);
            cell = "";
        } else if (c === "\n") {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = "";
        } else if (c !== "\r") cell += c;
    }
    if (cell || row.length) {
        row.push(cell);
        rows.push(row);
    }
    return rows;
}
async function responsive() {
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await until(`innerWidth===${width}`);
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Member learning export overflow ${width}`,
        );
    }
}
const memberPath = "/organization/members/" + fixture.member_id;
const exportPath = memberPath + "/learning-export";
const adminPath =
    "/admin/organizations/" +
    fixture.organization_id +
    "/members/" +
    fixture.member_id +
    "/learning-export";
try {
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    await goto(memberPath);
    await until(
        "!!document.querySelector('[data-testid=member-learning-export]')",
    );
    assert((await evaluate("document.body.innerText")).includes("منقضی‌شده"));
    assert((await evaluate("document.body.innerText")).includes("خوداظهاری"));
    const link = await evaluate(
        "document.querySelector('[data-testid=member-learning-export]').getAttribute('href')",
    );
    const result = await download(link);
    assert.equal(result.status, 200);
    assert.deepEqual(result.bom, [239, 187, 191]);
    assert(result.cache.includes("no-store"));
    assert.equal(result.robots, "noindex, nofollow");
    assert(result.disposition.includes(".csv"));
    const rows = csv(result.text);
    assert.equal(rows.length, 28);
    assert(rows.every((r) => r.length === 14));
    const row = rows.find((r) => r[3] === String(fixture.enrollment_id));
    assert.deepEqual(row.slice(7, 13), ["2", "1", "50", "1", "1", "1"]);
    assert(rows.some((r) => r[6] === "منقضی‌شده"));
    assert(!result.text.includes(fixture.member_phone));
    assert(!result.text.includes("PRIVATE-CSV-LESSON-BODY"));
    assert(!result.text.includes("private-export-token"));
    await responsive();
    const forbiddenAdmin = await download(adminPath);
    assert.equal(forbiddenAdmin.status, 403);
    await login("admin");
    await goto(
        "/admin/organization-managers?organization=" + fixture.organization_id,
    );
    await until(
        `!!document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export]')`,
    );
    const adminLink = await evaluate(
        `document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export]').getAttribute('href')`,
    );
    const admin = await download(adminLink);
    assert.equal(admin.status, 200);
    assert.equal(csv(admin.text).length, 28);
    await responsive();
    process.env.BROWSER_ORGANIZATION_PHONE = "09122220000";
    await login("organization");
    assert.equal((await download(exportPath)).status, 403);
    for (const role of ["student", "coach"]) {
        await login(role);
        assert.equal((await download(exportPath)).status, 403);
    }
    await login("student");
    await goto("/organization-memberships");
    await until(
        `!!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    await evaluate(
        `document.querySelector('[data-membership="${fixture.organization_id}"] details').open=true`,
    );
    await fill(
        `[data-membership="${fixture.organization_id}"] [name=reason]`,
        "خروج آزمایشی برای بررسی قطع دسترسی خروجی",
    );
    await submit(`[data-membership="${fixture.organization_id}"] form`);
    await until(
        `!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    const departed = await download(exportPath);
    assert.equal(departed.status, 403);
    assert(!departed.text.includes("نوبت سابقه آزمایشی"));
    await login("admin");
    assert.equal((await download(adminPath)).status, 403);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "organization-member-report-real-download-27-enrollments-and-14-columns",
                "self-reported-published-lesson-progress-recorded-attendance-and-ended-status",
                "utf8-bom-private-download-headers-and-no-body-meeting-phone-data",
                "full-admin-membership-page-download-without-org-manager-role",
                "foreign-organization-and-other-panel-isolation",
                "actual-student-membership-departure-revokes-manager-and-admin-org-export",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
