import { execFileSync } from "node:child_process";
import path from "node:path";
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

const php = process.env.BROWSER_PHP,
    database = process.env.BROWSER_DATABASE;
assert(
    php &&
        database &&
        path.resolve(database).startsWith("/tmp/") &&
        path.basename(database).startsWith("kardorost-"),
    "Use an isolated local browser database and PHP",
);
const title = "<b>خبر آزمایشی مرورگر</b>";
const message =
    "اطلاعیه صرفاً برای آزمون\n<script>globalThis.announcementUnsafe=true</script>";
try {
    await login("admin");
    await goto("/admin/announcements");
    await fill("[name=announcement_title]", title);
    await fill("[name=announcement_message]", message);
    await evaluate("document.querySelector('[name=audience_student]').click()");
    await evaluate("document.querySelector('[name=audience_coach]').click()");
    await submit("[data-testid=announcement-editor]");
    await until(
        "location.pathname.startsWith('/admin/announcements/') && !!document.querySelector('[data-testid=announcement-publish]')",
    );
    const announcementPath = await evaluate("location.pathname");
    assert(
        await evaluate(
            "document.querySelector('[data-testid=announcement-status]').innerText.includes('۳')",
        ),
    );
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    await submit("[data-testid=announcement-publish]");
    await until(
        "document.body.innerText.includes('مخاطبان نهایی ثبت شدند') && !!document.querySelector('[data-testid=announcement-stop]')",
    );
    execFileSync(
        php,
        [
            "artisan",
            "queue:work",
            "database",
            "--queue=default",
            "--stop-when-empty",
            "--tries=3",
            "--timeout=30",
            "--no-interaction",
        ],
        {
            cwd: process.cwd(),
            encoding: "utf8",
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                DB_DATABASE: database,
                APP_URL: base,
                SMS_DRIVER: "disabled",
                CACHE_STORE: "array",
            },
        },
    );
    await goto(announcementPath);
    assert(
        await evaluate(
            "document.querySelector('[data-testid=announcement-status]').innerText.includes('پایان‌یافته')",
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('main .announcement-recipient').length",
        ),
        4,
    );
    await login("student");
    await goto("/notifications");
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('.notification-row')).filter(row=>row.innerText.includes(${JSON.stringify(title)})).length`,
        ),
        1,
    );
    assert.equal(
        await evaluate("globalThis.announcementUnsafe === true"),
        false,
    );
    assert(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify("<script>globalThis.announcementUnsafe=true</script>")})`,
        ),
    );
    await login("coach");
    await goto("/notifications");
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('.notification-row')).filter(row=>row.innerText.includes(${JSON.stringify(title)})).length`,
        ),
        1,
    );
    await login("organization");
    await goto("/notifications");
    assert(
        !(await evaluate(
            `document.body.innerText.includes(${JSON.stringify(title)})`,
        )),
    );
    await login("admin");
    await goto("/admin/announcements");
    await fill("[name=announcement_title]", "اطلاعیه توقف آزمایشی");
    await fill(
        "[name=announcement_message]",
        "این پیش‌نویس فقط برای آزمون توقف است.",
    );
    await evaluate("document.querySelector('[name=audience_student]').click()");
    await submit("[data-testid=announcement-editor]");
    await until(
        "!!document.querySelector('[data-testid=announcement-publish]') && location.pathname!=='/admin/announcements'",
    );
    const stoppedPath = await evaluate("location.pathname");
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    await submit("[data-testid=announcement-publish]");
    await until("!!document.querySelector('[data-testid=announcement-stop]')");
    await fill("[name=announcement_stop_reason]", "توقف آزمایشی پیش از تحویل");
    await submit("[data-testid=announcement-stop]");
    await until(
        "document.querySelector('[data-testid=announcement-status]').innerText.includes('متوقف‌شده') && !document.querySelector('[data-testid=announcement-stop]')",
    );
    execFileSync(
        php,
        [
            "artisan",
            "queue:work",
            "database",
            "--queue=default",
            "--stop-when-empty",
            "--tries=3",
            "--timeout=30",
            "--no-interaction",
        ],
        {
            cwd: process.cwd(),
            encoding: "utf8",
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                DB_DATABASE: database,
                APP_URL: base,
                SMS_DRIVER: "disabled",
                CACHE_STORE: "array",
            },
        },
    );
    await goto(stoppedPath);
    assert(
        await evaluate(
            "document.querySelector('[data-testid=announcement-status]').innerText.includes('متوقف‌شده')",
        ),
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        "document.documentElement.scrollWidth<=innerWidth && document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
    );
    const shot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-announcements-mobile.png",
        Buffer.from(shot.data, "base64"),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            admin_review_and_immutable_publication: true,
            three_recipient_role_snapshot: true,
            durable_database_worker: true,
            multi_role_single_notification: true,
            escaped_plain_text: true,
            nonrecipient_inbox_isolation: true,
            stopped_outbox_job_no_delivery: true,
            mobile390: true,
        }),
    );
} finally {
    socket.close();
}
