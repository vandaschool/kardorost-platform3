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
    await readFile("/tmp/kardorost-mentor-availability-fixture.json", "utf8"),
);
try {
    await send("Network.clearBrowserCookies");
    await goto("/mentors/" + fixture.slug);
    const cta = await evaluate(
        "document.querySelector('[data-public-mentor-profile] a.btn').getAttribute('href')",
    );
    assert.equal(new URL(cta, base).searchParams.get("mentor"), fixture.slug);
    await evaluate(
        "document.querySelector('[data-public-mentor-profile] a.btn').click()",
    );
    await until(
        "location.pathname==='/login' && !!document.querySelector('[name=password]')",
    );
    await fill("[name=phone]", process.env.BROWSER_STUDENT_PHONE);
    await fill("[name=password]", password);
    await submit("form");
    await until(
        `location.pathname==='/consultations' && new URL(location.href).searchParams.get('mentor')===${JSON.stringify(fixture.slug)} && !!document.querySelector('[data-mentor-filter]')`,
    );
    await until("document.querySelectorAll('[data-slot-id]').length===12");
    assert(
        await evaluate(
            "[...document.querySelectorAll('[data-slot-id] h3')].every(el=>el.textContent.includes('مربی منتخب مرورگر'))",
        ),
    );
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "برای انتخاب زمان کمک می‌خواهید؟",
        ),
    );
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "تعریف زمان مشاوره",
        ),
    );
    const next = await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(el=>new URL(el.href).searchParams.get('slots_page')==='2').getAttribute('href')",
    );
    assert.equal(new URL(next, base).searchParams.get("mentor"), fixture.slug);
    await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(el=>new URL(el.href).searchParams.get('slots_page')==='2').click()",
    );
    await until(
        "new URL(location.href).searchParams.get('slots_page')==='2' && document.querySelectorAll('[data-slot-id]').length===1",
    );
    await fill("[data-slot-id] textarea", "رزرو آزمایشی از پروفایل مربی منتخب");
    await submit("[data-slot-id] form");
    await until(
        "!!document.querySelector('[data-empty-consultation-slots]') && document.body.innerText.includes('رزروشده')",
    );
    assert.equal(
        await evaluate("new URL(location.href).searchParams.get('mentor')"),
        fixture.slug,
    );
    await goto("/consultations?mentor=" + fixture.slug);
    await until("document.querySelectorAll('[data-slot-id]').length===12");
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
            `Mentor filter overflow ${width}`,
        );
        if (width === 390) {
            await until(
                "document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
            );
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-mentor-availability-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    await goto("/consultations?mentor=" + fixture.empty_slug);
    await until(
        "!!document.querySelector('[data-mentor-filter]') && document.querySelectorAll('[data-slot-id]').length===0",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-empty-consultation-slots]').innerText.includes('این مربی')",
        ),
    );
    assert(await evaluate("document.body.innerText.includes('رزروشده')"));
    await evaluate("document.querySelector('[data-clear-mentor]').click()");
    await until(
        "!new URL(location.href).searchParams.has('mentor') && !document.querySelector('[data-mentor-filter]') && document.querySelectorAll('[data-slot-id]').length===12",
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('برای انتخاب زمان کمک می‌خواهید؟')",
        ),
    );
    await networkIdle();
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "public-profile-to-intended-password-login",
                "mentor-only-direct-slots",
                "filter-preserved-second-page",
                "actual-free-slot-booking",
                "own-booking-history-preserved",
                "empty-mentor-no-other-coach-fallback",
                "clear-filter-and-coordination",
                "320-390-768-1440-layouts",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
