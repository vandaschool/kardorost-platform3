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
const title = "پکیج بررسی دسترسی جاری صرفاً آزمایشی";
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Only the owned synthetic fixture.');}$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$products=\App\Models\ConsultationProduct::all();$orders=\App\Models\PackagePurchase::all();if($products->count()>1||$orders->count()>1||$products->contains(fn($p)=>$p->coach_id!==$coach->id||!str_starts_with($p->title,'پکیج بررسی دسترسی جاری صرفاً آزمایشی')||$p->manual_payment_enabled)||$orders->contains(fn($p)=>$p->student_id!==$student->id||$p->coach_id!==$coach->id||$p->consultation_product_id!==$products->sole()->id)){throw new RuntimeException('Preserve other products and purchases.');}$order=$orders->first();echo json_encode(['coach_id'=>$coach->id,'product'=>$products->first()?->only('id','title','price','revision','published'),'order'=>$order?[...$order->only('id','amount','status'),'title'=>$order->definition['title'],'reusable'=>$order->expires_at->isFuture()&&$order->status==='pending_payment','snapshot_hash'=>hash('sha256',json_encode($order->getAttributes(),JSON_THROW_ON_ERROR))]:null,'payments'=>\App\Models\Payment::count()]);`;
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
async function buy(id) {
    const selector = `[data-package-offer="${id}"]`;
    await evaluate(
        `document.querySelector(${JSON.stringify(selector + " input[type=checkbox]")}).click()`,
    );
    await submit(selector + " form");
    await until(
        "location.pathname.startsWith('/package-purchases/') && !!document.querySelector('[data-package-purchase]')",
    );
    await networkIdle();
}
try {
    const initial = state();
    await login("admin");
    await goto("/admin/consultation-packages");
    if (!initial.product) {
        await evaluate("document.querySelector('main > details').open=true");
        for (const [name, value] of [
            ["product_title", title],
            [
                "product_description",
                "این پکیج فقط برای بررسی نرم‌افزاری دسترسی و حفظ قرارداد سفارش است.",
            ],
            ["product_coach", String(initial.coach_id)],
            ["product_price", "100000"],
            ["product_sessions", "2"],
            ["product_minutes", "60"],
            ["product_days", "30"],
        ])
            await fill(`main > details [name=${name}]`, value);
        await evaluate(
            "document.querySelector('main > details [name=product_published]').click()",
        );
        await evaluate(
            "document.querySelector('main > details [name=product_reviewed]').click()",
        );
        await submit("main > details form");
        await until(
            "!!document.querySelector('.consultation-grid .consultation-card') && document.querySelector('main > details [name=product_title]').value===''",
        );
        await networkIdle();
    }
    await responsive("Admin package product configuration");
    let current = state();
    await login("student");
    await goto("/consultation-packages");
    if (!current.order) {
        assert.equal(
            current.product.price,
            1000000,
            "Preserve the owned original product terms",
        );
        await buy(current.product.id);
        current = state();
    }
    const original = current.order;
    assert.equal(original.amount, 1000000);
    assert.equal(original.title, title);
    await goto("/package-purchases/" + original.id);
    await until(
        `document.querySelector('[data-package-purchase] h1').innerText===${JSON.stringify(title)}`,
    );
    await responsive("Owner frozen package purchase");
    await login("admin");
    await goto("/admin/consultation-packages");
    if (state().product.price !== 2000000) {
        await evaluate(
            "document.querySelector('.consultation-grid .consultation-card details').open=true",
        );
        await fill(
            ".consultation-grid [name=product_title]",
            title + " — ویرایش بعدی",
        );
        await fill(".consultation-grid [name=product_price]", "200000");
        await evaluate(
            "document.querySelector('.consultation-grid [name=product_reviewed]').click()",
        );
        await submit(".consultation-grid .consultation-card form");
        await until(
            "document.querySelector('.consultation-grid h2').innerText.includes('ویرایش بعدی')",
        );
        await networkIdle();
    }
    assert.equal(state().product.price, 2000000);
    await login("student");
    await goto("/consultation-packages");
    let reused = false;
    if (state().order.reusable) {
        await buy(current.product.id);
        reused = true;
    } else {
        await goto("/package-purchases/" + original.id);
    }
    assert.equal(state().order.snapshot_hash, original.snapshot_hash);
    assert.equal(state().order.id, original.id);
    assert.equal(state().payments, initial.payments);
    assert(
        await evaluate(
            `document.querySelector('[data-package-purchase] h1').innerText===${JSON.stringify(title)}`,
        ),
    );
    const deniedEdit = await evaluate(
        `(async()=>{const r=await fetch('/admin/consultation-packages/${current.product.id}',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json'},body:'{}'});await r.text();return r.status;})()`,
    );
    assert.equal(deniedEdit, 403);
    await login("student", "09121234564");
    await goto("/consultation-packages");
    const deniedRead = await evaluate(
        `(async()=>{const r=await fetch('/package-purchases/${original.id}',{headers:{Accept:'application/json'}});await r.text();return r.status;})()`,
    );
    assert.equal(deniedRead, 403);
    assert.equal(state().order.snapshot_hash, original.snapshot_hash);
    assert.equal(state().order.id, original.id);
    assert.equal(state().payments, initial.payments);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            pending_order_reused: reused,
            checks: [
                "actual-admin-package-create-publish-and-next-edition-price-edit",
                "owner-frozen-original-terms-price-and-identity-preserved-after-product-change",
                "no-payment-or-external-provider-request",
                "student-cannot-edit-product-peer-cannot-read-owner-order",
                "resumable-owned-fixture-keeps-expired-order-without-reset",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
