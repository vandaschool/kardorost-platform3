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
    await readFile("/tmp/kardorost-progress-fixture.json", "utf8"),
);
const classroom = "/learning/" + fixture.enrollment;
async function finishLesson() {
    await evaluate(
        "Array.from(document.querySelectorAll('main button')).find(b=>b.innerText.includes('درس را تکمیل کردم')).click()",
    );
    await networkIdle();
}
try {
    await login("admin");
    await goto("/admin/learning-reward-policies");
    await evaluate(
        "document.querySelector('main details').open=true;document.querySelector('[name=rewards_enabled]').click()",
    );
    await until("!!document.querySelector('[name=lesson_points]')");
    await fill("[name=lesson_points]", "10");
    await evaluate("document.querySelector('[name=badge_enabled]').click()");
    await until("!!document.querySelector('[name=badge_goal]')");
    await fill("[name=badge_goal]", "20");
    await fill("[name=badge_title]", "نشان مسیر آزمایشی");
    await evaluate(
        "document.querySelector('[name=reward_policy_reviewed]').click()",
    );
    await submit("[data-reward-policy]");
    await until(
        "document.body.innerText.includes('قواعد برای تکمیل‌های بعدی ذخیره شد')",
    );
    await login("student");
    await goto(classroom);
    assert(
        await evaluate(
            "document.querySelector('[data-testid=room-reward-terms]').innerText.includes('۱۰')",
        ),
    );
    await finishLesson();
    await goto("/learning-rewards");
    assert(
        await evaluate(
            "document.querySelector('[data-reward-metric=points]').innerText.includes('۱۰')",
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-reward-event]').length",
        ),
        1,
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-learning-badge]').length",
        ),
        0,
    );
    await goto(classroom);
    await finishLesson();
    await goto("/learning-rewards");
    assert(
        await evaluate(
            "document.querySelector('[data-reward-metric=points]').innerText.includes('۲۰')",
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-learning-badge]').length",
        ),
        1,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-learning-badge]').innerText.includes('نشان مسیر آزمایشی')",
        ),
    );
    const status = await evaluate(
        `(async()=>{const response=await fetch(${JSON.stringify(classroom + "/lessons/" + fixture.lesson + "/progress")},{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({complete:true})});return response.status;})()`,
    );
    assert.equal(status, 204);
    await goto("/learning-rewards");
    assert(
        await evaluate(
            "document.querySelector('[data-reward-metric=points]').innerText.includes('۲۰')",
        ),
    );
    await login("organization");
    await goto("/learning-rewards");
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-reward-event]').length",
        ),
        0,
    );
    await login("admin");
    await goto("/admin/learning-rewards");
    await fill(
        "[name=reward_subject_phone]",
        process.env.BROWSER_STUDENT_PHONE,
    );
    await submit("main form");
    await until("document.querySelectorAll('[data-reward-event]').length===2");
    await goto("/admin/learning-reward-policies");
    await evaluate("document.querySelector('main details').open=true");
    await fill("[name=lesson_points]", "25");
    await fill("[name=badge_goal]", "50");
    await fill("[name=badge_title]", "نشان جدید آزمایشی");
    await evaluate(
        "document.querySelector('[name=reward_policy_reviewed]').click()",
    );
    await submit("[data-reward-policy]");
    await until(
        "document.body.innerText.includes('قواعد برای تکمیل‌های بعدی ذخیره شد')",
    );
    await login("student");
    await goto(classroom);
    await finishLesson();
    await goto("/learning-rewards");
    assert(
        await evaluate(
            "document.querySelector('[data-reward-metric=points]').innerText.includes('۴۵')",
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-reward-event]').length",
        ),
        3,
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-learning-badge]').length",
        ),
        1,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-learning-badge]').innerText.includes('نشان مسیر آزمایشی')",
        ),
    );
    assert(
        !(await evaluate(
            "document.querySelector('[data-learning-badge]').innerText.includes('نشان جدید')",
        )),
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
        "/tmp/kardorost-rewards-mobile.png",
        Buffer.from(shot.data, "base64"),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            admin_versioned_policy: true,
            classroom_terms: true,
            real_completion_points: true,
            goal_badge_once: true,
            duplicate_completion_no_points: true,
            private_owner_and_admin_lookup: true,
            new_rule_preserves_old_points_and_badge: true,
            mobile390: true,
        }),
    );
} finally {
    socket.close();
}
