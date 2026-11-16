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
const database = process.env.DB_DATABASE;
assert(
    php &&
        database === "/tmp/kardorost-cohort-announcements-browser.sqlite" &&
        new URL(base).port === "8029",
    "Use the isolated cohort announcement fixture only.",
);
const fixture = JSON.parse(
    readFileSync("/tmp/kardorost-cohort-announcement-fixture.json", "utf8"),
);
const environment = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    APP_URL: base,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
    MELIPAYAMAK_INVITATIONS_ENABLED: "false",
    MELIPAYAMAK_REMINDERS_ENABLED: "false",
    MELIPAYAMAK_DELIVERY_ENABLED: "false",
};
function inspect(id) {
    const code =
        "require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$a=\\App\\Models\\Announcement::findOrFail(" +
        id +
        ");$rows=$a->recipients()->orderBy('user_id')->get();$data=\\Illuminate\\Support\\Facades\\DB::table('notifications')->whereIn('id',$rows->pluck('notification_id')->filter())->pluck('data');echo json_encode(['status'=>$a->status,'recipients'=>$rows->map(fn($r)=>['user_id'=>$r->user_id,'status'=>$r->status,'failure_code'=>$r->failure_code])->all(),'ciphertext_only'=>$data->every(fn($v)=>str_contains($v,'private_payload')&&!str_contains($v,$a->title)&&!str_contains($v,$a->audience['label']))]);";
    return JSON.parse(
        execFileSync(php, ["-r", code], {
            cwd: process.cwd(),
            env: environment,
            encoding: "utf8",
        }),
    );
}
function worker() {
    execFileSync(
        php,
        [
            "artisan",
            "queue:work",
            "database",
            "--queue=default",
            "--stop-when-empty",
            "--tries=1",
            "--timeout=30",
            "--no-interaction",
        ],
        {
            cwd: process.cwd(),
            env: environment,
            encoding: "utf8",
            timeout: 120000,
        },
    );
}

const membersTitle = "خبر خصوصی دانشجویان نوبت آزمایشی";
const coachesTitle = "خبر خصوصی مربی جدید همین دوره";
const literal = "<script>window.cohortAnnouncementInjected=true</script>";
async function create(roles, title) {
    await goto("/admin/announcements");
    await until(
        "!!document.querySelector('[name=announcement_audience_type]')",
    );
    await fill("[name=announcement_title]", title);
    await fill(
        "[name=announcement_message]",
        "متن آزمایشی خصوصی نوبت آموزشی.\n" + literal,
    );
    await fill("[name=announcement_audience_type]", "cohort");
    await until("!!document.querySelector('[name=announcement_cohort]')");
    await fill("[name=announcement_cohort]", fixture.cohort_id);
    assert.equal(
        await evaluate("!!document.querySelector('[name=audience_org_admin]')"),
        false,
    );
    assert.equal(
        await evaluate("!!document.querySelector('[name=audience_admin]')"),
        false,
    );
    for (const role of roles)
        await evaluate(
            `document.querySelector('[name=audience_${role}]').click()`,
        );
    await submit("[data-testid=announcement-editor]");
    await until(
        "location.pathname.startsWith('/admin/announcements/') && !!document.querySelector('[data-testid=announcement-publish]')",
    );
    assert(
        (
            await evaluate(
                "document.querySelector('[data-announcement-audience]').innerText",
            )
        ).includes("<b>دوره خصوصی آزمایشی</b>"),
    );
    const id = Number((await evaluate("location.pathname")).split("/").at(-1));
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    await submit("[data-testid=announcement-publish]");
    await until("!!document.querySelector('[data-testid=announcement-stop]')");
    return id;
}
async function account(role, index) {
    process.env["BROWSER_" + role.toUpperCase() + "_PHONE"] =
        "0912123456" + index;
    await login(role);
}
async function inbox(title, expected) {
    await goto("/notifications");
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(title)})`,
        ),
        expected,
    );
}
async function responsive(label) {
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
            `${label} overflow ${width}`,
        );
    }
}
try {
    await login("admin");
    const members = await create(["student", "coach"], membersTitle);
    assert.deepEqual(
        inspect(members).recipients.map((r) => r.user_id),
        [fixture.users[0], fixture.users[1], fixture.users[4]].sort(
            (a, b) => a - b,
        ),
    );
    await responsive("Cohort editor");
    await goto("/admin/courses");
    await until("!!document.querySelector('.coach-select')");
    await fill(".coach-select", fixture.users[5]);
    await until("document.body.innerText.includes('مربی دوره تعیین شد.')");
    await networkIdle();
    // Only this isolated fixture models an already ended registration; renewal uses the real service.
    const renewal = execFileSync(
        php,
        [
            "-r",
            "require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$e=\\App\\Models\\Enrollment::findOrFail(" +
                fixture.enrollments[0] +
                ");$e->update(['status'=>'expired']);$renewed=app(\\App\\Services\\EnrollmentService::class)->reserve($e->user,$e->cohort);echo json_encode(['id'=>$renewed->id,'billing_version'=>$renewed->billing_version]);",
        ],
        { cwd: process.cwd(), env: environment, encoding: "utf8" },
    );
    assert.deepEqual(JSON.parse(renewal), {
        id: fixture.enrollments[0],
        billing_version: 2,
    });
    worker();
    const result = inspect(members);
    assert.equal(result.status, "completed");
    assert.equal(result.ciphertext_only, true);
    assert.equal(
        result.recipients.filter((r) => r.status === "delivered").length,
        1,
    );
    assert.equal(
        result.recipients.find((r) => r.user_id === fixture.users[0])
            .failure_code,
        "scope_changed",
    );
    assert.equal(
        result.recipients.find((r) => r.user_id === fixture.users[1]).status,
        "skipped",
    );
    worker();
    assert.deepEqual(inspect(members), result);
    await account("student", 4);
    await inbox(membersTitle, true);
    assert((await evaluate("document.body.innerText")).includes("نوبت آموزشی"));
    assert(
        (await evaluate("document.body.innerText")).includes(
            "<b>دوره خصوصی آزمایشی</b>",
        ),
    );
    assert((await evaluate("document.body.innerText")).includes(literal));
    assert.equal(
        await evaluate("window.cohortAnnouncementInjected||false"),
        false,
    );
    assert.equal(
        await evaluate(
            "document.querySelector('script[data-page]').textContent.includes('private_payload')",
        ),
        false,
    );
    await responsive("Cohort inbox");
    for (const index of [0, 6, 7]) {
        await account("student", index);
        await inbox(membersTitle, false);
    }
    for (const index of [1, 5]) {
        await account("coach", index);
        await inbox(membersTitle, false);
    }
    await account("organization", 3);
    await inbox(membersTitle, false);
    await login("admin");
    const coaches = await create(["coach"], coachesTitle);
    assert.deepEqual(
        inspect(coaches).recipients.map((r) => r.user_id),
        [fixture.users[5]],
    );
    worker();
    assert.equal(
        inspect(coaches).recipients.filter((r) => r.status === "delivered")
            .length,
        1,
    );
    await account("coach", 5);
    await inbox(coachesTitle, true);
    await account("coach", 1);
    await inbox(coachesTitle, false);
    await account("student", 4);
    await inbox(coachesTitle, false);
    await login("admin");
    await goto("/admin/announcements/" + members);
    await until(
        "document.querySelector('[data-testid=announcement-status]').innerText.includes('پایان‌یافته')",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "exact-cohort-and-active-enrollment-audience",
                "actual-admin-course-coach-reassignment",
                "fixture-ended-enrollment-real-service-renewal-generation",
                "one-student-private-delivery-and-new-coach-only-fresh-notice",
                "old-coach-new-coach-other-cohort-pending-and-organization-isolation",
                "real-database-worker-idempotency-and-encrypted-owner-inbox",
                "escaped-private-label-script-and-320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
