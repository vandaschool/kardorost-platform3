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
    new URL(base).port === "8035" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-course-creation-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned three-user native fixture.",
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

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==3||\App\Models\User::where('name','like','بررسی ساخت دوره صرفاً آزمایشی%')->count()!==3){throw new RuntimeException('Owned three-user fixture');}$courses=\App\Models\Course::orderBy('id')->get();if($courses->count()>2||$courses->contains(fn($c)=>!in_array($c->title,['دوره منتشرشده صرفاً آزمایشی۷۱','پیش‌نویس دوره صرفاً آزمایشی۷۱'],true))){throw new RuntimeException('Preserve unrelated course records');}echo json_encode(['courses'=>$courses->map(fn($c)=>[...$c->only('id','title','slug','price','is_published'),'cohorts'=>$c->cohorts->map->only('id','title','format','capacity')->all()])->all(),'audit'=>\App\Models\AuditEvent::where('action','course.created')->orderBy('id')->get(['actor_id','subject_id'])->toArray(),'enrollments'=>\App\Models\Enrollment::count(),'payments'=>\App\Models\Payment::count()]);`;
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
async function privateStatus(path) {
    return await evaluate(
        `fetch(${JSON.stringify(path)}).then(async r=>{await r.text();return r.status})`,
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
            "Course creation layout " + width,
        );
    }
}
try {
    let s = state();
    await login("admin");
    for (const [title, price, capacity, format, published] of [
        ["دوره منتشرشده صرفاً آزمایشی۷۱", 100000, 5, "hybrid", true],
        ["پیش‌نویس دوره صرفاً آزمایشی۷۱", 0, 3, "recorded", false],
    ]) {
        if (s.courses.some((c) => c.title === title)) continue;
        await goto("/admin/courses");
        lease();
        await evaluate("document.querySelector('.page-intro button').click()");
        await until("!!document.querySelector('form.form-grid')");
        await fill('form.form-grid input[maxlength="150"]', title);
        await fill(
            "form.form-grid textarea",
            "محتوای ساختگی برای آزمون ساخت دوره؛ بدون آموزش یا اعتبار واقعی",
        );
        await fill('form.form-grid input[type=number][min="0"]', String(price));
        await fill(
            'form.form-grid input[type=number][min="1"]',
            String(capacity),
        );
        // The stage and format controls are in separate labels; target the explicit format options.
        await evaluate(
            `(()=>{const select=Array.from(document.querySelectorAll('form.form-grid select')).find(s=>s.querySelector('option[value="recorded"]'));select.value=${JSON.stringify(format)};select.dispatchEvent(new Event('change',{bubbles:true}));})()`,
        );
        if (published) {
            lease();
            await evaluate(
                "document.querySelector('form.form-grid input[type=checkbox]').click()",
            );
        }
        await responsive();
        await submit("form.form-grid");
        await until("!document.querySelector('form.form-grid')");
        s = state();
    }
    assert.equal(s.courses.length, 2);
    const published = s.courses.find((c) => c.is_published);
    const draft = s.courses.find((c) => !c.is_published);
    assert.equal(published.price, 1000000);
    assert.equal(draft.price, 0);
    assert.deepEqual(
        published.cohorts.map((c) => [c.capacity, c.format]),
        [[5, "hybrid"]],
    );
    assert.deepEqual(
        draft.cohorts.map((c) => [c.capacity, c.format]),
        [[3, "recorded"]],
    );
    assert.deepEqual(s.audit, [
        { actor_id: 3, subject_id: published.id },
        { actor_id: 3, subject_id: draft.id },
    ]);
    const before = JSON.stringify(s);
    await login("student");
    await goto("/courses");
    assert(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(published.title)})`,
        ),
    );
    assert(
        !(await evaluate(
            `document.body.innerText.includes(${JSON.stringify(draft.title)})`,
        )),
    );
    assert.equal(await privateStatus("/admin/courses"), 403);
    lease();
    const denied = await evaluate(
        `fetch('/admin/courses',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({title:'نباید ساخته شود',description:'بررسی ممنوعیت ساخت دوره',stage:1,price:100000,capacity:5,format:'hybrid',is_published:true})}).then(async r=>{await r.text();return r.status})`,
    );
    assert.equal(denied, 403);
    assert.equal(JSON.stringify(state()), before);
    await goto("/catalog");
    assert(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(published.title)})`,
        ),
    );
    assert(
        !(await evaluate(
            `document.body.innerText.includes(${JSON.stringify(draft.title)})`,
        )),
    );
    assert.equal(await privateStatus("/catalog/" + draft.slug), 404);
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    lease();
    await writeFile(
        "/tmp/kardorost-course-creation71-mobile.png",
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
    assert.equal(state().enrollments, 0);
    assert.equal(state().payments, 0);
    assert.equal(exceptions.length, 0, exceptions.join("\n"));
    console.log(
        JSON.stringify({
            result: "passed",
            fixture: "three-owned-software-only-users",
            published_rial: 1000000,
            private_draft: true,
            initial_cohorts: 2,
            student_post: 403,
            draft_catalog: 404,
            unchanged_after_denial: true,
            enrollments: 0,
            payments: 0,
            widths: [320, 390, 768, 1440],
            live_services_verified: false,
        }),
    );
} finally {
    await send("Emulation.clearDeviceMetricsOverride");
    await send("Page.close");
    socket.close();
}
