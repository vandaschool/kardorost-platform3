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
    new URL(base).port === "8034" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-organization-workflow-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned six-user native fixture.",
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

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==6||\App\Models\User::where('name','like','بررسی سازمان صرفاً آزمایشی%')->count()!==6){throw new RuntimeException('Owned six-user software-only fixture');}$course=\App\Models\Course::first();$org=\App\Models\Organization::first();if(\App\Models\Course::count()>1||($course&&($course->title!=='دوره بررسی سازمان صرفاً آزمایشی'||$course->price!==1000000))||\App\Models\Organization::count()>1||($org&&$org->name!=='سازمان بررسی دسترسی صرفاً آزمایشی')){throw new RuntimeException('Preserve unrelated data');}$cohort=\App\Models\Cohort::first();$license=\App\Models\OrganizationLicense::first();$members=$org?$org->members()->get()->map(fn($u)=>['id'=>$u->id,'kind'=>$u->pivot->kind])->all():[];$imports=\App\Models\OrganizationInvitationImport::orderBy('id')->get();echo json_encode(['course'=>$course?->only('id','coach_id'),'cohort_id'=>$cohort?->id,'organization_id'=>$org?->id,'members'=>$members,'license'=>$license?->only('id','revision','seat_limit'),'invitations'=>\App\Models\OrganizationInvitation::orderBy('id')->get(['id','status','phone'])->toArray(),'committed_imports'=>$imports->whereNotNull('committed_at')->count(),'imports_hash'=>hash('sha256',json_encode($imports->map->getAttributes()->all(),JSON_THROW_ON_ERROR)),'enrollments'=>\App\Models\Enrollment::orderBy('id')->get(['id','user_id','status','amount','payment_plan','organization_license_id'])->toArray(),'seats'=>\App\Models\OrganizationSeat::count(),'payments'=>\App\Models\Payment::count(),'benefits'=>\App\Models\EnrollmentConsultationBenefit::count(),'grants'=>\App\Models\ConsultationGrant::count()]);`;
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
const member = (s, id) => s.members.some((m) => m.id === id);
async function click(expression) {
    lease();
    await evaluate(expression);
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
            "Organization layout " + width,
        );
    }
}
async function end(selector) {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).open=true`,
    );
    await fill(
        selector + " textarea[name=reason]",
        "بررسی صرفاً نرم‌افزاری؛ حفظ سوابق آموزشی",
    );
    await submit(selector + " form");
    await networkIdle();
}
try {
    let s = state();
    await login("admin");
    if (!s.course) {
        await goto("/admin/courses");
        await click("document.querySelector('.page-intro button').click()");
        await until("!!document.querySelector('form.form-grid')");
        await fill(
            'form.form-grid input[maxlength="150"]',
            "دوره بررسی سازمان صرفاً آزمایشی",
        );
        await fill(
            "form.form-grid textarea",
            "دوره ساختگی برای بررسی عملیات سازمان؛ بدون اعتبار رسمی",
        );
        await fill('form.form-grid input[type=number][min="0"]', "100000");
        await fill('form.form-grid input[type=number][min="1"]', "5");
        await click(
            "document.querySelector('form.form-grid input[type=checkbox]').click()",
        );
        await submit("form.form-grid");
        await until("!document.querySelector('form.form-grid')");
        s = state();
    }
    if (s.course.coach_id !== 2) {
        await goto("/admin/courses");
        await fill(".coach-select", "2");
        await networkIdle();
        s = state();
        assert.equal(s.course.coach_id, 2);
    }
    if (!s.organization_id) {
        await goto("/admin/organizations");
        await click("document.querySelector('.page-intro button').click()");
        await until("!!document.querySelector('form.form-grid')");
        await fill(
            'form.form-grid input[maxlength="150"]',
            "سازمان بررسی دسترسی صرفاً آزمایشی",
        );
        await fill("form.form-grid select", "company");
        await fill(
            "form.form-grid input[type=tel]",
            process.env.BROWSER_ORGANIZATION_PHONE,
        );
        await submit("form.form-grid");
        await until("!document.querySelector('form.form-grid')");
        s = state();
    }
    if (!s.license) {
        await goto("/admin/organization-licenses");
        await evaluate("document.querySelector('main details').open=true");
        await fill("[name=organization_id]", String(s.organization_id));
        await fill("[name=cohort_id]", String(s.cohort_id));
        await fill("[name=seat_limit]", "2");
        await fill("[name=contract_reference]", "SOFTWARE-ONLY-ORG70");
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                timeZone: "Asia/Tehran",
            })
                .formatToParts(new Date(Date.now() + 30 * 86400000))
                .map((p) => [p.type, p.value]),
        );
        for (const [label, key] of [
            ["سال", "year"],
            ["ماه", "month"],
            ["روز", "day"],
        ]) {
            await fill(
                `details select[aria-label^="${label}"]`,
                String(Number(parts[key])),
            );
        }
        await click(
            "document.querySelector('details input[type=checkbox]').click()",
        );
        await submit("details form");
        await until("document.querySelectorAll('main article').length===1");
        s = state();
    }
    if (s.license.seat_limit === 2) {
        await goto("/admin/organization-licenses");
        await evaluate("document.querySelector('article details').open=true");
        await fill("article details input[type=number]", "3");
        await click(
            "Array.from(document.querySelectorAll('article details input[type=checkbox]')).at(-1).click()",
        );
        await submit("article details form");
        await networkIdle();
        s = state();
        assert.equal(s.license.seat_limit, 3);
        assert.equal(s.license.revision, 2);
    }
    if (s.invitations.length === 0) {
        await login("organization");
        await goto("/organization/invitations");
        lease();
        await writeFile(
            "/tmp/kardorost-org70-browser.csv",
            "phone\n09121234560\n09121234564\n",
        );
        const root = await send("DOM.getDocument");
        const node = await send("DOM.querySelector", {
            nodeId: root.root.nodeId,
            selector: "[name=invitation_csv]",
        });
        await send("DOM.setFileInputFiles", {
            nodeId: node.nodeId,
            files: ["/tmp/kardorost-org70-browser.csv"],
        });
        lease();
        await evaluate(
            "document.querySelector('[name=invitation_csv]').form.requestSubmit()",
        );
        await until(
            "!!document.querySelector('.invitation-import-preview form')",
        );
        assert.equal(state().invitations.length, 0);
        await click(
            "document.querySelector('.invitation-import-preview input[type=checkbox]').click()",
        );
        await submit(".invitation-import-preview form");
        await until("document.querySelectorAll('.invitation-card').length===2");
        s = state();
        assert.equal(await privateStatus("/organization/members/1"), 403);
        assert.equal(await privateStatus("/organization/members/5"), 403);
    }

    s = state();
    assert.equal(s.invitations.length, 2);
    assert.equal(s.committed_imports, 1);
    const importHash = s.imports_hash;
    for (const [id, phone] of [
        [1, process.env.BROWSER_STUDENT_PHONE],
        [5, "09121234564"],
    ]) {
        if (
            s.invitations.some(
                (i) =>
                    i.status === "pending" && i.phone.endsWith(phone.slice(1)),
            )
        ) {
            await login("student", phone);
            await goto("/organization-invitations");
            await until("!!document.querySelector('.invitation-card form')");
            await click(
                "document.querySelector('.invitation-card input[type=checkbox]').click()",
            );
            await submit(".invitation-card form");
            await until("!document.querySelector('.invitation-card form')");
            s = state();
            assert(member(s, id));
        }
    }
    if (s.enrollments.length === 0) {
        await login("organization");
        await goto(`/organization/licenses/${s.license.id}`);
        await until(
            "document.querySelectorAll('form.organization-license-form input[type=checkbox]:not(:disabled)').length===3",
        );
        await click(
            "document.querySelectorAll('form.organization-license-form input[type=checkbox]:not(:disabled)')[0].click()",
        );
        await click(
            "document.querySelectorAll('form.organization-license-form input[type=checkbox]:not(:disabled)')[1].click()",
        );
        await click(
            "document.querySelectorAll('form.organization-license-form input[type=checkbox]:not(:disabled)')[2].click()",
        );
        await submit("form.organization-license-form");
        await until("document.body.innerText.includes('2 عضو ثبت‌نام شد')");
        s = state();
        assert.equal(s.seats, 2);
        assert.equal(await privateStatus("/organization/members/1"), 200);
        await responsive();
    }
    const enrolled = JSON.stringify(s.enrollments);
    assert.equal(s.seats, 2);
    assert.equal(s.enrollments.length, 2);
    for (const e of s.enrollments) {
        assert.equal(e.status, "active");
        assert.equal(e.amount, 0);
        assert.equal(e.payment_plan, "organization");
    }
    if (member(s, 1)) {
        await login("student");
        await goto("/organization-memberships");
        await until("!!document.querySelector('main details textarea')");
        await end("main details");
        await networkIdle();
        s = state();
        assert(!member(s, 1));
    }
    await login("student");
    await goto("/learning");
    assert(
        await evaluate(
            "document.body.innerText.includes('دوره بررسی سازمان صرفاً آزمایشی')",
        ),
    );
    assert.equal(
        await privateStatus(
            `/learning/${s.enrollments.find((e) => e.user_id === 1).id}`,
        ),
        200,
    );
    if (member(s, 5)) {
        await login("organization");
        await goto("/organization");
        assert.equal(await privateStatus("/organization/members/1"), 403);
        await end('[data-member="5"] details');
        s = state();
        assert(!member(s, 5));
        assert.equal(await privateStatus("/organization/members/5"), 403);
    }
    await login("student", "09121234564");
    await goto("/learning");
    assert(
        await evaluate(
            "document.body.innerText.includes('دوره بررسی سازمان صرفاً آزمایشی')",
        ),
    );
    assert.equal(
        await privateStatus(
            `/learning/${s.enrollments.find((e) => e.user_id === 5).id}`,
        ),
        200,
    );
    await login("admin");
    await goto("/admin/organization-managers");
    if (!member(s, 6)) {
        await fill("input[name=phone]", "09121234565");
        await submit("form.stack-form");
        await networkIdle();
        s = state();
        assert(member(s, 6));
    }
    if (member(s, 4)) {
        await end('[data-member="4"] details');
        s = state();
        assert(!member(s, 4));
    }
    await login("organization");
    await goto("/organization");
    assert(
        !(await evaluate(
            "document.body.innerText.includes('سازمان بررسی دسترسی صرفاً آزمایشی')",
        )),
    );
    assert.equal(await privateStatus("/organization/members/1"), 403);
    await login("organization", "09121234565");
    await goto("/organization");
    assert(
        await evaluate(
            "document.body.innerText.includes('سازمان بررسی دسترسی صرفاً آزمایشی')",
        ),
    );
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        'document.querySelector(".sidebar").getBoundingClientRect().left>=innerWidth',
    );
    lease();
    await writeFile(
        "/tmp/kardorost-organization-workflow70-mobile.png",
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
    s = state();
    assert.equal(s.imports_hash, importHash);
    assert.equal(JSON.stringify(s.enrollments), enrolled);
    assert.equal(s.payments, 0);
    assert.equal(s.benefits, 0);
    assert.equal(s.grants, 0);
    assert.equal(s.seats, 2);
    assert.deepEqual(s.members, [{ id: 6, kind: "manager" }]);
    assert.equal(exceptions.length, 0, exceptions.join("\n"));
    console.log(
        JSON.stringify({
            result: "passed",
            fixture: "six-owned-software-only-users",
            csv_consent: true,
            current_manager_lifecycle: true,
            retained_enrollments: 2,
            retained_seats: 2,
            payments: 0,
            grant_issuance: 0,
            committed_import_unchanged: true,
            widths: [320, 390, 768, 1440],
            live_services_verified: false,
        }),
    );
} finally {
    await send("Emulation.clearDeviceMetricsOverride");
    await send("Page.close");
    socket.close();
}
