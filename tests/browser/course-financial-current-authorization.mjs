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

const title = "دوره بررسی مالی جاری صرفاً آزمایشی";
const benefitTitle = "مشاوره همراه خرید صرفاً آزمایشی";
const proof = Buffer.from(
    "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS-COURSE68\n%%EOF\n",
);
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','بررسی مالی دوره صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Owned synthetic fixture only.');}$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$courses=\App\Models\Course::all();if($courses->count()>1||$courses->contains(fn($c)=>$c->title!=='دوره بررسی مالی جاری صرفاً آزمایشی'||$c->price!==1000000)){throw new RuntimeException('Preserve unrelated courses.');}$course=$courses->first();$cohorts=\App\Models\Cohort::all();if($cohorts->count()>1||$cohorts->contains(fn($c)=>!$course||$c->course_id!==$course->id)){throw new RuntimeException('Preserve unrelated cohorts.');}$cohort=$cohorts->first();$enrollments=\App\Models\Enrollment::where('user_id',$student->id)->get();if($enrollments->count()>1||$enrollments->contains(fn($e)=>!$cohort||$e->user_id!==$student->id||$e->cohort_id!==$cohort->id||$e->amount!==1000000)){throw new RuntimeException('Preserve unrelated enrollments.');}$enrollment=$enrollments->first();$contract=$enrollment?\App\Models\EnrollmentConsultationBenefit::where('enrollment_id',$enrollment->id)->where('billing_version',$enrollment->billing_version)->first():null;$benefit=$cohort?\App\Models\CohortConsultationBenefit::where('cohort_id',$cohort->id)->first():null;$payments=\App\Models\Payment::where('user_id',$student->id)->get();if($payments->count()>1||$payments->contains(fn($p)=>!$enrollment||$p->user_id!==$student->id||$p->enrollment_id!==$enrollment->id||$p->amount!==1000000||$p->method!=='manual')){throw new RuntimeException('Preserve unrelated payments.');}$payment=$payments->first();$receipt=$payment?->receipt;if(\App\Models\PaymentReceipt::count()!==($receipt?1:0)||($receipt&&$receipt->transfer_reference!=='SOFTWAREONLYCOURSE68')||\App\Models\ConsultationGrant::count()>1){throw new RuntimeException('Preserve unrelated receipts or grants.');}$grant=$contract?->consultation_grant_id?\App\Models\ConsultationGrant::findOrFail($contract->consultation_grant_id):null;if($grant&&($grant->student_id!==$student->id||$grant->coach_id!==$coach->id)){throw new RuntimeException('Preserve unrelated grant participants.');}echo json_encode(['coach'=>$coach->only('id','is_active'),'course'=>$course?$course->only('id','coach_id'):null,'cohort'=>$cohort?$cohort->only('id','manual_payment_enabled'):null,'benefit'=>$benefit?$benefit->only('enabled','sessions','revision'):null,'enrollment'=>$enrollment?[...$enrollment->only('id','status','billing_version'),'expired_without_receipt'=>$enrollment->status==='pending_payment'&&$enrollment->expires_at?->isPast()&&!$receipt]:null,'contract'=>$contract?[...$contract->only('id','status','issue_reason'),'sessions'=>$contract->definition['sessions'],'definition_hash'=>hash('sha256',json_encode($contract->definition,JSON_THROW_ON_ERROR))]:null,'payment'=>$payment?$payment->only('id','status','amount'):null,'receipt'=>$receipt?[...$receipt->only('id','status'),'hash'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('receipts')->path($receipt->file_path))]:null,'grant'=>$grant?$grant->only('id','total_sessions','student_id','coach_id'):null,'allocations'=>(int)\App\Models\PaymentAllocation::sum('amount')]);`;
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
async function editor(kind) {
    const word = kind === "benefit" ? "مشاوره همراه دوره" : "تنظیم پرداخت";
    await evaluate(
        `(()=>{const el=Array.from(document.querySelectorAll('main details')).find(el=>el.querySelector('summary')?.innerText.includes(${JSON.stringify(word)}));if(!el)throw Error('Missing editor');el.id=${JSON.stringify(kind + "68")};el.open=true;})()`,
    );
    await until(`!!document.querySelector('#${kind}68[open]')`);
}
async function coachActivity(active) {
    if (state().coach.is_active === active) return;
    await goto("/admin/users");
    await evaluate(
        `document.querySelector('[data-user-id="${state().coach.id}"] button').click()`,
    );
    await until("!!document.querySelector('[data-testid=user-access]')");
    assert.equal(
        await evaluate(
            "document.querySelector('[name=access_active]').checked",
        ),
        !active,
    );
    await evaluate("document.querySelector('[name=access_active]').click()");
    await submit("[data-testid=user-access]");
    await until("!document.querySelector('[data-testid=user-access]')");
    await networkIdle();
    assert.equal(state().coach.is_active, active);
}
try {
    let current = state();
    await login("admin");
    await goto("/admin/courses");
    if (!current.course) {
        assert(
            await evaluate(
                "document.body.innerText.includes('هنوز دوره‌ای ساخته نشده')",
            ),
        );
        await evaluate(
            "document.querySelector('main .page-intro button').click()",
        );
        await fill('.form-grid input[maxlength="150"]', title);
        await fill(
            ".form-grid textarea",
            "این دوره فقط برای بررسی نرم‌افزاری است؛ هیچ خدمت یا وجه واقعی مبادله نمی‌شود.",
        );
        await fill('.form-grid input[max="1000000000"]', "100000");
        await fill('.form-grid input[max="10000"]', "10");
        await evaluate(
            "document.querySelector('.form-grid input[type=checkbox]').click()",
        );
        await submit(".form-grid");
        await until(
            `!document.querySelector('.form-grid') && document.body.innerText.includes(${JSON.stringify(title)})`,
        );
        await networkIdle();
        current = state();
    }
    if (!current.course.coach_id) {
        lease();
        await fill(".coach-select", String(current.coach.id));
        await networkIdle();
        await goto("/admin/courses");
    }
    if (!state().cohort.manual_payment_enabled) {
        await editor("billing");
        await evaluate(
            "document.querySelectorAll('#billing68 input[type=checkbox]')[1].click()",
        );
        await submit("#billing68 form");
        await until("!document.querySelector('#billing68[open]')");
        await networkIdle();
    }
    current = state();
    if (!current.benefit) {
        await editor("benefit");
        await evaluate(
            "document.querySelector('#benefit68 input[type=checkbox]').click()",
        );
        await fill('#benefit68 input[maxlength="160"]', benefitTitle);
        await fill(
            "#benefit68 select:not(:has(option[value=first_payment]))",
            String(current.coach.id),
        );
        await fill('#benefit68 input[max="100"]', "2");
        await fill('#benefit68 input[max="365"]', "30");
        await fill(
            "#benefit68 select:has(option[value=first_payment])",
            "first_payment",
        );
        await evaluate(
            "document.querySelectorAll('#benefit68 input[type=checkbox]')[1].click()",
        );
        await submit("#benefit68 form");
        await until("!document.querySelector('#benefit68[open]')");
        await networkIdle();
    }
    current = state();
    await login("student");
    await goto("/courses");
    if (!current.enrollment || current.enrollment.expired_without_receipt) {
        if (current.enrollment)
            assert(
                !current.receipt,
                "Preserve existing evidence and financial history",
            );
        lease();
        await evaluate(
            "document.querySelector('.cohort-checkout button').click()",
        );
        await until("location.pathname==='/learning'");
        await networkIdle();
    }
    current = state();
    const frozen = current.contract;
    assert(frozen && [2, 3].includes(frozen.sessions));
    await goto("/finance");
    await responsive("Owner frozen course financial promise");
    if (!current.receipt) {
        await evaluate(
            "document.querySelector('.receipt-upload button').click()",
        );
        await fill(
            ".receipt-upload-form input[dir=ltr]",
            "SOFTWAREONLYCOURSE68",
        );
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                timeZone: "Asia/Tehran",
            })
                .formatToParts(new Date())
                .map((p) => [p.type, p.value]),
        );
        for (const [label, key] of [
            ["سال", "year"],
            ["ماه", "month"],
            ["روز", "day"],
        ])
            await fill(
                `.receipt-upload-form select[aria-label^="${label}"]`,
                String(Number(parts[key])),
            );
        lease();
        await writeFile("/tmp/kardorost-course68-software-only.pdf", proof, {
            mode: 0o600,
        });
        const { root } = await send("DOM.getDocument");
        const { nodeId } = await send("DOM.querySelector", {
            nodeId: root.nodeId,
            selector: ".receipt-upload-form input[type=file]",
        });
        await send("DOM.setFileInputFiles", {
            nodeId,
            files: ["/tmp/kardorost-course68-software-only.pdf"],
        });
        await submit(".receipt-upload-form");
        await until(
            "document.body.innerText.includes('رسید شما در انتظار بررسی مدیریت است.')",
        );
        await networkIdle();
        current = state();
        assert.equal(current.grant, null);
        assert.equal(current.allocations, 0);
    }
    await login("admin");
    await goto("/admin/courses");
    current = state();
    if (current.benefit.enabled && current.benefit.sessions === 2) {
        await editor("benefit");
        await fill('#benefit68 input[max="100"]', "3");
        await evaluate(
            "document.querySelectorAll('#benefit68 input[type=checkbox]')[1].click()",
        );
        await submit("#benefit68 form");
        await until("!document.querySelector('#benefit68[open]')");
        await networkIdle();
    }
    assert.equal(state().contract.definition_hash, frozen.definition_hash);
    current = state();
    if (current.receipt.status === "pending") {
        await coachActivity(false);
        await goto("/admin/finance");
        await evaluate(
            "Array.from(document.querySelectorAll('.receipt-review-card button')).find(b=>b.innerText==='بررسی رسید').click()",
        );
        await fill(
            ".receipt-review-card form input[inputmode=numeric]",
            "100000",
        );
        await fill(
            ".receipt-review-card form textarea",
            "بررسی صرفاً نرم‌افزاری؛ هیچ وجه واقعی دریافت نشده است",
        );
        await evaluate(
            "document.querySelector('.receipt-review-card form input[type=checkbox]').click()",
        );
        await submit(".receipt-review-card form");
        await until("!document.querySelector('.receipt-review-card form')");
        await networkIdle();
        current = state();
        assert.equal(current.payment.status, "paid");
        assert.equal(current.allocations, 1000000);
        assert.equal(current.contract.status, "blocked");
        assert.equal(current.contract.issue_reason, "coach_unavailable");
        assert.equal(current.grant, null);
    }
    current = state();
    if (current.contract.status === "blocked") {
        await coachActivity(true);
        await goto("/admin/course-benefits");
        await until("!!document.querySelector('main article button')");
        lease();
        await evaluate("document.querySelector('main article button').click()");
        await until(
            "document.body.innerText.includes('سهمیه صادر شده') && !document.querySelector('main article button')",
        );
        await networkIdle();
    }
    current = state();
    assert.equal(current.contract.status, "issued");
    assert.equal(current.grant.total_sessions, frozen.sessions);
    assert.equal(current.contract.definition_hash, frozen.definition_hash);
    await goto("/admin/courses");
    if (state().benefit.enabled) {
        await editor("benefit");
        await evaluate(
            "document.querySelector('#benefit68 input[type=checkbox]').click()",
        );
        await submit("#benefit68 form");
        await until("!document.querySelector('#benefit68[open]')");
        await networkIdle();
    }
    assert.equal(state().benefit.enabled, false);
    assert.equal(state().grant.id, current.grant.id);
    await login("student");
    await goto("/consultation-grants");
    await until(
        "document.querySelectorAll('.consultation-grant-card').length===1",
    );
    assert.equal(
        await evaluate("document.body.innerText.includes('COURSE-BENEFIT-')"),
        false,
    );
    await responsive("Owner issued frozen course consultation grant");
    await goto("/finance");
    await responsive("Owner paid course invoice");
    const { createHash } = await import("node:crypto");
    assert.equal(
        current.receipt.hash,
        createHash("sha256").update(proof).digest("hex"),
    );
    lease();
    const download = await evaluate(
        `(async()=>{const r=await fetch('/receipts/${current.receipt.id}/file');const bytes=await r.arrayBuffer();return{status:r.status,cache:r.headers.get('Cache-Control'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(v=>v.toString(16).padStart(2,'0')).join('')};})()`,
    );
    assert.equal(download.status, 200);
    assert.equal(download.hash, current.receipt.hash);
    assert(download.cache.includes("no-store"));
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until("innerWidth===390");
    lease();
    await writeFile(
        "/tmp/kardorost-course68-mobile.png",
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
    await login("student", "09121234564");
    await goto("/finance");
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify("MANUAL-" + current.receipt.id)})`,
        ),
        false,
        "The peer can see their own course quote but cannot see the original owner's receipt reference",
    );
    const denied = await evaluate(
        `(async()=>{const r=await fetch('/receipts/${current.receipt.id}/file',{headers:{Accept:'application/json'}});await r.text();return r.status;})()`,
    );
    assert.equal(denied, 403);
    assert.equal(state().grant.id, current.grant.id);
    assert.equal(state().contract.definition_hash, frozen.definition_hash);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            software_only_no_real_funds: true,
            checks: [
                "empty-course-list-actual-admin-create-publish-assign-coach-manual-payment",
                "actual-benefit-configure-next-edition-and-disable-keeps-original-promise",
                "student-checkout-jalali-private-receipt-does-not-issue-grant",
                "actual-admin-coach-deactivate-independent-receipt-review-records-money-but-blocks-grant",
                "actual-admin-reactivate-and-retry-issues-one-frozen-grant",
                "private-proof-byte-hash-no-store-peer403-source-reference-private",
                "resumable-owned-fixture-preserves-expired-quotes-and-renews-through-actual-checkout",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
