import { execFileSync } from "node:child_process";
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

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

const php = process.env.BROWSER_PHP_BINARY;
const database = process.env.DB_DATABASE;
assert(
    php &&
        database === "/tmp/kardorost-scheduled-announcements-browser.sqlite" &&
        new URL(base).port === "8027",
    "Only the isolated scheduled announcement fixture may be changed.",
);
const environment = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    APP_URL: base,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
function artisan(args) {
    return execFileSync(php, ["artisan", ...args, "--no-interaction"], {
        cwd: process.cwd(),
        env: environment,
        encoding: "utf8",
        timeout: 120000,
    });
}
function metadata(id) {
    const code =
        "require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$a=\\App\\Models\\Announcement::findOrFail(" +
        id +
        ");echo json_encode(['status'=>$a->status,'recipients'=>$a->recipient_count,'delivered'=>$a->recipients()->where('status','delivered')->count()]);";
    return JSON.parse(
        execFileSync(php, ["-r", code], {
            cwd: process.cwd(),
            env: environment,
            encoding: "utf8",
        }),
    );
}
async function chooseTime() {
    await evaluate(
        "document.querySelector('[data-announcement-scheduling] details').open=true",
    );
    const at = new Date(Date.now() + 130000);
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(at)
            .map((p) => [p.type, p.value]),
    );
    for (const [prefix, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ])
        await fill(
            `select[aria-label="${prefix} ارسال اعلان"]`,
            String(Number(parts[key])),
        );
    const time = new Intl.DateTimeFormat("en-GB", {
        hour: "2-digit",
        minute: "2-digit",
        hourCycle: "h23",
        timeZone: "Asia/Tehran",
    }).format(at);
    await fill("[name=announcement_schedule_time]", time);
}
async function schedule() {
    await chooseTime();
    assert.equal(
        await evaluate(
            "document.querySelector('[name=announcement_schedule_reviewed]').checked",
        ),
        false,
    );
    await evaluate(
        "document.querySelector('[name=announcement_schedule_reviewed]').click()",
    );
    await submit("[data-announcement-schedule]");
    await until(
        "!!document.querySelector('[data-pending-announcement-schedule]') && !document.querySelector('[name=announcement_schedule_reviewed]').checked && !document.querySelector('[data-announcement-schedule] button').disabled===false",
    );
    await networkIdle();
}
const finalTitle = "نسخه نهایی اعلان زمان‌دار مرورگر";
const literal = "<script>window.scheduledAnnouncementInjected=true</script>";
try {
    await login("admin");
    await goto("/admin/announcements");
    await fill("[name=announcement_title]", "نسخه اول اعلان زمان‌دار مرورگر");
    await fill(
        "[name=announcement_message]",
        "این پیام فقط برای آزمون زمان‌بندی است.\n" + literal,
    );
    await evaluate("document.querySelector('[name=audience_student]').click()");
    await submit("[data-testid=announcement-editor]");
    await until(
        "location.pathname.startsWith('/admin/announcements/') && !!document.querySelector('[data-announcement-schedule]')",
    );
    const path = await evaluate("location.pathname");
    const announcementId = Number(path.split("/").at(-1));
    await schedule();
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-announcement-schedule-history-row]').length",
        ),
        1,
    );
    assert(
        await evaluate(
            "!!document.querySelector('[data-announcement-list-schedule]')",
        ),
    );
    await evaluate(
        "document.querySelector('[data-cancel-announcement-schedule]').click()",
    );
    await until(
        "!document.querySelector('[data-pending-announcement-schedule]')",
    );
    await schedule();
    await send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 1500,
        downloadThroughput: -1,
        uploadThroughput: -1,
    });
    await fill("[name=announcement_title]", "نسخه دوم ذخیره‌شده مرورگر");
    await submit("[data-testid=announcement-editor]");
    await fill("[name=announcement_title]", finalTitle);
    await until(
        "document.querySelector('[data-testid=announcement-status] h2').innerText==='نسخه دوم ذخیره‌شده مرورگر' && !document.querySelector('[data-testid=announcement-editor] button').disabled",
    );
    await send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 0,
        downloadThroughput: -1,
        uploadThroughput: -1,
    });
    assert.equal(
        await evaluate(
            "document.querySelector('[name=announcement_title]').value",
        ),
        finalTitle,
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-pending-announcement-schedule]')",
        ),
        false,
    );
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-testid=announcement-publish] button').disabled",
        ),
    );
    await submit("[data-testid=announcement-editor]");
    await until(
        `document.querySelector('[data-testid=announcement-status] h2').innerText===${JSON.stringify(finalTitle)} && !document.querySelector('[data-testid=announcement-editor] button').disabled`,
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[name=announcement_reviewed]').checked",
        ),
        false,
    );
    await schedule();
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-announcement-schedule-history-row]').length",
        ),
        3,
    );
    const currentId = await evaluate(
        "Number(document.querySelector('[data-announcement-schedule-history-row]').dataset.announcementScheduleHistoryRow)",
    );
    await send("Page.reload", { ignoreCache: true });
    await until(
        `JSON.parse(document.querySelector('script[data-page]')?.textContent||'{}').props?.pending_schedule?.id===${currentId}`,
    );
    const due = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.pending_schedule.scheduled_for",
    );
    const deadline = new Date(due).getTime();
    assert(deadline > Date.now());
    artisan(["platform:dispatch-announcements"]);
    assert.deepEqual(metadata(announcementId), {
        status: "draft",
        recipients: 0,
        delivered: 0,
    });
    await fill("[name=announcement_title]", "عنوان ذخیره‌نشده نباید منتشر شود");
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-testid=announcement-publish] button').disabled",
        ),
    );
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
            `Scheduled announcement overflow ${width}`,
        );
    }
    console.log(
        JSON.stringify({
            waiting_for_actual_due_utc: new Date(deadline).toISOString(),
        }),
    );
    while (Date.now() < deadline + 1000)
        await new Promise((resolve) => setTimeout(resolve, 1000));
    artisan(["schedule:run"]);
    assert.deepEqual(metadata(announcementId), {
        status: "sending",
        recipients: 2,
        delivered: 0,
    });
    artisan([
        "queue:work",
        "database",
        "--queue=default",
        "--stop-when-empty",
        "--tries=1",
        "--timeout=30",
    ]);
    assert.deepEqual(metadata(announcementId), {
        status: "completed",
        recipients: 2,
        delivered: 2,
    });
    artisan(["platform:dispatch-announcements"]);
    assert.deepEqual(metadata(announcementId), {
        status: "completed",
        recipients: 2,
        delivered: 2,
    });
    await goto(path);
    await until(
        "document.querySelector('[data-testid=announcement-status]').innerText.includes('پایان‌یافته')",
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-pending-announcement-schedule]')",
        ),
        false,
    );
    assert(
        (
            await evaluate(
                "document.querySelector('[data-announcement-schedule-history-row]').innerText",
            )
        ).includes("مخاطبان ثبت"),
    );
    await login("student");
    await goto("/notifications");
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('.notification-row')).filter(r=>r.innerText.includes(${JSON.stringify(finalTitle)})).length`,
        ),
        1,
    );
    assert((await evaluate("document.body.innerText")).includes(literal));
    assert.equal(
        await evaluate("window.scheduledAnnouncementInjected||false"),
        false,
    );
    await login("coach");
    await goto("/notifications");
    assert(!(await evaluate("document.body.innerText")).includes(finalTitle));
    await login("organization");
    await goto("/notifications");
    assert(!(await evaluate("document.body.innerText")).includes(finalTitle));
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "encrypted-reviewed-schedule-create-cancel-edit-history",
                "inflight-draft-edits-remain-unsaved",
                "fresh-review-required-after-save",
                "pre-due-no-recipient-snapshot-or-notification",
                "actual-wall-clock-tehran-jalali-due-and-real-scheduler",
                "actual-database-worker-two-student-deliveries-once",
                "unsaved-title-never-published",
                "coach-organization-inbox-isolation-and-escaped-text",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
