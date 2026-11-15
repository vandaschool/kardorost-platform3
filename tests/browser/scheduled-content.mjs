import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
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

const fixture = JSON.parse(
    await readFile("/tmp/kardorost-content-schedule-fixture.json", "utf8"),
);
const php = process.env.BROWSER_PHP_BINARY;
assert(
    php && process.env.DB_DATABASE?.startsWith("/tmp/"),
    "Use an isolated local SQLite browser database",
);
async function chooseTime() {
    const at = new Date(Date.now() + 180000);
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
            `select[aria-label="${prefix} انتشار محتوا"]`,
            String(Number(parts[key])),
        );
    const time = new Intl.DateTimeFormat("en-GB", {
        hour: "2-digit",
        minute: "2-digit",
        hourCycle: "h23",
        timeZone: "Asia/Tehran",
    }).format(at);
    await fill("[name=content_schedule_time]", time);
}
async function schedule() {
    await chooseTime();
    assert.equal(
        await evaluate(
            "document.querySelector('[name=content_schedule_reviewed]').checked",
        ),
        false,
    );
    await evaluate(
        "document.querySelector('[name=content_schedule_reviewed]').click()",
    );
    await submit("[data-content-schedule]");
    await until(
        "!!document.querySelector('[data-pending-content-schedule]') && !document.querySelector('[name=content_schedule_reviewed]').checked",
    );
}
try {
    await login("admin");
    await goto("/admin/content/" + fixture.id);
    await until("!!document.querySelector('[data-content-schedule]')");
    await schedule();
    let publicHtml = await (await fetch(base + fixture.path)).text();
    assert(
        publicHtml.includes("نسخه عمومی قبلی مرورگر") &&
            !publicHtml.includes("نسخه آینده مرورگر"),
    );
    await evaluate(
        "document.querySelector('[data-cancel-content-schedule]').click()",
    );
    await until("!document.querySelector('[data-pending-content-schedule]')");
    await schedule();
    await fill("[name=title]", "نسخه نهایی زمان‌دار مرورگر");
    assert(
        await evaluate(
            "document.querySelector('[data-content-publish]').disabled",
        ),
    );
    await submit("[data-content-draft]");
    await until(
        "!document.querySelector('[data-pending-content-schedule]') && document.querySelector('h1').innerText.includes('نسخه نهایی زمان‌دار مرورگر')",
    );
    await schedule();
    await goto("/admin/content/" + fixture.id);
    await until("!!document.querySelector('[data-pending-content-schedule]')");
    const due = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.pending_schedule.scheduled_for",
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-content-schedule-history]').length",
        ),
        3,
    );
    await fill("[name=title]", "تغییر ذخیره‌نشده نباید منتشر شود");
    assert(
        await evaluate(
            "document.querySelector('[data-content-schedule] button').disabled",
        ),
    );
    await goto("/admin/content/" + fixture.id);
    await until("!!document.querySelector('[data-pending-content-schedule]')");
    publicHtml = await (await fetch(base + fixture.path)).text();
    assert(!publicHtml.includes("نسخه نهایی زمان‌دار مرورگر"));
    console.log(JSON.stringify({ waiting_for_local_due_at: due }));
    const deadline = Date.now() + 240000;
    while (Date.now() < new Date(due).getTime() + 1000) {
        assert(
            Date.now() < deadline,
            "Due time did not arrive within the bounded local test window",
        );
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    await networkIdle();
    execFileSync(php, ["artisan", "schedule:run", "--no-interaction"], {
        cwd: process.cwd(),
        env: {
            ...process.env,
            DB_CONNECTION: "sqlite",
            APP_URL: base,
            SMS_DRIVER: "disabled",
        },
        encoding: "utf8",
        timeout: 45000,
    });
    await goto("/admin/content/" + fixture.id);
    await until("!document.querySelector('[data-pending-content-schedule]')");
    assert.equal(
        await evaluate(
            "JSON.parse(document.querySelector('script[data-page]').textContent).props.page.current_version",
        ),
        2,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-content-schedule-history] summary').innerText.includes('منتشرشده')",
        ),
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Content editor overflow ${width}`,
        );
        if (width === 390) {
            await until(
                "document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
            );
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-content-schedule-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    await goto(fixture.path);
    assert(
        (await evaluate("document.body.innerText")).includes(
            "نسخه نهایی زمان‌دار مرورگر",
        ),
    );
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "تغییر ذخیره‌نشده نباید منتشر شود",
        ),
    );
    assert.equal(await evaluate("window.scheduledInjected || false"), false);
    assert(
        (await evaluate("document.body.innerText")).includes(
            "<script>window.scheduledInjected=true</script>",
        ),
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Scheduled article overflow ${width}`,
        );
    }
    await networkIdle();
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "explicit-saved-snapshot-consent",
                "old-public-copy-before-due",
                "explicit-cancel-preserved-history",
                "draft-save-cancels-prior-schedule",
                "fresh-reviewed-checkbox",
                "unsaved-edit-does-not-change-scheduled-copy",
                "actual-wall-clock-due-and-local-scheduler-publication",
                "one-replacement-publication",
                "escaped-public-text",
                "editor-public-320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
