import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import path from "node:path";
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
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            m.params.args.map((arg) => arg.description || arg.value).join(" "),
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
async function login(role) {
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

const php = process.env.BROWSER_PHP;
const database = process.env.BROWSER_DATABASE;
assert(
    php &&
        database &&
        path.resolve(database).startsWith("/tmp/") &&
        path.basename(database).startsWith("kardorost-"),
    "Provide PHP and an isolated /tmp/kardorost- browser database",
);
try {
    await login("student");
    await goto("/notifications");
    assert(
        await evaluate(
            "document.body.innerText.includes('یادآوری کلاس پیش رو')",
        ),
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('یادآوری مشاوره پیش رو')",
        ),
    );
    await evaluate(
        "document.querySelector('[name=sms_reminders_enabled]').click()",
    );
    await submit("form");
    await until(
        "document.body.innerText.includes('ترجیح پیامک یادآوری ذخیره شد')",
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[name=sms_reminders_enabled]').checked",
        ),
        false,
    );
    await evaluate(
        "Array.from(document.querySelectorAll('.notification-row')).find(row=>row.innerText.includes('یادآوری کلاس')).querySelector('a').click()",
    );
    await until(
        "location.pathname.startsWith('/learning/') && location.search.includes('tab=sessions') && !!document.querySelector('.live-session-card')",
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('کلاس یادآوری مرورگر')",
        ),
    );
    assert(await evaluate("!!document.querySelector(location.hash)"));
    await goto("/notifications");
    await evaluate(
        "Array.from(document.querySelectorAll('.notification-row')).find(row=>row.innerText.includes('یادآوری مشاوره')).querySelector('a').click()",
    );
    await until(
        "location.pathname === '/consultations' && location.search.includes('bookings_page=2') && location.search.includes('focus=') && !!document.querySelector('.reminder-focused')",
    );
    await until(
        "(() => { const r = document.querySelector('.reminder-focused').getBoundingClientRect(); return r.top >= 0 && r.top < innerHeight; })()",
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('مشاوره آزمایشی مرورگر')",
        ),
    );
    await login("coach");
    await goto("/notifications");
    assert.equal(
        await evaluate(
            "document.querySelector('[name=sms_reminders_enabled]').checked",
        ),
        true,
    );
    await evaluate(
        "Array.from(document.querySelectorAll('.notification-row')).find(row=>row.innerText.includes('یادآوری کلاس')).querySelector('a').click()",
    );
    await until("location.pathname.startsWith('/teaching/')");
    await login("organization");
    await goto("/organization/invitations");
    assert(
        await evaluate(
            "document.body.innerText.includes('گزارش تحویل: رسیده به گوشی')",
        ),
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('browser-synthetic-sender')",
        )),
    );
    await login("admin");
    await goto("/admin/communications");
    assert(await evaluate("document.body.innerText.includes('رسیده به گوشی')"));
    const deliveryId = await evaluate(
        "Array.from(document.querySelectorAll('[data-delivery]')).find(row=>row.innerText.includes('90000000000000002')).dataset.delivery",
    );
    await evaluate(
        `document.querySelector('[data-delivery="${deliveryId}"] button').click()`,
    );
    await until(
        "document.body.innerText.includes('درخواست استعلام تازه ثبت شد')",
    );
    execFileSync(
        php,
        [
            "artisan",
            "queue:work",
            "database",
            "--queue=sms",
            "--once",
            "--tries=1",
            "--timeout=30",
            "--no-interaction",
        ],
        {
            encoding: "utf8",
            cwd: process.cwd(),
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                DB_DATABASE: database,
                APP_URL: base,
                SMS_DRIVER: "disabled",
                MELIPAYAMAK_DELIVERY_ENABLED: "true",
                MELIPAYAMAK_REMINDERS_ENABLED: "false",
                CACHE_STORE: "array",
            },
        },
    );
    await goto("/admin/communications?delivery=" + deliveryId);
    assert(
        await evaluate(
            "document.body.innerText.includes('تنظیمات سرویس کامل نیست')",
        ),
    );
    assert(await evaluate("document.body.innerText.includes('بررسی ۱')"));
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        "!document.querySelector('.sidebar') || document.querySelector('.sidebar').getBoundingClientRect().left >= innerWidth",
    );
    assert(
        await evaluate("document.documentElement.scrollWidth <= innerWidth"),
    );
    const screenshot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-communications-mobile.png",
        Buffer.from(screenshot.data, "base64"),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            class_reminder_deep_link: true,
            consultation_page_two_deep_link: true,
            user_sms_preference: true,
            coach_scope: true,
            organization_delivery_ui_synthetic: true,
            admin_query_queue_without_provider_credentials: true,
            mobile390: true,
        }),
    );
} finally {
    socket.close();
}
