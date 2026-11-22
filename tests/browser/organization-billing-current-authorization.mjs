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
    new URL(base).port === "8032" &&
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

const title = "صورتحساب بررسی دسترسی جاری صرفاً آزمایشی";
const organizationName = "سابقه ارزیابی صرفاً آزمایشی";
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Owned synthetic fixture only.');}$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();$organization=$manager->organizations()->wherePivot('kind','manager')->where('organizations.name','سابقه ارزیابی صرفاً آزمایشی')->firstOrFail();$invoices=\App\Models\OrganizationInvoice::all();if($invoices->count()>1||$invoices->contains(fn($i)=>$i->organization_id!==$organization->id||$i->contract_reference!=='SOFTWARE-ONLY-CONTRACT66'||!str_starts_with($i->title,'صورتحساب بررسی دسترسی جاری صرفاً آزمایشی')||$i->amount!==1000000)){throw new RuntimeException('Preserve unrelated invoices.');}$invoice=$invoices->first();$payments=\App\Models\Payment::whereNotNull('organization_invoice_id')->orderBy('id')->get();if($payments->count()>2||$payments->contains(fn($p)=>!$invoice||$p->organization_invoice_id!==$invoice->id||$p->user_id!==$manager->id||$p->method!=='manual'||!in_array($p->amount,[400000,600000],true))){throw new RuntimeException('Preserve unrelated organization payments.');}$receipts=\App\Models\PaymentReceipt::orderBy('id')->get();if($receipts->count()!==$payments->count()||$receipts->contains(fn($r)=>!in_array($r->transfer_reference,['SOFTWAREONLYORG66A','SOFTWAREONLYORG66B'],true)||!$payments->contains('id',$r->payment_id))){throw new RuntimeException('Preserve unrelated private receipts.');}echo json_encode(['organization_id'=>$organization->id,'invoice'=>$invoice?$invoice->only('id','title','status','revision','amount'):null,'paid'=>$invoice?->paidAmount(),'remaining'=>$invoice?->remainingAmount(),'receipts'=>$receipts->map(fn($r)=>[...$r->only('id','status','transfer_reference'),'hash'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('receipts')->path($r->file_path))])->all(),'personal_payment_hash'=>hash('sha256',\App\Models\Payment::whereNull('organization_invoice_id')->orderBy('id')->get()->toJson())]);`;
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
async function date(selector, future = false) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + (future ? 7 * 86400000 : 0)))
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ])
        await fill(
            `${selector} select[aria-label^="${label}"]`,
            String(Number(parts[key])),
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
            "Organization invoice layout " + width,
        );
    }
}
async function managerLogin() {
    await login("organization");
    lease();
    await fill(
        'select[aria-label="انتخاب سازمان"]',
        String(state().organization_id),
    );
    await until(
        `document.querySelector('main h1').innerText===${JSON.stringify(organizationName)}`,
    );
    await networkIdle();
}
assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide the owned organization-manager fixture phone",
);
try {
    const initial = state();
    await login("admin");
    await goto("/admin/organization-invoices");
    if (!initial.invoice) {
        await evaluate("document.querySelector('main details').open=true");
        await fill("[name=organization_id]", String(initial.organization_id));
        for (const [name, value] of [
            ["invoice_title", title],
            ["contract_reference", "SOFTWARE-ONLY-CONTRACT66"],
            ["line_title_0", "خدمات صرفاً نرم‌افزاری؛ بدون دریافت واقعی وجه"],
            ["line_price_0", "100000"],
        ])
            await fill(`[name=${name}]`, value);
        await date("details", true);
        await submit("details form");
        await until(
            "location.pathname.startsWith('/admin/organization-invoices/') && !!document.querySelector('[name=invoice_issue_reviewed]')",
        );
        await networkIdle();
    }
    let current = state();
    const invoiceId = current.invoice.id;
    await goto("/admin/organization-invoices/" + invoiceId);
    if (current.invoice.status === "draft") {
        if (current.invoice.title === title) {
            await fill("[name=invoice_title]", title + " — بازبینی");
            await submit("form:has([name=invoice_title])");
            await until(
                `document.querySelector('main h1').innerText===${JSON.stringify(title + " — بازبینی")}`,
            );
            await networkIdle();
        }
        await evaluate(
            "document.querySelector('[name=invoice_issue_reviewed]').click()",
        );
        await submit("form:has([name=invoice_issue_reviewed])");
        await until(
            "document.querySelector('.eyebrow').innerText.includes('صادرشده') && !document.querySelector('[name=invoice_title]')",
        );
        await networkIdle();
    }
    await responsive();
    for (const [index, amount] of [40000, 60000].entries()) {
        current = state();
        let receipt = current.receipts[index];
        const reference = "SOFTWAREONLYORG66" + (index === 0 ? "A" : "B");
        const proof = Buffer.from(
            "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS-ORG66-" +
                index +
                "\n%%EOF\n",
        );
        if (!receipt) {
            assert.equal(current.remaining, index === 0 ? 1000000 : 600000);
            await managerLogin();
            await goto("/organization/invoices/" + invoiceId);
            await evaluate("document.querySelector('main details').open=true");
            await fill("[name=payment_amount]", String(amount));
            await fill("[name=transfer_reference]", reference);
            await date("details");
            const path = "/tmp/kardorost-org66-software-only-" + index + ".pdf";
            lease();
            await writeFile(path, proof, { mode: 0o600 });
            const { root } = await send("DOM.getDocument");
            const { nodeId } = await send("DOM.querySelector", {
                nodeId: root.nodeId,
                selector: "details input[type=file]",
            });
            await send("DOM.setFileInputFiles", { nodeId, files: [path] });
            await submit("details form");
            await until(
                "document.body.innerText.includes('رسید در انتظار بررسی')",
            );
            await networkIdle();
            current = state();
            receipt = current.receipts[index];
            assert.equal(
                current.paid,
                index === 0 ? 0 : 400000,
                "Receipt upload alone does not settle debt",
            );
            assert.equal(current.invoice.status, "issued");
        }
        assert.equal(receipt.transfer_reference, reference);
        const { createHash } = await import("node:crypto");
        assert.equal(
            receipt.hash,
            createHash("sha256").update(proof).digest("hex"),
        );
        if (receipt.status === "pending") {
            await login("admin");
            await goto("/admin/organization-invoices/" + invoiceId);
            await evaluate(
                "Array.from(document.querySelectorAll('.receipt-review-card button')).find(b=>b.innerText==='بررسی رسید').click()",
            );
            await fill(
                ".receipt-review-card form input[inputmode=numeric]",
                String(amount),
            );
            await fill(
                ".receipt-review-card form textarea",
                "بررسی صرفاً نرم‌افزاری؛ هیچ وجه واقعی دریافت نشده است",
            );
            await evaluate(
                "document.querySelector('.receipt-review-card form input[type=checkbox]').click()",
            );
            await submit(".receipt-review-card form");
            await until(
                `!document.querySelector('.receipt-review-card form') && document.querySelector('.eyebrow').innerText.includes('${index === 0 ? "صادرشده" : "تسویه‌شده"}')`,
            );
            await networkIdle();
        }
        current = state();
        assert.equal(current.receipts[index].status, "approved");
        const approvedAmount = current.receipts
            .filter((row) => row.status === "approved")
            .reduce(
                (total, row) =>
                    total +
                    (row.transfer_reference.endsWith("A") ? 400000 : 600000),
                0,
            );
        assert.equal(current.paid, approvedAmount);
        assert.equal(current.remaining, 1000000 - approvedAmount);
    }
    await managerLogin();
    await goto("/organization/invoices/" + invoiceId);
    await until(
        "document.querySelector('.eyebrow').innerText.includes('تسویه‌شده')",
    );
    await responsive();
    current = state();
    for (const receipt of current.receipts) {
        lease();
        const result = await evaluate(
            `(async()=>{const r=await fetch('/receipts/${receipt.id}/file');const bytes=await r.arrayBuffer();return{status:r.status,cache:r.headers.get('Cache-Control'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(v=>v.toString(16).padStart(2,'0')).join('')};})()`,
        );
        assert.equal(result.status, 200);
        assert.equal(result.hash, receipt.hash);
        assert(result.cache.includes("no-store"));
    }
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until("innerWidth===390");
    lease();
    await writeFile(
        "/tmp/kardorost-organization66-mobile.png",
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
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(title)})`,
        ),
        false,
    );
    const denied = await evaluate(
        `(async()=>{const statuses=[];for(const url of ['/organization/invoices/${invoiceId}','/receipts/${current.receipts[0].id}/file']){const r=await fetch(url,{headers:{Accept:'application/json'}});await r.text();statuses.push(r.status);}return statuses;})()`,
    );
    assert.deepEqual(denied, [403, 403]);
    assert.equal(state().personal_payment_hash, initial.personal_payment_hash);
    assert.equal(state().invoice.status, "paid");
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            software_only_no_real_funds: true,
            checks: [
                "actual-admin-create-edit-issue-frozen-contract",
                "manager-selects-owned-organization-and-uploads-two-private-receipts",
                "upload-does-not-settle-debt",
                "independent-admin-review-40000-plus60000-toman-equals1000000-rial",
                "private-receipt-byte-hashes-and-no-store",
                "student-organization-and-receipt403-personal-finance-unchanged",
                "resumable-without-reset-320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
