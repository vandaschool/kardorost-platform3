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
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Only the owned synthetic fixture.');}$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$slots=\App\Models\ConsultationSlot::all();$bookings=\App\Models\Consultation::all();$requests=\App\Models\ConsultationRequest::orderBy('id')->get();if($slots->count()>1||$slots->contains(fn($s)=>$s->coach_id!==$coach->id||$s->price!==0)||$bookings->count()>1||$bookings->contains(fn($b)=>$b->student_id!==$student->id||$b->topic!=='بازبینی دسترسی مشاوره صرفاً آزمایشی')||$requests->count()>2||$requests->contains(fn($r)=>$r->student_id!==$student->id||!str_starts_with($r->topic,'بازبینی دسترسی مشاوره صرفاً آزمایشی'))){throw new RuntimeException('Preserve unrelated consultation state.');}echo json_encode(['slot'=>$slots->first()?->only('id','published','booked','meeting_url'),'booking'=>$bookings->first()?->only('id','status','version'),'requests'=>$requests->map(fn($r)=>$r->only('id','status','version'))]);`;
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
async function request(topic) {
    await fill("main > .consultation-card textarea", topic);
    await evaluate(
        `(()=>{const e=document.querySelectorAll('main > .consultation-card textarea')[1];e.value='صرفاً بررسی نرم‌افزاری؛ بدون تماس واقعی';e.dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    await submit("main > .consultation-card form");
    await until(`document.body.innerText.includes(${JSON.stringify(topic)})`);
    await networkIdle();
}
try {
    let current = state();
    await login("coach");
    await goto("/consultations?view=coach");
    if (!current.slot) {
        await evaluate("document.querySelector('main > details').open=true");
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                timeZone: "Asia/Tehran",
            })
                .formatToParts(new Date(Date.now() + 3 * 86400000))
                .map((p) => [p.type, p.value]),
        );
        for (const [label, key] of [
            ["سال", "year"],
            ["ماه", "month"],
            ["روز", "day"],
        ])
            await fill(
                `details select[aria-label^="${label}"]`,
                String(Number(parts[key])),
            );
        await fill("details input[type=time]", "14:20");
        await evaluate(
            "document.querySelector('details input[type=checkbox]').click()",
        );
        await submit("main > details form");
        await until(
            "document.querySelector('details input[type=time]').value==='' && !!document.querySelector('[data-slot-id]')",
        );
        await networkIdle();
        current = state();
    }
    const slot = `[data-slot-id="${current.slot.id}"]`;
    await fill(
        slot + " input[type=url]",
        "https://meet.google.com/xyz-abcd-efg",
    );
    await submit(slot + " form");
    await networkIdle();
    assert.equal(
        state().slot.meeting_url,
        "https://meet.google.com/xyz-abcd-efg",
    );
    if (!current.slot.booked) {
        if (!state().slot.published) {
            await evaluate(
                `document.querySelector(${JSON.stringify(slot + " .stack-form > button")}).click()`,
            );
            await networkIdle();
        }
        await evaluate(
            `document.querySelector(${JSON.stringify(slot + " .stack-form > button")}).click()`,
        );
        await until(
            `document.querySelector(${JSON.stringify(slot)}).innerText.includes('پنهان')`,
        );
        await networkIdle();
        assert.equal(state().slot.published, false);
        await evaluate(
            `document.querySelector(${JSON.stringify(slot + " .stack-form > button")}).click()`,
        );
        await until(
            `document.querySelector(${JSON.stringify(slot)}).innerText.includes('قابل رزرو')`,
        );
        await networkIdle();
        assert.equal(state().slot.published, true);
    }
    await responsive("Coach consultation actions");
    await login("student");
    await goto("/consultations");
    current = state();
    if (!current.booking) {
        await fill(slot + " textarea", "بازبینی دسترسی مشاوره صرفاً آزمایشی");
        await submit(slot + " form");
        await until("!!document.querySelector('[data-booking-id]')");
        await networkIdle();
        current = state();
    }
    if (current.booking.status === "confirmed") {
        const booking = `[data-booking-id="${current.booking.id}"]`;
        await fill(
            booking + " textarea",
            "لغو صرفاً آزمایشی برای بررسی دسترسی جاری",
        );
        await submit(booking + " form");
        await until(
            `document.querySelector(${JSON.stringify(booking)}).innerText.includes('لغوشده')`,
        );
        await networkIdle();
    }
    assert.equal(state().booking.status, "cancelled");
    assert.equal(state().slot.booked, false);
    current = state();
    if (!current.requests.length) {
        await request("بازبینی دسترسی مشاوره صرفاً آزمایشی — انصراف صاحب");
        current = state();
    }
    if (current.requests[0].status === "pending") {
        const selector = `[data-coordination-request-id="${current.requests[0].id}"]`;
        await fill(selector + " textarea", "انصراف صرفاً آزمایشی صاحب درخواست");
        await submit(selector + " form");
        await networkIdle();
    }
    assert.equal(state().requests[0].status, "withdrawn");
    if (state().requests.length === 1)
        await request("بازبینی دسترسی مشاوره صرفاً آزمایشی — تصمیم ادمین");
    await responsive("Student cancellation and withdrawal");
    await login("admin");
    await goto("/consultations?view=admin");
    current = state();
    if (current.requests[1].status === "pending") {
        const selector = `[data-coordination-request-id="${current.requests[1].id}"]`;
        await fill(
            selector + " textarea",
            "بستن صرفاً آزمایشی توسط ادمین فعال",
        );
        await submit(selector + " form:last-of-type");
        await networkIdle();
    }
    assert.equal(state().requests[1].status, "declined");
    await responsive("Admin coordination closure");
    await login("student", "09121234564");
    await goto("/consultations");
    assert(
        !(await evaluate(
            "document.body.innerText.includes('بازبینی دسترسی مشاوره صرفاً آزمایشی')",
        )),
    );
    const denied = await evaluate(
        `(async()=>{const r=await fetch('/consultations/${current.booking.id}',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({version:1,action:'cancel',reason:'Software-only peer denial'})});await r.text();return r.status;})()`,
    );
    assert.equal(denied, 403);
    assert.equal(state().booking.status, "cancelled");
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "real-coach-free-slot-form-jalali-time-hide-show-and-meeting-update",
                "real-student-booking-owner-cancellation-and-request-withdrawal",
                "real-current-admin-coordination-close-and-unrelated-student403",
                "resumable-owned-fixture-no-reset-no-live-contact-or-provider-request",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
