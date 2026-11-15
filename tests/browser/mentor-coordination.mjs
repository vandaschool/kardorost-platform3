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
    await readFile("/tmp/kardorost-mentor-coordination-fixture.json", "utf8"),
);
const topic =
    "<script>window.coordinationInjected=true</script> درخواست خصوصی مربی منتخب";
try {
    await login("student");
    await goto("/consultations?mentor=" + fixture.slug);
    await until("!!document.querySelector('[data-coordination-request]')");
    assert.equal(
        await evaluate(
            "document.querySelector('[name=request_grant]').options.length",
        ),
        2,
    );
    assert(
        !(
            await evaluate(
                "document.querySelector('[name=request_grant]').innerText",
            )
        ).includes("سهمیه مربی دیگر مرورگر"),
    );
    await fill("[name=request_topic]", topic);
    await fill(
        "[name=request_availability]",
        "عصر روزهای هفته برای هماهنگی با مربی منتخب",
    );
    await fill("[name=request_grant]", String(fixture.grant_id));
    await submit("[data-coordination-request]");
    await until(
        "document.querySelectorAll('[data-coordination-request-id]').length===1 && document.querySelector('[name=request_topic]').value===''",
    );
    const first = await evaluate(
        "document.querySelector('[data-coordination-request-id]').dataset.coordinationRequestId",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-coordination-request-id]').innerText.includes('مربی منتخب هماهنگی مرورگر')",
        ),
    );
    assert.equal(await evaluate("window.coordinationInjected || false"), false);
    await login("coach");
    await goto("/consultations");
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "درخواست خصوصی مربی منتخب",
        ),
    );
    await login("admin");
    await goto("/consultations?view=admin&coordination_request=" + first);
    await until("!!document.querySelector('[data-coordination-filter]')");
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-coordination-request-id]').length",
        ),
        1,
    );
    assert.equal(
        await evaluate("document.querySelectorAll('[data-slot-id]').length"),
        1,
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-slot-id]').dataset.slotId",
        ),
        String(fixture.paid_slot_id),
    );
    const rejection = await evaluate(
        `(async()=>{const r=await fetch('/consultations/requests/'+${JSON.stringify(first)}+'/schedule',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({version:1,slot_id:${fixture.wrong_slot_id}})});return {status:r.status,text:await r.text()}})()`,
    );
    assert.equal(rejection.status, 422);
    await networkIdle();
    await fill(
        `[data-coordination-request-id="${first}"] [name=coordination_slot_id]`,
        String(fixture.paid_slot_id),
    );
    await submit(
        `[data-coordination-request-id="${first}"] [data-coordination-schedule]`,
    );
    await until(
        `document.querySelector('[data-coordination-request-id="${first}"]').innerText.includes('زمان تعیین شد') && document.body.innerText.includes('رزروشده')`,
    );
    await login("student");
    await goto("/consultations?mentor=" + fixture.slug);
    await until("document.body.innerText.includes('رزروشده')");
    assert(
        await evaluate(
            "document.body.innerText.includes('سهمیه مربی منتخب مرورگر')",
        ),
    );
    assert.equal(await evaluate("window.coordinationInjected || false"), false);
    const grants = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.grants",
    );
    assert.equal(grants.find((g) => g.id === fixture.grant_id).remaining, 1);
    await fill(
        "[name=request_topic]",
        "درخواست دوم برای رزرو رایگان مربی منتخب",
    );
    await fill(
        "[name=request_availability]",
        "زمان دیگری در بعد از ظهر روزهای هفته",
    );
    await submit("[data-coordination-request]");
    await until(
        "document.querySelectorAll('[data-coordination-request-id]').length===2 && document.querySelector('[name=request_topic]').value===''",
    );
    const second = await evaluate(
        "document.querySelector('[data-coordination-request-id]').dataset.coordinationRequestId",
    );
    await login("admin");
    await goto("/consultations?view=admin&coordination_request=" + second);
    await until("document.querySelectorAll('[data-slot-id]').length===12");
    const next = await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(a=>new URL(a.href).searchParams.get('slots_page')==='2').getAttribute('href')",
    );
    assert.equal(
        new URL(next, base).searchParams.get("coordination_request"),
        second,
    );
    await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(a=>new URL(a.href).searchParams.get('slots_page')==='2').click()",
    );
    await until(
        "document.querySelectorAll('[data-slot-id]').length===1 && new URL(location.href).searchParams.get('slots_page')==='2'",
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-slot-id]').dataset.slotId",
        ),
        String(fixture.last_free_slot_id),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-coordination-request-id]').length",
        ),
        1,
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
            `Admin coordination overflow ${width}`,
        );
        if (width === 390) {
            await until(
                "document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
            );
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-mentor-coordination-admin-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    await fill(
        `[data-coordination-request-id="${second}"] [name=coordination_slot_id]`,
        String(fixture.last_free_slot_id),
    );
    await submit(
        `[data-coordination-request-id="${second}"] [data-coordination-schedule]`,
    );
    await until(
        `document.querySelector('[data-coordination-request-id="${second}"]').innerText.includes('زمان تعیین شد')`,
    );
    await login("student");
    await goto("/consultations?mentor=" + fixture.slug);
    await until("document.querySelectorAll('[id^=consultation-]').length===2");
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
            `Student coordination overflow ${width}`,
        );
    }
    const original = process.env.BROWSER_STUDENT_PHONE;
    process.env.BROWSER_STUDENT_PHONE = "09121234564";
    await login("student");
    process.env.BROWSER_STUDENT_PHONE = original;
    await goto("/consultations?coordination_request=" + first);
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "درخواست خصوصی مربی منتخب",
        ),
    );
    assert.equal(
        await evaluate(
            "JSON.parse(document.querySelector('script[data-page]').textContent).props.coordination_filter",
        ),
        null,
    );
    await networkIdle();
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "selected-mentor-specific-coordination",
                "matching-package-dropdown-only",
                "request-does-not-grant-coach-topic-access",
                "admin-one-request-one-matching-paid-slot",
                "wrong-coach-api-rejected",
                "package-consumed-only-by-confirmed-actual-booking",
                "second-free-coordination",
                "admin-matching-slot-pagination-preserves-request",
                "peer-isolation",
                "320-390-768-1440-layouts",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
