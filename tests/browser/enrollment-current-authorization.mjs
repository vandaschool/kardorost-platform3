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
    lease();
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
    await send("Page.navigate", { url: "about:blank" });
    await until("location.href==='about:blank'");
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
    new URL(base).port === "8033" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-course-financial-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned five-user native fixture.",
);

function lease() {
    assert(
        process.env.KARDOROST_LEASE_OWNER,
        "Supply the active delivery lease owner.",
    );
    execFileSync(
        "python3",
        [
            "/workspace/work/kardorost-autonomy-lease.py",
            "touch",
            process.env.KARDOROST_LEASE_OWNER,
        ],
        { stdio: "pipe" },
    );
}

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','بررسی مالی دوره صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Owned synthetic fixture only.');}$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$peer=\App\Models\User::where('phone','989121234564')->firstOrFail();$course=\App\Models\Course::sole();$cohort=\App\Models\Cohort::sole();if($course->title!=='دوره بررسی مالی جاری صرفاً آزمایشی'||$course->price!==1000000||$cohort->course_id!==$course->id||!$cohort->manual_payment_enabled){throw new RuntimeException('Preserve the original course financial fixture.');}$original=\App\Models\Enrollment::where('user_id',$student->id)->sole();$contract=\App\Models\EnrollmentConsultationBenefit::where('enrollment_id',$original->id)->where('billing_version',$original->billing_version)->sole();$grant=\App\Models\ConsultationGrant::findOrFail($contract->consultation_grant_id);$payment=\App\Models\Payment::where('user_id',$student->id)->sole();$receipt=$payment->receipt;if($original->status!=='active'||$original->payment_plan!=='full'||$contract->status!=='issued'||$grant->total_sessions!==2||$payment->status!=='paid'||$receipt->transfer_reference!=='SOFTWAREONLYCOURSE68'){throw new RuntimeException('Preserve the completed original financial promise.');}$benefit=\App\Models\CohortConsultationBenefit::where('cohort_id',$cohort->id)->sole();if($benefit->enabled){throw new RuntimeException('Preserve the stopped future benefit.');}$quote=\App\Models\Enrollment::where('user_id',$peer->id)->first();if(\App\Models\Enrollment::count()!==($quote?2:1)||($quote&&($quote->cohort_id!==$cohort->id||$quote->payment_plan!=='installments'||$quote->amount!==1000000))||\App\Models\Payment::count()!==1||\App\Models\PaymentReceipt::count()!==1||\App\Models\ConsultationGrant::count()!==1){throw new RuntimeException('Preserve unrelated enrollment or financial records.');}$originalHash=hash('sha256',json_encode([$original->getAttributes(),$original->installments()->get()->map->getAttributes()->all(),$contract->getAttributes(),$grant->getAttributes(),$payment->getAttributes(),$receipt->getAttributes()],JSON_THROW_ON_ERROR));$quoteRows=$quote?$quote->installments()->where('billing_version',$quote->billing_version)->get():collect();echo json_encode(['cohort_id'=>$cohort->id,'billing_revision'=>$cohort->billing_revision,'plan_amounts'=>$cohort->installmentPlan()->orderBy('sequence')->pluck('amount')->map(fn($n)=>(int)$n)->all(),'original_hash'=>$originalHash,'receipt_id'=>$receipt->id,'receipt_hash'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('receipts')->path($receipt->file_path)),'quote'=>$quote?[...$quote->only('id','status','amount'),'reusable'=>$quote->expires_at?->isFuture(),'amounts'=>$quoteRows->pluck('amount')->map(fn($n)=>(int)$n)->all(),'hash'=>hash('sha256',json_encode([$quote->getAttributes(),$quoteRows->map->getAttributes()->all()],JSON_THROW_ON_ERROR))]:null]);`;
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
async function responsive() {
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
            "Frozen enrollment financial view " + width,
        );
    }
}
async function plan(amounts) {
    await goto("/admin/courses");
    await evaluate(
        "(()=>{const el=Array.from(document.querySelectorAll('main details')).find(el=>el.querySelector('summary')?.innerText.includes('تنظیم پرداخت'));el.id='plan69';el.open=true;})()",
    );
    if (
        !(await evaluate(
            "document.querySelector('#plan69 input[type=checkbox]').checked",
        ))
    )
        await evaluate(
            "document.querySelector('#plan69 input[type=checkbox]').click()",
        );
    await until(
        "document.querySelectorAll('#plan69 .billing-part-row').length===2",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('#plan69 .billing-part-row input[inputmode=numeric]')).forEach((el,i)=>el.id='plan69amount'+i)",
    );
    await fill("#plan69amount0", String(amounts[0]));
    await fill("#plan69amount1", String(amounts[1]));
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + 30 * 86400000))
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ])
        await fill(
            `#plan69 select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    await submit("#plan69 form");
    await until("!document.querySelector('#plan69[open]')");
    await networkIdle();
    assert.deepEqual(
        state().plan_amounts,
        amounts.map((n) => n * 10),
    );
}
async function signup() {
    await goto("/courses");
    await fill(".cohort-checkout select", "installments");
    lease();
    await evaluate("document.querySelector('.cohort-checkout button').click()");
    await until("location.pathname==='/learning'");
    await networkIdle();
}
try {
    const initial = state();
    await login("admin");
    if (!initial.quote) {
        await plan([40000, 60000]);
    }
    let current = state();
    if (current.quote)
        assert.deepEqual(
            current.quote.amounts,
            [400000, 600000],
            "Preserve the original peer quote",
        );
    await login("student", "09121234564");
    if (!current.quote) {
        await signup();
        current = state();
    }
    const originalQuote = current.quote;
    assert.deepEqual(originalQuote.amounts, [400000, 600000]);
    await goto("/finance");
    await responsive();
    for (const amount of [40000, 60000])
        assert(
            await evaluate(
                `document.body.innerText.includes('${new Intl.NumberFormat("fa-IR").format(amount)}')`,
            ),
        );
    await login("admin");
    if (
        JSON.stringify(state().plan_amounts) !==
        JSON.stringify([500000, 500000])
    )
        await plan([50000, 50000]);
    assert.equal(state().original_hash, initial.original_hash);
    assert.equal(state().quote.hash, originalQuote.hash);
    await login("student", "09121234564");
    let reused = false;
    if (state().quote.reusable) {
        await signup();
        reused = true;
    }
    await goto("/finance");
    assert.equal(state().quote.hash, originalQuote.hash);
    assert.equal(state().original_hash, initial.original_hash);
    const denied = await evaluate(
        `(async()=>{const r=await fetch('/admin/cohorts/${current.cohort_id}/billing',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({expected_revision:${state().billing_revision},installment_enabled:false,manual_payment_enabled:false,parts:[]})});await r.text();return r.status;})()`,
    );
    assert.equal(denied, 403);
    assert.equal(state().quote.hash, originalQuote.hash);
    assert.equal(state().original_hash, initial.original_hash);
    lease();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until("innerWidth===390");
    await until(
        "document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth",
    );
    await writeFile(
        "/tmp/kardorost-enrollment69-mobile.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: true,
                })
            ).data,
            "base64",
        ),
    );
    await login("student");
    await goto("/finance");
    lease();
    const download = await evaluate(
        `(async()=>{const r=await fetch('/receipts/${initial.receipt_id}/file');const bytes=await r.arrayBuffer();return{status:r.status,hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(v=>v.toString(16).padStart(2,'0')).join('')};})()`,
    );
    assert.equal(download.status, 200);
    assert.equal(download.hash, initial.receipt_hash);
    assert.equal(state().original_hash, initial.original_hash);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            held_quote_reused: reused,
            no_new_payment: true,
            checks: [
                "actual-admin-40-60-installment-plan-and-jalali-due-date",
                "actual-peer-student-signup-with-current-verified-role",
                "future-50-50-plan-preserves-held-40-60-quote-and-original-paid-contract-grant-receipt-bytes",
                "same-held-enrollment-reuse-without-deadline-or-contract-rewrite",
                "student-cannot-mutate-admin-plan403",
                "expired-held-quote-preserved-on-resume-no-reset",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
