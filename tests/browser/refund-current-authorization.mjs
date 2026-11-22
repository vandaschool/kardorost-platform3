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
    new URL(base).port === "8031" &&
        process.env.DB_DATABASE === "/tmp/kardorost-linkedin-browser.sqlite" &&
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
const marker = "SOFTWAREONLYREFUND65";
const reason =
    "درخواست صرفاً آزمایشی نرم‌افزار؛ بدون دریافت یا برگشت واقعی وجه";
const proof = Buffer.from(
    "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-BANK-TRANSFER65\n%%EOF\n",
);
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Only the owned synthetic fixture.');}$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$payments=\App\Models\Payment::all();if($payments->count()>1||$payments->contains(fn($p)=>$p->user_id!==$student->id||$p->reference!=='SOFTWARE-ONLY-REFUND65'||$p->amount!==1000000||!$p->enrollment_id||$p->consultation_id||$p->package_purchase_id||$p->organization_invoice_id)){throw new RuntimeException('Preserve other payments.');}$payment=$payments->first();if(!$payment&&($argv[1]??'')==='create'){if(\App\Models\Refund::count()!==0){throw new RuntimeException('Preserve existing refunds.');}$enrollment=\App\Models\Enrollment::where('user_id',$student->id)->firstOrFail();$payment=\App\Models\Payment::create(['enrollment_id'=>$enrollment->id,'installment_id'=>null,'user_id'=>$student->id,'amount'=>1000000,'method'=>'gateway','status'=>'review_required','captured_at'=>now(),'paid_at'=>now(),'reference'=>'SOFTWARE-ONLY-REFUND65']);}if(!$payment){throw new RuntimeException('Initialize the software-only fixture explicitly.');}$refund=$payment->refund;if(\App\Models\Refund::count()!==($refund?1:0)||\App\Models\PaymentAllocation::where('payment_id',$payment->id)->exists()){throw new RuntimeException('Preserve unrelated or allocated funds.');}echo json_encode(['payment'=>$payment->only('id','status','amount'),'refund'=>$refund?$refund->only('id','status','version','transfer_reference'):null,'proof_hash'=>$refund?->file_path?hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('refunds')->path($refund->file_path)):null]);`;
function state(create = false) {
    if (create) lease();
    return JSON.parse(
        execFileSync(
            process.env.BROWSER_PHP_BINARY,
            ["-r", code, create ? "create" : "read"],
            {
                encoding: "utf8",
                env: {
                    ...process.env,
                    DB_CONNECTION: "sqlite",
                    SMS_DRIVER: "disabled",
                    ZIBAL_ENABLED: "false",
                },
            },
        ),
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
            "Refund layout width " + width,
        );
    }
}
try {
    let current = state(true);
    const paymentId = current.payment.id;
    await login("student");
    await goto("/refunds?payment=" + paymentId);
    if (!current.refund) {
        await until("!!document.querySelector('article form textarea')");
        await fill("article form input[dir=ltr]", "IR820540102680020817909002");
        await fill("article form textarea", reason);
        await evaluate(
            "document.querySelector('article form input[type=checkbox]').click()",
        );
        await submit("article form");
        await until(
            `document.body.innerText.includes(${JSON.stringify(reason)}) && !!document.querySelector('.refund-history')`,
        );
        await networkIdle();
        current = state();
    }
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('IR820540102680020817909002')",
        ),
        false,
    );
    await responsive();
    await login("admin");
    await goto("/refunds?view=admin&status=all&payment=" + paymentId);
    if (current.refund.status === "requested") {
        await fill("article select", "approve");
        await fill(
            "article textarea",
            "بررسی صرفاً نرم‌افزاری؛ هیچ انتقال واقعی انجام نشده است",
        );
        await submit("article form");
        await until(
            "document.body.innerText.includes('تأییدشده؛ منتظر واریز') && !document.querySelector('article select:not([aria-label])')",
        );
        await networkIdle();
        current = state();
    }
    if (current.refund.status === "approved") {
        assert.equal(
            await evaluate("!!document.querySelector('article a')"),
            false,
            "Approval alone has no transfer evidence",
        );
        await fill("article form input[inputmode=numeric]", "100000");
        await evaluate(
            `(()=>{const a=document.querySelectorAll('article form input[dir=ltr]');for(const [i,value] of ['IR820540102680020817909002',${JSON.stringify(marker)}].entries()){a[i].value=value;a[i].dispatchEvent(new Event('input',{bubbles:true}));}})()`,
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
                `article select[aria-label^="${label}"]`,
                String(Number(parts[key])),
            );
        lease();
        await writeFile("/tmp/kardorost-refund65-software-only.pdf", proof, {
            mode: 0o600,
        });
        const { root } = await send("DOM.getDocument");
        const { nodeId } = await send("DOM.querySelector", {
            nodeId: root.nodeId,
            selector: "article input[type=file]",
        });
        await send("DOM.setFileInputFiles", {
            nodeId,
            files: ["/tmp/kardorost-refund65-software-only.pdf"],
        });
        for (let i = 0; i < 2; i++)
            await evaluate(
                `document.querySelectorAll('article form input[type=checkbox]')[${i}].click()`,
            );
        await submit("article form");
        await until(
            `!document.querySelector('article form') && document.body.innerText.includes('${marker}')`,
        );
        await networkIdle();
        current = state();
    }
    assert.equal(current.refund.status, "completed");
    assert.equal(current.payment.status, "refunded");
    assert.equal(current.payment.amount, 1000000);
    const { createHash } = await import("node:crypto");
    assert.equal(
        current.proof_hash,
        createHash("sha256").update(proof).digest("hex"),
    );
    await login("student");
    await goto("/refunds?payment=" + paymentId);
    await until(`document.body.innerText.includes('${marker}')`);
    const fileUrl = new URL(
        await evaluate("document.querySelector('article a').href"),
        base,
    ).pathname;
    lease();
    const downloaded = await evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(fileUrl)});const bytes=new Uint8Array(await r.arrayBuffer());const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(v=>v.toString(16).padStart(2,'0')).join('');return{status:r.status,cache:r.headers.get('Cache-Control'),type:r.headers.get('X-Content-Type-Options'),hash};})()`,
    );
    assert.equal(downloaded.status, 200);
    assert.equal(downloaded.hash, current.proof_hash);
    assert(
        downloaded.cache.includes("private") &&
            downloaded.cache.includes("no-store"),
    );
    assert.equal(downloaded.type, "nosniff");
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('IR820540102680020817909002')",
        ),
        false,
    );
    await responsive();
    lease();
    await writeFile(
        "/tmp/kardorost-refund65-mobile.png",
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
    await goto("/refunds");
    assert.equal(
        await evaluate(`document.body.innerText.includes('${marker}')`),
        false,
    );
    const denied = await evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(fileUrl)},{headers:{Accept:'application/json'}});await r.text();return r.status;})()`,
    );
    assert.equal(denied, 403);
    assert.equal(state().proof_hash, current.proof_hash);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            software_only_no_real_funds: true,
            checks: [
                "actual-owner-request-admin-approve-and-manual-proof-form",
                "approval-does-not-create-transfer-evidence",
                "jalali-date-and-rial-toman-full-amount",
                "private-proof-byte-hash-no-store-nosniff",
                "owner-iban-masked-peer-file403",
                "resumable-owned-five-user-fixture-without-reset",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
