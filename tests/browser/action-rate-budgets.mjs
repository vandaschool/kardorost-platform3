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

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8031" &&
        process.env.DB_DATABASE === "/tmp/kardorost-linkedin-browser.sqlite",
    "Use only the owned isolated five-user browser database.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    APP_URL: base,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const bootstrap = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();`;
function inspect() {
    return JSON.parse(
        execFileSync(
            php,
            [
                "-r",
                bootstrap +
                    String.raw`$u=\App\Models\User::where('phone','989121234561')->firstOrFail();if(\App\Models\User::count()!==5||!str_starts_with($u->name,'راهنمای لینکدین صرفاً آزمایشی')||!$u->hasRole('coach')){throw new RuntimeException('Use the owned software-only coach fixture.');}echo json_encode(['resumes'=>\App\Models\CareerResume::where('user_id',$u->id)->count(),'credentials'=>\App\Models\CoachCredential::where('user_id',$u->id)->count()]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
assert.deepEqual(
    inspect(),
    { resumes: 0, credentials: 0 },
    "Inspect the owned partial fixture before rerunning mutations.",
);
async function blockedResume() {
    return evaluate(
        "(async()=>{const r=await fetch('/career/resumes',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({title:'Software rate over-budget probe'})});await r.text();return {status:r.status,retry:Number(r.headers.get('retry-after'))}})()",
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
            `Cross-action workflow overflow ${width}`,
        );
    }
}
try {
    await login("coach");
    for (let index = 1; index <= 10; index++) {
        await goto("/career/resumes");
        await fill(
            "[name=title]",
            "آزمون صرفاً نرم‌افزاری سهمیه مستقل " + index,
        );
        await submit("[data-resume-create]");
        await until("!!document.querySelector('[data-resume-draft]')");
    }
    const first = await blockedResume();
    assert.equal(first.status, 429);
    assert(first.retry > 0 && first.retry <= 60);
    assert.equal(inspect().resumes, 10);
    await goto("/coach-credentials");
    await fill(
        "[name=credential_title]",
        "مدرک صرفاً آزمایشی پس از پرشدن سهمیه بخش دیگر",
    );
    await fill(
        "[name=credential_issuer]",
        "نمونه نرم‌افزاری؛ بدون اعتبار مدرک",
    );
    const doc = await send("DOM.getDocument");
    const input = await send("DOM.querySelector", {
        nodeId: doc.root.nodeId,
        selector: "[name=credential_file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: input.nodeId,
        files: ["/tmp/kardorost-action-throttle-fixture.pdf"],
    });
    await evaluate(
        "document.querySelector('[name=credential_consent]').click()",
    );
    await submit("[data-testid=credential-upload]");
    await until(
        "!!document.querySelector('[data-testid=credential-selected]')",
    );
    assert.equal(
        await evaluate("document.querySelectorAll('[role=alert]').length"),
        0,
    );
    assert.deepEqual(inspect(), { resumes: 10, credentials: 1 });
    await responsive();
    assert.equal(
        (await blockedResume()).status,
        429,
        "The first action remains exhausted after a different action succeeds.",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "ten-actual-coach-resume-create-forms-and-eleventh-real-429",
                "actual-private-pdf-upload-succeeds-with-independent-unprefixed-action-budget",
                "original-create-action-still-limited-after-upload",
                "no-limit-disabled-no-cache-flush-and-320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
