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

try {
    await send("Network.clearBrowserCookies");
    await goto("/contact?audience=university");
    await until("!!document.querySelector('[data-public-contact]')");
    assert.equal(
        await evaluate("document.querySelector('[name=audience]').value"),
        "university",
    );
    await fill("[name=name]", "درخواست آزمایشی دانشگاه");
    await fill("[name=phone]", "۰۹۱۲۱۱۱۲۲۲۲");
    await fill("[name=organization_name]", "دانشگاه آزمایشی خصوصی");
    await fill(
        "[name=message]",
        "<script>window.contactInjected=true</script> درخواست برگزاری دوره برای مجموعه آزمایشی",
    );
    await evaluate("document.querySelector('[name=consent]').click()");
    const key = await evaluate(
        "document.querySelector('[name=request_key]').value",
    );
    const csrf = await evaluate(
        "document.querySelector('[name=_token]').value",
    );
    await submit("[data-public-contact]");
    await until("!!document.querySelector('[data-contact-success]')");
    assert.equal(await evaluate("window.contactInjected || false"), false);
    await evaluate(
        `(async()=>{const response=await fetch('/contact',{method:'POST',headers:{'Content-Type':'application/json','Accept':'text/html','X-CSRF-TOKEN':${JSON.stringify(csrf)}},body:JSON.stringify({request_key:${JSON.stringify(key)},name:'Replay fixture',phone:'09121112222',audience:'individual',subject:'training',message:'Replay must never replace original data',consent:true})});return await response.text()})()`,
    );
    await networkIdle();
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await goto("/contact");
        assert(
            await evaluate(
                "document.documentElement.scrollWidth <= innerWidth + 1",
            ),
            `Public overflow ${width}`,
        );
    }
    await login("admin");
    await goto("/admin/contact-leads");
    await until(
        "document.querySelectorAll('[data-contact-lead-row]').length===1",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-contact-lead-row]').innerText.includes('درخواست آزمایشی دانشگاه')",
        ),
    );
    assert(
        !(await evaluate("document.body.innerText")).includes("Replay fixture"),
    );
    const leadId = await evaluate(
        "document.querySelector('[data-contact-lead-row]').dataset.contactLeadRow",
    );
    await goto("/admin/contact-leads/" + leadId);
    await until("!!document.querySelector('[data-contact-follow-up]')");
    assert(
        await evaluate(
            "document.querySelector('[data-contact-lead]').innerText.includes('<script>window.contactInjected=true</script>')",
        ),
    );
    assert.equal(await evaluate("window.contactInjected || false"), false);
    assert(
        await evaluate(
            "document.querySelector('[data-contact-lead]').innerText.includes('تأییدنشده')",
        ),
    );
    for (const status of ["working", "qualified", "closed", "working"]) {
        await fill("[name=lead_status]", status);
        await fill("[name=lead_note]", "یادداشت آزمایشی پیگیری " + status);
        const count = await evaluate(
            "document.querySelectorAll('[data-contact-lead] .assessment-editor-item').length",
        );
        await submit("[data-contact-follow-up]");
        await until(
            `document.querySelectorAll('[data-contact-lead] .assessment-editor-item').length===${count + 1}`,
        );
        await until("document.querySelector('[name=lead_note]').value===''");
    }
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-contact-lead] .assessment-editor-item').length",
        ),
        5,
    );
    await goto("/admin/contact-leads?status=all&phone=09121112222");
    await until(
        "document.querySelectorAll('[data-contact-lead-row]').length===1",
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await goto("/admin/contact-leads/" + leadId);
        await until("!!document.querySelector('[data-contact-lead]')");
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Admin overflow ${width}`,
        );
        if (width === 390) {
            await until(
                "document.querySelector('.sidebar').getBoundingClientRect().left >= innerWidth-1",
            );
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-contact-leads-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        const result = await evaluate(
            `(async()=>{const r=await fetch('/admin/contact-leads/'+${JSON.stringify(leadId)},{headers:{Accept:'application/json'}});return {status:r.status,text:await r.text()}})()`,
        );
        assert.equal(result.status, 403);
        assert(!result.text.includes("درخواست آزمایشی دانشگاه"));
    }
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "anonymous-university-form",
                "explicit-consent",
                "idempotent-replay-original-data",
                "admin-private-follow-up-history-and-reopen",
                "normalized-phone-filter",
                "escaped-text",
                "student-coach-organization-isolation",
                "public-admin-320-390-768-1440",
            ],
        }),
    );
} catch (error) {
    console.error("Browser location", await evaluate("location.href"));
    throw error;
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
