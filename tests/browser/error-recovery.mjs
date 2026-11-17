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

const environmentFile = process.env.CONTAINER_ENV_FILE;
const bootstrap = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();`;
let fixture;
if (environmentFile) {
    assert(
        environmentFile.startsWith("/tmp/") && new URL(base).port === "18089",
        "Only the owned container fixture.",
    );
    const code =
        bootstrap +
        String.raw`if(config('app.debug')||\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4){throw new RuntimeException('Only production-mode synthetic source.');}$share=\App\Models\ResumeShare::sole();$data=app(\App\Services\ResumeSharing::class)->metadata($share);echo json_encode(['public_url'=>route('public.resume',$data['token'],false),'download_url'=>route('career.linkedin.download',['resume'=>$share->career_resume_id,'version'=>$share->resume_version_id],false)]);`;
    const python = String.raw`import importlib.util,sys
from pathlib import Path
s=importlib.util.spec_from_file_location('recovery','resources/operations/recovery.py');r=importlib.util.module_from_spec(s);s.loader.exec_module(r);c=r.Composition(Path(sys.argv[1]));assert c.config['name'].startswith('kardorost-validation-');assert c.settings['APP_URL']==sys.argv[2];assert c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false';print(c.run(['exec','-T','app','php','-r',sys.stdin.read()]))`;
    fixture = JSON.parse(
        execFileSync("python3", ["-c", python, environmentFile, base], {
            input: code,
            encoding: "utf8",
        }),
    );
} else {
    const php = process.env.BROWSER_PHP_BINARY;
    assert(
        php &&
            new URL(base).port === "8031" &&
            process.env.DB_DATABASE ===
                "/tmp/kardorost-linkedin-browser.sqlite",
        "Only the owned native fixture.",
    );
    const env = {
        ...process.env,
        DB_CONNECTION: "sqlite",
        DB_DATABASE: process.env.DB_DATABASE,
        APP_URL: base,
        SMS_DRIVER: "disabled",
        ZIBAL_ENABLED: "false",
    };
    fixture = JSON.parse(
        execFileSync(
            php,
            [
                "-r",
                bootstrap +
                    String.raw`if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','راهنمای لینکدین صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Only the owned software fixture.');}$share=\App\Models\ResumeShare::whereNull('revoked_at')->where('expires_at','>',now())->orderByDesc('id')->firstOrFail();$data=app(\App\Services\ResumeSharing::class)->metadata($share);echo json_encode(['public_url'=>route('public.resume',$data['token'],false)]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
let peer;
async function openPeer() {
    const t = await (
        await fetch(
            "http://127.0.0.1:9222/json/new?" +
                encodeURIComponent("about:blank"),
            { method: "PUT" },
        )
    ).json();
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    let next = 0;
    const waiting = new Map();
    ws.addEventListener("message", (e) => {
        const m = JSON.parse(e.data);
        if (m.id) {
            const p = waiting.get(m.id);
            if (m.error) p.reject(m.error);
            else p.resolve(m.result);
            waiting.delete(m.id);
        }
    });
    const rpc = (method, params = {}) =>
        new Promise((resolve, reject) => {
            const n = ++next;
            waiting.set(n, { resolve, reject });
            ws.send(JSON.stringify({ id: n, method, params }));
        });
    await rpc("Runtime.enable");
    await rpc("Page.enable");
    const evalPeer = async (expression) => {
        const r = await rpc("Runtime.evaluate", {
            expression,
            returnByValue: true,
            awaitPromise: true,
        });
        if (r.exceptionDetails) throw Error("Peer evaluation failed");
        return r.result.value;
    };
    const waitPeer = async (expression) => {
        for (let i = 0; i < 100; i++) {
            if (await evalPeer(expression)) return;
            await new Promise((r) => setTimeout(r, 100));
        }
        throw Error(
            "Peer fixture login timed out: " +
                (await evalPeer(
                    "JSON.stringify({path:location.pathname,ready:document.readyState,title:document.title})",
                )),
        );
    };
    return { t, ws, rpc, evalPeer, waitPeer };
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
let deniedId;
socket.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (
        m.method === "Network.responseReceived" &&
        m.params.response.url === base + "/admin/readiness" &&
        m.params.response.status === 403
    )
        deniedId = m.params.requestId;
});
try {
    await login("admin");
    await networkIdle();
    const version = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).version",
    );
    peer = await openPeer();
    await peer.rpc("Network.clearBrowserCookies");
    await peer.rpc("Page.navigate", { url: base + "/login" });
    await peer.waitPeer("!!document.querySelector('[name=password]')");
    for (const [name, value] of [
        ["phone", process.env.BROWSER_STUDENT_PHONE],
        ["password", password],
    ])
        await peer.evalPeer(
            `(()=>{const el=document.querySelector('[name=${name}]');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
        );
    await peer.evalPeer("document.querySelector('form').requestSubmit()");
    await peer.waitPeer(
        "location.pathname==='/student' && !!document.querySelector('main')",
    );
    await evaluate(
        "(()=>{const link=document.querySelector('a[href=\"/admin/readiness\"]');link.closest('details').open=true;link.click();})()",
    );
    await until("!!document.querySelector('[data-problem-page]')");
    await networkIdle();
    assert(
        await evaluate(
            "document.body.innerText.includes('این بخش در دسترس شما نیست')",
        ),
    );
    assert.equal(
        await evaluate("document.querySelectorAll('iframe').length"),
        0,
    );
    assert(deniedId);
    const response = await send("Network.getResponseBody", {
        requestId: deniedId,
    });
    const page = JSON.parse(response.body);
    assert.equal(page.component, "ErrorPage");
    assert.equal(page.props.status, 403);
    for (const key of [
        "auth",
        "unreadNotifications",
        "organizationInvitationCount",
        "flash",
    ])
        assert(!(key in page.props));
    await responsive("Inertia permission error");
    await evaluate(
        "document.querySelector('[data-problem-page] a[href=\"/panel\"]').click()",
    );
    await until(
        "location.pathname==='/student' && !!document.querySelector('[data-panel-navigation]')",
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-panel-navigation]').children.length",
        ),
        5,
    );
    if (environmentFile) {
        const isolated = await evaluate(
            `(async()=>{for(let i=0;i<10;i++){const r=await fetch(${JSON.stringify(fixture.download_url)},{headers:{Accept:'text/plain'}});await r.text();if(r.status!==200)throw Error('Unexpected download budget');}const limited=await fetch(${JSON.stringify(fixture.download_url)},{headers:{Accept:'application/json'}});await limited.text();const other=await fetch('/career/resumes',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({title:[]})});await other.text();const retained=await fetch(${JSON.stringify(fixture.download_url)},{headers:{Accept:'application/json'}});await retained.text();return {limited:limited.status,other:other.status,retained:retained.status};})()`,
        );
        assert.deepEqual(isolated, { limited: 429, other: 422, retained: 429 });
    }
    await goto("/missing-PRIVATE-ERROR-URL-MARKER");
    assert(
        await evaluate(
            "document.body.innerText.includes('این صفحه در دسترس نیست')",
        ),
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('PRIVATE-ERROR-URL-MARKER')",
        )),
    );
    assert.equal(
        await evaluate("document.querySelectorAll('script').length"),
        0,
    );
    await responsive("Standalone missing page");
    await send("Emulation.setEmulatedMedia", {
        features: [{ name: "prefers-color-scheme", value: "dark" }],
    });
    assert.equal(
        await evaluate("getComputedStyle(document.body).color"),
        "rgb(238, 241, 247)",
    );
    await responsive("Standalone dark missing page");
    await send("Emulation.setEmulatedMedia", { features: [] });
    const picture = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-error-page-fixture.png",
        Buffer.from(picture.data, "base64"),
        { mode: 0o600 },
    );
    await send("Network.clearBrowserCookies");
    await goto("/login");
    await send("Page.navigate", {
        url: base.replace("127.0.0.1", "localhost") + "/login",
    });
    await until(
        "location.hostname==='localhost' && document.readyState==='complete' && !!document.querySelector('input[name=password]')",
    );
    await networkIdle();
    await evaluate(
        `(()=>{const f=document.createElement('form');f.method='POST';f.action=${JSON.stringify(base + "/login")};document.body.append(f);f.submit();})()`,
    );
    await until(
        "location.hostname==='127.0.0.1' && document.readyState==='complete' && document.body.innerText.includes('نشست این فرم نیاز به تازه‌سازی دارد')",
    );
    assert.equal(
        await evaluate("document.querySelectorAll('script,form').length"),
        0,
    );
    await responsive("Actual invalid cross-origin form 419");
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await goto("/login");
    const limited = await evaluate(
        `(async()=>{let last;for(let i=0;i<61;i++){const r=await fetch(${JSON.stringify(fixture.public_url)},{headers:{Accept:'text/html'}});await r.text();last={status:r.status,retry:Number(r.headers.get('retry-after')),cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),referrer:r.headers.get('referrer-policy')};if(r.status===429)break;if(r.status!==200)throw Error('Unexpected isolated share status');}return last})()`,
    );
    assert.equal(limited.status, 429);
    assert(limited.retry > 0 && limited.retry <= 60);
    assert(
        limited.cache.includes("no-store") &&
            limited.robots.includes("noindex") &&
            limited.referrer === "no-referrer",
    );
    await goto(fixture.public_url);
    assert(
        await evaluate(
            "document.body.innerText.includes('کمی به این بخش فرصت بدهید') && document.body.innerText.includes('زمان انتظار اعلام‌شده')",
        ),
    );
    assert.equal(await evaluate("document.querySelectorAll('form').length"), 0);
    await responsive("Real rate limit page");
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "actual-admin-page-with-student-session-stale-link-inertia-403-without-modal-or-private-props",
                "panel-link-recovers-actual-student-five-section-navigation",
                "standalone-sessionless-404-no-url-details-or-assets-light-dark",
                "actual-cross-origin-tokenless-form-419-standalone-without-replay",
                "real-public-route-60-request-limit-429-retains-retry-private-headers-and-no-post-form",
                "320-390-768-1440",
                ...(environmentFile
                    ? [
                          "production-nginx-php-fpm-pg-runtime",
                          "implicit-download-limit-retained-while-independent-create-validation422-no-business-record-write",
                      ]
                    : []),
            ],
        }),
    );
} finally {
    if (peer) {
        peer.ws.close();
        await fetch("http://127.0.0.1:9222/json/close/" + peer.t.id);
    }
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
