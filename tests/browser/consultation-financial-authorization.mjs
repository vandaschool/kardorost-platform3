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
    await networkIdle();
    await evaluate("document.documentElement.dataset.qaNavigation='previous'");
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main') && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'`,
    );
    await networkIdle();
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
async function login(
    role,
    phone = process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
) {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill("[name=phone]", phone);
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

assert(
    new URL(base).port === "8031" &&
        process.env.DB_DATABASE === "/tmp/kardorost-linkedin-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned five-user native fixture.",
);
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Only the owned synthetic fixture.');}$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$grants=\App\Models\ConsultationGrant::all();if($grants->count()>1||$grants->contains(fn($g)=>$g->student_id!==$student->id||$g->coach_id!==$coach->id||$g->source_reference!=='SOFTWARE-ONLY-BROWSER62')){throw new RuntimeException('Preserve other grants.');}echo json_encode(['coach_id'=>$coach->id,'grant'=>$grants->first()?->only('id','total_sessions','revision','active'),'payments'=>\App\Models\Payment::count()]);`;
function state() {
    return JSON.parse(
        execFileSync(process.env.BROWSER_PHP_BINARY, ["-r", code], {
            encoding: "utf8",
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                SMS_DRIVER: "disabled",
                ZIBAL_ENABLED: "false",
            },
        }),
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
            label + width,
        );
    }
}
try {
    const initial = state();
    await login("admin");
    await goto("/admin/consultation-grants");
    if (!initial.grant) {
        await evaluate("document.querySelector('main > details').open=true");
        await fill("[name=grant_student_phone]", "09121234560");
        await fill("[name=grant_coach]", String(initial.coach_id));
        await fill(
            "[name=grant_title]",
            "سهمیه بررسی دسترسی مالی صرفاً آزمایشی",
        );
        await fill("[name=grant_source]", "SOFTWARE-ONLY-BROWSER62");
        await fill("[name=grant_total]", "3");
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                timeZone: "Asia/Tehran",
            })
                .formatToParts(new Date(Date.now() + 20 * 86400000))
                .map((p) => [p.type, p.value]),
        );
        for (const [label, key] of [
            ["سال", "year"],
            ["ماه", "month"],
            ["روز", "day"],
        ])
            await fill(
                `main > details select[aria-label^="${label}"]`,
                String(Number(parts[key])),
            );
        await evaluate(
            "document.querySelector('main > details input[type=checkbox]').click()",
        );
        await submit("main > details form");
        await until(
            "!!document.querySelector('.consultation-grant-card') && document.querySelector('[name=grant_student_phone]').value===''",
        );
        await networkIdle();
    }
    let current = state();
    if (current.grant.total_sessions !== 4 || current.grant.active) {
        await evaluate(
            "document.querySelector('.consultation-grant-card details').open=true",
        );
        await fill(".consultation-grant-card input[type=number]", "4");
        if (current.grant.active)
            await evaluate(
                "document.querySelector('.consultation-grant-card input[type=checkbox]').click()",
            );
        await submit(".consultation-grant-card form");
        await until(
            "document.querySelector('.consultation-grant-card').innerText.includes('متوقف')",
        );
        await networkIdle();
    }
    current = state();
    assert.equal(current.grant.total_sessions, 4);
    assert.equal(current.grant.active, false);
    assert.equal(current.payments, initial.payments);
    await responsive("Admin manual source grant");
    await login("student");
    await goto("/consultation-grants");
    await until(
        "document.body.innerText.includes('سهمیه بررسی دسترسی مالی صرفاً آزمایشی')",
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('SOFTWARE-ONLY-BROWSER62')",
        )),
    );
    assert(
        await evaluate(
            "document.querySelector('.consultation-grant-card').innerText.includes('متوقف')",
        ),
    );
    await responsive("Owner inactive grant without source reference");
    const denied = await evaluate(
        `(async()=>{const r=await fetch('/admin/consultation-grants/${current.grant.id}',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({revision:${current.grant.revision},total_sessions:99,valid_until:'2026-12-30',active:true})});await r.text();return r.status;})()`,
    );
    assert.equal(denied, 403);
    assert.deepEqual(state().grant, current.grant);
    await login("student", "09121234564");
    await goto("/consultation-grants");
    assert(
        !(await evaluate(
            "document.body.innerText.includes('سهمیه بررسی دسترسی مالی صرفاً آزمایشی')",
        )),
    );
    assert.equal(state().payments, initial.payments);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "actual-admin-reviewed-source-grant-form-jalali-expiry-create-edit-stop",
                "manual-grant-does-not-create-payment",
                "owner-only-grant-without-admin-source-reference",
                "student-cannot-change-admin-grant-peer-cannot-read-owner-grant",
                "resumable-owned-fixture-no-reset-or-provider-request",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
