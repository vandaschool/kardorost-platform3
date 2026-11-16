import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
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
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main')`,
    );
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
        new URL(base).port === "8030" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-coach-credentials-browser.sqlite",
    "Use the isolated coach credential browser database and matching PHP upload limits only.",
);
const fixture = JSON.parse(
    readFileSync("/tmp/kardorost-coach-credentials-fixture.json", "utf8"),
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
const count = Number(
    execFileSync(
        php,
        [
            "-r",
            bootstrap + String.raw`echo \App\Models\CoachCredential::count();`,
        ],
        { cwd: process.cwd(), env, encoding: "utf8" },
    ),
);
assert.equal(count, 0, "Use a fresh owned fixture before rerunning mutations.");
const expectedHash = createHash("sha256")
    .update(readFileSync("/tmp/kardorost-coach-credential-fixture.pdf"))
    .digest("hex");
function inspect(id) {
    return JSON.parse(
        execFileSync(
            php,
            [
                "-r",
                bootstrap +
                    String.raw`$c=\App\Models\CoachCredential::findOrFail(` +
                    id +
                    String.raw`);echo json_encode(['status'=>$c->status,'revision'=>$c->revision,'events'=>$c->events()->count(),'file_exists'=>\Illuminate\Support\Facades\Storage::disk('learning')->exists($c->file_path),'metadata_encrypted'=>!str_contains($c->getRawOriginal('definition'),$c->definition['title']),'path_encrypted'=>!str_contains($c->getRawOriginal('file_path'),'coach-credentials/'),'notifications'=>\Illuminate\Support\Facades\DB::table('notifications')->where('type',\App\Notifications\CoachCredentialChanged::class)->count(),'public_profiles'=>\App\Models\CoachProfile::count()]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
async function choose(path) {
    const doc = await send("DOM.getDocument");
    const input = await send("DOM.querySelector", {
        nodeId: doc.root.nodeId,
        selector: "[name=credential_file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: input.nodeId,
        files: [path],
    });
    await until(
        "document.querySelector('[name=credential_file]').files.length===1",
    );
}
async function fileDownload() {
    return evaluate(
        "(async()=>{const r=await fetch(document.querySelector('[data-testid=credential-file]').href);const b=new Uint8Array(await r.arrayBuffer());const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',b))).map(v=>v.toString(16).padStart(2,'0')).join('');return {status:r.status,size:b.length,hash,type:r.headers.get('content-type'),cache:r.headers.get('cache-control'),disposition:r.headers.get('content-disposition'),robots:r.headers.get('x-robots-tag')};})()",
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
            `${label} overflow ${width}`,
        );
    }
}
async function forbidden(path) {
    return evaluate(
        `fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}}).then(async r=>({status:r.status,private:(await r.text()).includes('credentialInjected')}))`,
    );
}
const literal = "<script>window.credentialInjected=true</script>";
try {
    await login("coach");
    await goto("/coach-credentials");
    await until("!!document.querySelector('[data-testid=credential-upload]')");
    await fill("[name=credential_title]", literal + " مدرک صرفاً آزمایشی");
    await fill(
        "[name=credential_issuer]",
        "مؤسسه آزمایشی نرم‌افزار، بدون اعتبار مدرک واقعی",
    );
    await fill(
        "[name=credential_note]",
        "یادداشت خصوصی مربی برای بررسی فرایند",
    );
    await evaluate(
        "document.querySelector('[name=credential_consent]').click()",
    );
    await choose("/tmp/kardorost-coach-credential-oversize.pdf");
    await submit("[data-testid=credential-upload]");
    await until(
        "!!document.querySelector('[data-testid=credential-upload] .field-error')",
    );
    assert.equal(
        Number(
            execFileSync(
                php,
                [
                    "-r",
                    bootstrap +
                        String.raw`echo \App\Models\CoachCredential::count();`,
                ],
                { cwd: process.cwd(), env, encoding: "utf8" },
            ),
        ),
        0,
    );
    await choose("/tmp/kardorost-coach-credential-fixture.pdf");
    await submit("[data-testid=credential-upload]");
    await until(
        "location.pathname.startsWith('/coach-credentials/') && !!document.querySelector('[data-testid=credential-selected]')",
    );
    const id = Number((await evaluate("location.pathname")).split("/").at(-1));
    const path = "/coach-credentials/" + id;
    assert(
        (
            await evaluate(
                "document.querySelector('[data-testid=credential-selected]').innerText",
            )
        ).includes(literal),
    );
    assert.equal(await evaluate("window.credentialInjected||false"), false);
    const submitted = inspect(id);
    assert.equal(submitted.status, "submitted");
    assert.equal(submitted.events, 1);
    assert.equal(submitted.metadata_encrypted, true);
    assert.equal(submitted.path_encrypted, true);
    assert.equal(submitted.public_profiles, 0);
    const ownFile = await fileDownload();
    assert.equal(ownFile.status, 200);
    assert.equal(ownFile.size, 3 * 1024 * 1024);
    assert.equal(ownFile.hash, expectedHash);
    assert.equal(ownFile.type, "application/octet-stream");
    assert(ownFile.cache.includes("no-store"));
    assert(ownFile.disposition.includes("attachment"));
    assert.equal(ownFile.robots, "noindex, nofollow");
    await responsive("Coach private credential");
    await login("admin");
    await goto("/admin/coach-credentials");
    await until("!!document.querySelector('[data-testid=credential-filters]')");
    await fill("[name=credential_phone_filter]", "09121234561");
    await fill("[name=credential_status_filter]", "submitted");
    await submit("[data-testid=credential-filters]");
    await until(
        "location.search.includes('phone=') && document.querySelectorAll('[data-credential-id]').length===1",
    );
    await evaluate(
        `document.querySelector('[data-credential-id="${id}"] a').click()`,
    );
    await until("!!document.querySelector('[data-testid=credential-review]')");
    assert.equal((await fileDownload()).hash, expectedHash);
    await fill(
        "[name=credential_review_reason]",
        "توضیح خصوصی بررسی داخلی برای صاحب پرونده",
    );
    await evaluate(
        "document.querySelector('[name=credential_reviewed]').click()",
    );
    await submit("[data-testid=credential-review]");
    await until(
        "document.querySelector('[data-testid=credential-status]').innerText.includes('پذیرفته')",
    );
    assert.equal(inspect(id).events, 2);
    assert.equal(inspect(id).notifications, 1);
    assert.equal(inspect(id).public_profiles, 0);
    await responsive("Admin credential review");
    await goto("/admin/users?user_id=" + fixture.users[1]);
    await until(
        `!!document.querySelector('[data-user-id="${fixture.users[1]}"] button')`,
    );
    await evaluate(
        `document.querySelector('[data-user-id="${fixture.users[1]}"] button').click()`,
    );
    await until("!!document.querySelector('[data-testid=user-access]')");
    await evaluate("document.querySelector('[name=access_student]').click()");
    await evaluate("document.querySelector('[name=access_coach]').click()");
    await submit("[data-testid=user-access]");
    await until(
        "!document.querySelector('[data-testid=user-access]') && document.body.innerText.includes('دسترسی و وضعیت کاربر به‌روز شد.')",
    );
    process.env.BROWSER_STUDENT_PHONE = "09121234561";
    await login("student");
    await goto("/account");
    await until(
        "!!document.querySelector('[data-testid=account-coach-credentials]')",
    );
    await evaluate(
        "document.querySelector('[data-testid=account-coach-credentials]').click()",
    );
    await until(
        "location.pathname==='/coach-credentials' && !!document.querySelector('[data-credential-id]')",
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-testid=credential-upload]')",
        ),
        false,
    );
    await goto("/notifications");
    assert(
        (await evaluate("document.body.innerText")).includes(
            "بررسی داخلی پرونده خصوصی شما ثبت شد",
        ),
    );
    assert(!(await evaluate("document.body.innerText")).includes(literal));
    await evaluate(`document.querySelector('a[href="${path}"]').click()`);
    await until(
        "!!document.querySelector('[data-testid=credential-selected]')",
    );
    assert.equal((await fileDownload()).hash, expectedHash);
    assert(
        (await evaluate("document.body.innerText")).includes(
            "توضیح خصوصی بررسی داخلی برای صاحب پرونده",
        ),
    );
    await evaluate(
        "document.querySelector('[data-testid=credential-withdraw-section]').open=true",
    );
    await fill(
        "[name=credential_withdraw_reason]",
        "کنارگذاشتن آزمایشی با حفظ اصل فایل و سابقه",
    );
    await submit("[data-testid=credential-withdraw]");
    await until(
        "document.querySelector('[data-testid=credential-status]').innerText.includes('کنارگذاشته')",
    );
    assert.deepEqual(
        {
            status: inspect(id).status,
            revision: inspect(id).revision,
            events: inspect(id).events,
            exists: inspect(id).file_exists,
            notifications: inspect(id).notifications,
        },
        {
            status: "withdrawn",
            revision: 3,
            events: 3,
            exists: true,
            notifications: 1,
        },
    );
    assert.equal((await fileDownload()).hash, expectedHash);
    process.env.BROWSER_COACH_PHONE = "09121234564";
    await login("coach");
    for (const targetPath of [path, path + "/file"]) {
        const r = await forbidden(targetPath);
        assert.equal(r.status, 403);
        assert.equal(r.private, false);
    }
    process.env.BROWSER_STUDENT_PHONE = "09121234560";
    await login("student");
    assert.equal((await forbidden(path + "/file")).status, 403);
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    assert.equal((await forbidden(path)).status, 403);
    assert.equal((await forbidden(path + "/file")).status, 403);
    await login("admin");
    await goto(path);
    await until(
        "!!document.querySelector('[data-testid=credential-selected]')",
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-testid=credential-review]')",
        ),
        false,
    );
    assert.equal((await fileDownload()).hash, expectedHash);
    assert.equal(await evaluate("window.credentialInjected||false"), false);
    const publicPage = await evaluate(
        "fetch('/mentors').then(async r=>({status:r.status,private:(await r.text()).includes('credentialInjected')}))",
    );
    assert.equal(publicPage.status, 200);
    assert.equal(publicPage.private, false);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "real-oversize-pdf-rejected-and-three-mebibyte-private-pdf-uploaded",
                "owner-admin-original-sha256-downloads-private-headers-and-encrypted-source-metadata",
                "actual-admin-filter-internal-review-encrypted-history-generic-owner-notification",
                "actual-coach-role-removal-preserves-personal-history-through-account-link",
                "actual-owner-withdrawal-preserves-file-and-three-events-no-reactivation-ui",
                "other-coach-student-and-member-organization-manager-isolation",
                "no-public-profile-public-document-or-script-execution",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
