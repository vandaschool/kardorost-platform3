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
    "Use the owned five-user local QA database only.",
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
                    String.raw`echo json_encode(['shares'=>\App\Models\ResumeShare::count(),'published'=>\App\Models\AuditEvent::where('action','resume_share.published')->count(),'revoked'=>\App\Models\AuditEvent::where('action','resume_share.revoked')->count(),'contacts_encrypted'=>\App\Models\ResumeShare::get()->every(fn($s)=>!str_contains($s->getRawOriginal('definition'),'share-source-contact@example.test'))]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
const reuse = process.env.BROWSER_REUSE_RESUME_SHARE_FIXTURE === "1";
const before = inspect();
const progress = reuse
    ? JSON.parse(
          readFileSync(
              "/tmp/kardorost-resume-sharing-browser-progress.json",
              "utf8",
          ),
      )
    : null;
const reuseArchive = reuse && progress.stage === "archive-restore-checked";
assert(
    before.shares === 0 ||
        (reuse &&
            /^\/career\/resumes\/[1-9][0-9]*$/.test(progress.editor) &&
            ((progress.stage === "first-published" &&
                before.shares === 1 &&
                before.published === 1 &&
                before.revoked === 0) ||
                (reuseArchive &&
                    before.shares === 4 &&
                    before.published === 4 &&
                    before.revoked === 3))),
    "Only an explicit owned sharing checkpoint may be resumed.",
);

const literal = "<script>window.resumeShareInjected=true</script>";
const summary = literal + "\nشرح صرفاً آزمایشی برای بررسی اشتراک و حریم.";
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
async function fetchPage(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});return {status:r.status,text:await r.text(),cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),nosniff:r.headers.get('x-content-type-options'),referrer:r.headers.get('referrer-policy')};})()`,
    );
}
async function makeLink(contacts = false, days = 7) {
    await until("!!document.querySelector('[data-publish-resume-share]')");
    if (
        (await evaluate(
            "document.querySelector('[name=include_contacts]').checked",
        )) !== contacts
    )
        await evaluate(
            "document.querySelector('[name=include_contacts]').click()",
        );
    await fill("[name=duration_days]", String(days));
    if (
        !(await evaluate(
            "document.querySelector('[name=share_reviewed]').checked",
        ))
    )
        await evaluate(
            "document.querySelector('[name=share_reviewed]').click()",
        );
    if (
        !(await evaluate(
            "document.querySelector('[name=share_consent]').checked",
        ))
    )
        await evaluate(
            "document.querySelector('[name=share_consent]').click()",
        );
    await submit("[data-publish-resume-share]");
    await until("!!document.querySelector('[data-open-resume-share]')");
    await networkIdle();
    assert.equal(
        await evaluate("document.querySelectorAll('[role=alert]').length"),
        0,
    );
    return evaluate(
        "(()=>{const a=document.querySelector('[data-open-resume-share]');return {id:Number(a.closest('[data-resume-share]').dataset.resumeShare),path:a.getAttribute('href')};})()",
    );
}
async function revoke(id, reason = "") {
    await evaluate(
        `document.querySelector('[data-resume-share="${id}"] details').open=true`,
    );
    if (reason)
        await fill(`[data-resume-share="${id}"] [name=share_reason]`, reason);
    await submit(`[data-resume-share="${id}"] [data-revoke-resume-share]`);
    await until(
        `!document.querySelector('[data-resume-share="${id}"] details')`,
    );
    await networkIdle();
}
async function checkpoint(data) {
    await writeFile(
        "/tmp/kardorost-resume-sharing-browser-progress.json",
        JSON.stringify(data),
        { mode: 0o600 },
    );
}
try {
    let editor, version1, sharing1, first, version2, second, third;
    if (reuse) {
        ({ editor, version1, sharing1, first, version2, second, third } =
            progress);
        await login("student");
        await goto(sharing1);
    } else {
        await login("student");
        await goto("/career/resumes");
        await fill("[name=title]", "اشتراک رزومه صرفاً آزمایشی");
        await submit("[data-resume-create]");
        await until("!!document.querySelector('[data-resume-draft]')");
        editor = await evaluate("location.pathname");
        await fill("[name=resume_name]", "نام ثابت مرورگر اشتراک");
        await fill("[name=headline]", "توسعه‌دهنده با اطلاعات خوداظهاری");
        await fill("[name=email]", "share-source-contact@example.test");
        await fill("[name=resume_phone]", "09120000099");
        await fill("[name=city]", "تهران");
        await fill("[name=summary]", summary);
        for (const skill of ["SQL", "PHP"]) {
            await fill("[name=skill]", skill);
            await evaluate(
                "document.querySelector('[data-add-skill]').click()",
            );
        }
        await evaluate(
            "document.querySelector('[data-add-entry=links]').click()",
        );
        await fill("[name=links_label_0]", "پیوند ثبت‌شده");
        await fill(
            "[name=links_url_0]",
            "https://example.test/share-source-private-link",
        );
        await submit("[data-resume-draft]");
        await until(
            "!document.querySelector('[data-resume-snapshot]').disabled",
        );
        await evaluate(
            "document.querySelector('[data-resume-snapshot]').click()",
        );
        await until("!!document.querySelector('[data-resume-sharing]')");
        version1 = await evaluate("location.pathname");
        await evaluate(
            "document.querySelector('[data-resume-sharing]').click()",
        );
        await until("!!document.querySelector('[data-no-resume-shares]')");
        sharing1 = await evaluate("location.pathname");
        assert.equal(
            await evaluate(
                "document.querySelectorAll('[data-share-contact]').length",
            ),
            0,
        );
        assert.equal(
            await evaluate(
                "document.querySelector('[data-publish-resume-share]').checkValidity()",
            ),
            false,
        );
        await submit("[data-publish-resume-share]");
        await networkIdle();
        assert.equal(inspect().shares, 0);
        await evaluate(
            "document.querySelector('[name=share_reviewed]').click()",
        );
        await evaluate(
            "document.querySelector('[name=share_consent]').click()",
        );
        await evaluate(
            "document.querySelector('[name=include_contacts]').click()",
        );
        await until(
            "!document.querySelector('[name=share_reviewed]').checked && !document.querySelector('[name=share_consent]').checked",
        );
        assert.equal(
            await evaluate(
                "document.querySelectorAll('[data-share-contact]').length",
            ),
            3,
        );
        await evaluate(
            "document.querySelector('[name=include_contacts]').click()",
        );
        first = await makeLink();
        await checkpoint({
            stage: "first-published",
            editor,
            version1,
            sharing1,
            first,
        });
    }
    if (!reuseArchive) {
        await send("Browser.grantPermissions", {
            origin: base,
            permissions: ["clipboardReadWrite", "clipboardSanitizedWrite"],
        });
        await evaluate(
            "document.querySelector('[data-copy-resume-share]').click()",
        );
        await until(
            "document.querySelector('[data-resume-share] [role=status]')?.textContent.includes('لینک کپی شد')",
        );
        assert.equal(
            await evaluate("navigator.clipboard.readText()"),
            base + first.path,
        );
        await responsive("Owner share management");
        await networkIdle();
        await send("Network.clearBrowserCookies");
        await goto(first.path);
        assert.equal(await evaluate("!!window.resumeShareInjected"), false);
        assert(
            await evaluate(
                `document.querySelector('.resume-paper').innerText.includes(${JSON.stringify(literal)})`,
            ),
        );
        for (const value of [
            "share-source-contact@example.test",
            "09120000099",
            "share-source-private-link",
        ])
            assert(
                !(await evaluate(
                    `document.body.innerText.includes(${JSON.stringify(value)})`,
                )),
            );
        const firstPublic = await fetchPage(first.path);
        assert.equal(firstPublic.status, 200);
        assert(
            firstPublic.cache.includes("no-store") &&
                firstPublic.robots.includes("noindex") &&
                firstPublic.nosniff === "nosniff" &&
                firstPublic.referrer === "no-referrer",
        );
        await responsive("Anonymous Persian resume");
        const pdf = await send("Page.printToPDF", {
            printBackground: true,
            preferCSSPageSize: true,
        });
        assert(
            Buffer.from(pdf.data, "base64").subarray(0, 5).toString() ===
                "%PDF-",
        );
        await writeFile(
            "/tmp/kardorost-shared-resume-fixture.pdf",
            Buffer.from(pdf.data, "base64"),
            { mode: 0o600 },
        );
        await login("student");
        await goto(editor);
        await fill("[name=locale]", "en");
        await fill("[name=resume_name]", "New English source name");
        await fill("[name=headline]", "Software Developer");
        await fill(
            "[name=summary]",
            "New saved source text, not an automatic translation.",
        );
        await submit("[data-resume-draft]");
        await until(
            "!document.querySelector('[data-resume-snapshot]').disabled",
        );
        await evaluate(
            "document.querySelector('[data-resume-snapshot]').click()",
        );
        await until("!!document.querySelector('[data-resume-sharing]')");
        version2 = await evaluate("location.pathname");
        assert.equal(
            (await fetchPage(first.path)).text,
            firstPublic.text,
            "Later snapshot does not rewrite the consented public copy.",
        );
        await goto(sharing1);
        await revoke(first.id, "قطع اختیاری صاحب در مرورگر");
        assert.equal((await fetchPage(first.path)).status, 404);
        await checkpoint({
            stage: "first-revoked",
            editor,
            version1,
            version2,
            sharing1,
            first,
        });
        second = await makeLink(true, 30);
        await checkpoint({
            stage: "contact-published",
            editor,
            version1,
            version2,
            sharing1,
            first,
            second,
        });
        await networkIdle();
        await send("Network.clearBrowserCookies");
        await goto(second.path);
        assert(
            await evaluate(
                "document.body.innerText.includes('share-source-contact@example.test') && document.body.innerText.includes('09120000099')",
            ),
        );
        assert.equal(await evaluate("!!window.resumeShareInjected"), false);
        await login("admin");
        await goto(sharing1);
        assert.equal(
            await evaluate(
                "!!document.querySelector('[data-publish-resume-share]')",
            ),
            false,
        );
        await evaluate(
            `document.querySelector('[data-resume-share="${second.id}"] details').open=true`,
        );
        assert.equal(
            await evaluate(
                `document.querySelector('[data-resume-share="${second.id}"] form').checkValidity()`,
            ),
            false,
        );
        await responsive("Full admin share management");
        await revoke(second.id, "توضیح ادمین برای قطع لینک آزمایشی");
        assert.equal((await fetchPage(second.path)).status, 404);
        await login("student");
        await goto(sharing1);
        assert(
            await evaluate(
                "document.body.innerText.includes('توضیح ادمین برای قطع لینک آزمایشی')",
            ),
        );
        third = await makeLink();
        await goto(editor);
        await evaluate(
            "Array.from(document.querySelectorAll('.page-intro button')).find(el=>el.textContent.includes('بایگانی رزومه')).click()",
        );
        await until(
            "Array.from(document.querySelectorAll('.page-intro button')).some(el=>el.textContent.includes('بازیابی رزومه'))",
        );
        assert.equal((await fetchPage(third.path)).status, 404);
        await evaluate(
            "Array.from(document.querySelectorAll('.page-intro button')).find(el=>el.textContent.includes('بازیابی رزومه')).click()",
        );
        await until(
            "Array.from(document.querySelectorAll('.page-intro button')).some(el=>el.textContent.includes('بایگانی رزومه'))",
        );
        assert.equal((await fetchPage(third.path)).status, 404);
        await checkpoint({
            stage: "archive-restore-checked",
            editor,
            version1,
            version2,
            sharing1,
            first,
            second,
            third,
        });
        await networkIdle();
    }
    const resumeId = Number(editor.split("/").at(-1)),
        versionId = Number(version2.split("/").at(-1));
    const expired = reuseArchive
        ? JSON.parse(
              execFileSync(
                  php,
                  [
                      "-r",
                      bootstrap +
                          String.raw`$s=\App\Models\ResumeShare::where('career_resume_id',` +
                          resumeId +
                          String.raw`)->where('resume_version_id',` +
                          versionId +
                          String.raw`)->whereNull('revoked_at')->sole();if(!$s->expires_at->isPast()){throw new RuntimeException('Owned expired fixture missing.');}$token=app(\App\Services\ResumeSharing::class)->metadata($s)['token'];echo json_encode(['id'=>$s->id,'path'=>route('public.resume',$token,false)]);`,
                  ],
                  { cwd: process.cwd(), env, encoding: "utf8" },
              ),
          )
        : JSON.parse(
              execFileSync(
                  php,
                  [
                      "-r",
                      bootstrap +
                          String.raw`if(config('database.connections.sqlite.database')!=='/tmp/kardorost-linkedin-browser.sqlite'){throw new RuntimeException('Only the owned fixture.');}$resume=\App\Models\CareerResume::findOrFail(` +
                          resumeId +
                          String.raw`);if($resume->title!=='اشتراک رزومه صرفاً آزمایشی'){throw new RuntimeException('Fixture changed.');}$version=\App\Models\ResumeVersion::findOrFail(` +
                          versionId +
                          String.raw`);\Carbon\Carbon::setTestNow(now()->subDays(8));$s=app(\App\Services\ResumeSharing::class)->publish($resume->owner,$resume,$version,['revision'=>$resume->revision,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'duration_days'=>7,'include_contacts'=>false,'reviewed'=>true,'consent'=>true]);$token=app(\App\Services\ResumeSharing::class)->metadata($s)['token'];echo json_encode(['id'=>$s->id,'path'=>route('public.resume',$token,false)]);`,
                  ],
                  { cwd: process.cwd(), env, encoding: "utf8" },
              ),
          );
    assert.equal((await fetchPage(expired.path)).status, 404);
    await goto(version2 + "/shares");
    assert(
        await evaluate(
            "document.body.innerText.includes('مهلت لینک تمام شده')",
        ),
    );
    const fifth = await makeLink();
    await checkpoint({
        stage: "english-published",
        editor,
        version1,
        version2,
        sharing1,
        first,
        second,
        third,
        expired,
        fifth,
    });
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await goto(fifth.path);
    assert.equal(await evaluate("document.documentElement.dir"), "ltr");
    assert(
        await evaluate(
            "document.body.innerText.includes('New English source name') && document.body.innerText.includes('Shared resume snapshot')",
        ),
    );
    await responsive("Anonymous English resume");
    for (const role of ["coach", "organization"]) {
        if (role === "organization")
            process.env.BROWSER_ORGANIZATION_PHONE =
                process.env.BROWSER_ORG_ADMIN_PHONE;
        await login(role);
        assert.equal((await fetchPage(sharing1)).status, 403);
    }
    process.env.BROWSER_STUDENT_PHONE = "09121234564";
    await login("student");
    assert.equal((await fetchPage(sharing1)).status, 403);
    assert.deepEqual(inspect(), {
        shares: 5,
        published: 5,
        revoked: 3,
        contacts_encrypted: true,
    });
    assert.deepEqual(exceptions, []);
    await checkpoint({ stage: "passed", editor, version1, version2, sharing1 });
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "real-owner-required-consent-review-and-contact-preview-reset",
                "real-publication-clipboard-and-anonymous-default-contact-omission",
                "private-public-headers-escaped-text-print-pdf-and-frozen-source-after-new-version",
                "real-owner-revoke-contact-optin-and-admin-revoke-with-required-reason",
                "actual-archive-restore-does-not-reactivate-link",
                "consistent-past-clock-expiry-fixture-and-new-active-english-publication",
                "coach-same-org-manager-peer-management-isolation",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
}
