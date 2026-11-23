import { execFileSync } from "node:child_process";
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
let loginRetryAfter = 0;
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.responseReceived" &&
        m.params.response.status === 429 &&
        new URL(m.params.response.url).pathname === "/login"
    ) {
        const entry = Object.entries(m.params.response.headers).find(
            ([name]) => name.toLowerCase() === "retry-after",
        );
        const seconds = Number(entry?.[1]);
        if (Number.isInteger(seconds) && seconds > 0 && seconds <= 60)
            loginRetryAfter = seconds;
    }

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
function lease() {
    assert(process.env.KARDOROST_LEASE_OWNER);
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
    for (let attempt = 0; attempt < 3; attempt++) {
        await networkIdle();
        await send("Page.navigate", { url: "about:blank" });
        await until("location.href==='about:blank'");
        await send("Network.clearBrowserCookies");
        await send("Page.navigate", { url: base + "/login" });
        await until("!!document.querySelector('input[name=password]')");
        await networkIdle();
        await fill("[name=phone]", phone);
        await fill("[name=password]", password);
        loginRetryAfter = 0;
        await submit("form");
        for (let index = 0; index < 100; index++) {
            if (await evaluate(`location.pathname==='/${role}'`)) return;
            if (loginRetryAfter > 0) break;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        assert(
            loginRetryAfter > 0,
            "Fixture login must either finish or return an actual bounded Retry-After.",
        );
        await networkIdle();
        console.log(
            "Honoring actual login Retry-After: " +
                loginRetryAfter +
                " seconds.",
        );
        const deadline = Date.now() + loginRetryAfter * 1000 + 250;
        while (Date.now() < deadline)
            await new Promise((resolve) =>
                setTimeout(resolve, Math.min(20000, deadline - Date.now())),
            );
    }
    throw new Error(
        "Fixture login remained rate limited after three bounded attempts.",
    );
}

const environmentFile = process.env.CONTAINER_ENV_FILE;
assert(
    environmentFile?.startsWith("/tmp/") && new URL(base).port === "18089",
    "Use only the private four-user Compose fixture.",
);
const php = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4){throw new RuntimeException('Only the owned synthetic source.');}$credential=\App\Models\CoachCredential::sole();$metadata=app(\App\Services\CoachCredentials::class)->metadata($credential);$share=\App\Models\ResumeShare::sole();$data=app(\App\Services\ResumeSharing::class)->metadata($share);echo json_encode(['credential_id'=>$credential->id,'file_hash'=>$metadata['sha256'],'share_manage'=>route('career.shares',['resume'=>$share->career_resume_id,'version'=>$share->resume_version_id],false),'public_url'=>route('public.resume',$data['token'],false),'expires_timestamp'=>$share->expires_at->timestamp]);`;
const python = String.raw`import importlib.util,json,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('recovery','resources/operations/recovery.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r);c=r.Composition(Path(sys.argv[1]));assert c.config['name'].startswith('kardorost-validation-');assert c.settings['APP_URL']==sys.argv[2];print(c.run(['exec','-T','app','php','-r',sys.stdin.read()]))`;
const fixture = JSON.parse(
    execFileSync("python3", ["-c", python, environmentFile, base], {
        input: php,
        encoding: "utf8",
    }),
);
async function download() {
    return evaluate(
        "(async()=>{const r=await fetch(document.querySelector('[data-testid=credential-file]').href);const bytes=new Uint8Array(await r.arrayBuffer());const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(x=>x.toString(16).padStart(2,'0')).join('');return {status:r.status,hash,cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),disposition:r.headers.get('content-disposition')};})()",
    );
}
async function status(path) {
    return evaluate(
        `fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}}).then(async r=>{await r.text();return r.status})`,
    );
}
let quotaWaits = 0;
async function awaitPublicQuota() {
    for (let attempt = 0; attempt < 3; attempt++) {
        const reply = await evaluate(
            `(async()=>{const r=await fetch(${JSON.stringify(fixture.public_url)},{headers:{Accept:'text/html'}});await r.text();return {status:r.status,retry:Number(r.headers.get('retry-after'))};})()`,
        );
        if (reply.status === 200 || reply.status === 404) {
            assert.equal(
                reply.status,
                Date.now() >= fixture.expires_timestamp * 1000 ? 404 : 200,
                "Current public access must follow the original expiry without renewal",
            );
            return reply.status;
        }
        assert.equal(reply.status, 429, "Unexpected public fixture status");
        assert(
            reply.retry > 0 && reply.retry <= 60,
            "Honor only the actual bounded quota retry interval",
        );
        assert(attempt < 2, "Public fixture quota did not reopen");
        quotaWaits++;
        await new Promise((resolve) =>
            setTimeout(resolve, Math.min(reply.retry, 30) * 1000),
        );
    }
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
            `${label} overflow ${width}`,
        );
    }
}
try {
    await login("coach");
    await goto("/coach-credentials/" + fixture.credential_id);
    await until("!!document.querySelector('[data-testid=credential-file]')");
    const own = await download();
    assert.equal(own.status, 200);
    assert.equal(own.hash, fixture.file_hash);
    assert(
        own.cache.includes("no-store") &&
            own.robots.includes("noindex") &&
            own.disposition.includes("attachment"),
    );
    await responsive("Container private coach credential");
    await login("student");
    assert.equal(
        await status("/coach-credentials/" + fixture.credential_id),
        403,
    );
    assert.equal(
        await status("/coach-credentials/" + fixture.credential_id + "/file"),
        403,
    );
    await goto(fixture.share_manage);
    await until("!!document.querySelector('[data-resume-share]')");
    const expiredAtOwnerView = Date.now() >= fixture.expires_timestamp * 1000;
    if (expiredAtOwnerView) {
        assert.equal(
            await evaluate(
                "!!document.querySelector('[data-open-resume-share]')",
            ),
            false,
        );
        assert(
            await evaluate(
                "document.body.innerText.includes('مهلت لینک تمام شده')",
            ),
        );
    } else {
        assert.equal(
            await evaluate(
                "document.querySelector('[data-open-resume-share]').getAttribute('href')",
            ),
            fixture.public_url,
        );
    }
    await responsive("Container private resume sharing");
    await networkIdle();
    await send("Network.clearBrowserCookies");
    const publicStatus = await awaitPublicQuota();
    await goto(fixture.public_url);
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('نام نسخه آزمایشی کانتینر')",
        ),
        publicStatus === 200,
    );
    for (const privateValue of [
        "09120000099",
        "container-private-source@example.test",
        "مدرک صرفاً آزمایشی کانتینر",
    ])
        assert(
            !(await evaluate(
                `document.body.innerText.includes(${JSON.stringify(privateValue)})`,
            )),
        );
    const headers = await evaluate(
        "fetch(location.pathname).then(async r=>{await r.text();return {status:r.status,cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),referrer:r.headers.get('referrer-policy')}})",
    );
    assert.equal(headers.status, publicStatus);
    assert(
        headers.cache.includes("no-store") &&
            headers.robots.includes("noindex") &&
            headers.referrer === "no-referrer",
    );
    const fallbackPolicy = await evaluate(
        "fetch('/').then(async r=>{await r.text();return r.headers.get('referrer-policy')})",
    );
    assert.equal(fallbackPolicy, "strict-origin-when-cross-origin");
    await responsive("Container anonymous fixed resume");
    await login("admin");
    await goto("/coach-credentials/" + fixture.credential_id);
    await until("!!document.querySelector('[data-testid=credential-file]')");
    assert.equal((await download()).hash, fixture.file_hash);
    await goto(fixture.share_manage);
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-publish-resume-share]')",
        ),
        false,
    );
    await responsive("Container full admin private sharing");
    await login("organization");
    assert.equal(
        await status("/coach-credentials/" + fixture.credential_id + "/file"),
        403,
    );
    assert.equal(await status(fixture.share_manage), 403);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            quota_waits: quotaWaits,
            public_share_status: publicStatus,
            expired_original_share_no_public_copy: publicStatus === 404,
            checks: [
                "non-development-container-owner-and-admin-original-credential-file-hash-download",
                "student-organization-role-private-credential-isolation",
                publicStatus === 200
                    ? "restored-owner-visible-consented-sharing-and-anonymous-fixed-public-copy"
                    : "original-expired-link404-owner-history-and-frozen-private-consent-preserved-without-reconsent",
                "default-contact-omission-private-public-headers-and-admin-owner-consent-boundary",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
