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
        new URL(base).port === "8029" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-cohort-announcements-browser.sqlite",
    "Use only the isolated local cohort fixture for directory QA.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'||\App\Models\User::count()<8){throw new RuntimeException('Only an existing isolated fixture may be extended.');}if(\App\Models\User::where('name','like','Browser-directory%')->exists()){if(($argv[1]??'')!=='reuse'){throw new RuntimeException('Directory fixture exists; opt in only to resetting these synthetic accounts.');}$ids=[];for($i=0;$i<31;$i++){$u=\App\Models\User::where('phone',(string)(989111110000+$i))->where('name','like','Browser-directory%')->firstOrFail();$u->forceFill(['is_active'=>$i!==1,'phone_verified_at'=>$i===2?null:now()])->save();$keys=$i===0?['student','coach']:['student'];$u->roles()->sync(\App\Models\Role::whereIn('key',$keys)->pluck('id'));$ids[]=$u->id;}$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();echo json_encode(['ids'=>$ids,'admin_id'=>$admin->id]);return;}$ids=[];for($i=0;$i<31;$i++){$u=\App\Models\User::factory()->create(['name'=>$i===0?'Browser-directory <script>window.directoryInjected=true</script>':'Browser-directory '.$i,'phone'=>(string)(989111110000+$i),'password'=>'Browser-Only-2026','phone_verified_at'=>$i===2?null:now()]);$u->forceFill(['is_active'=>$i!==1])->save();$u->roles()->attach(\App\Models\Role::where('key','student')->value('id'));if($i===0){$u->roles()->attach(\App\Models\Role::where('key','coach')->value('id'));}$ids[]=$u->id;}$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();echo json_encode(['ids'=>$ids,'admin_id'=>$admin->id]);`;
const fixture = JSON.parse(
    execFileSync(
        php,
        [
            "-r",
            code,
            process.env.BROWSER_REUSE_LOCAL_USER_FIXTURE === "1"
                ? "reuse"
                : "fresh",
        ],
        {
            cwd: process.cwd(),
            env,
            encoding: "utf8",
        },
    ),
);
async function directoryGoto(path) {
    await evaluate("document.documentElement.dataset.directoryNavigating='1'");
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname==='/admin/users' && !document.documentElement.dataset.directoryNavigating && !!document.querySelector('[data-testid=user-filters]')`,
    );
    await networkIdle();
}
async function props() {
    return evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props",
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
            `Directory overflow ${width}`,
        );
    }
}
try {
    await login("admin");
    await directoryGoto("/admin/users");
    await fill("[name=q]", "Browser-directory");
    await fill("[name=role]", "student");
    await fill("[name=status]", "active");
    await fill("[name=verification]", "verified");
    await submit("[data-testid=user-filters]");
    await until(
        "location.search.includes('q=Browser-directory') && document.querySelectorAll('[data-user-id]').length===25",
    );
    await networkIdle();
    await directoryGoto(await evaluate("location.pathname+location.search"));
    let p = await props();
    assert.equal(p.users.total, 29);
    assert.equal(p.users.data[0].id, fixture.ids[30]);
    const first = p.users.data.map((u) => u.id);
    assert(
        p.users.data.every(
            (u) =>
                !Object.hasOwn(u, "password") &&
                !Object.hasOwn(u, "email") &&
                !Object.hasOwn(u, "remember_token") &&
                u.roles.every((r) => !Object.hasOwn(r, "pivot")),
        ),
    );
    await responsive();
    await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(a=>a.innerText.includes('بعد')).click()",
    );
    await until("new URL(location.href).searchParams.get('page')==='2'");
    await networkIdle();
    await directoryGoto(await evaluate("location.pathname+location.search"));
    p = await props();
    assert.equal(p.users.data.length, 4);
    assert.equal(p.filters.role, "student");
    assert(p.users.data.every((u) => !first.includes(u.id)));
    assert(
        (await evaluate("document.body.innerText")).includes(
            "<script>window.directoryInjected=true</script>",
        ),
    );
    assert.equal(await evaluate("window.directoryInjected||false"), false);
    assert.equal(p.users.data.at(-1).roles.length, 2);
    await directoryGoto(
        "/admin/users?q=Browser-directory&role=student&status=active&verification=verified",
    );
    await evaluate(
        `document.querySelector('[data-user-id="${fixture.ids[30]}"] button').click()`,
    );
    await until("!!document.querySelector('[data-testid=user-access]')");
    await responsive();
    await evaluate(
        "document.querySelector('[name=access_coach]').click();document.querySelector('[name=access_active]').click()",
    );
    await submit("[data-testid=user-access]");
    await until(
        "!document.querySelector('[data-testid=user-access]') && document.body.innerText.includes('دسترسی و وضعیت کاربر به‌روز شد.')",
    );
    await networkIdle();
    await directoryGoto(await evaluate("location.pathname+location.search"));
    p = await props();
    assert.equal(p.users.total, 28);
    assert(!p.users.data.some((u) => u.id === fixture.ids[30]));
    assert.equal(p.filters.status, "active");
    await directoryGoto("/admin/users");
    const faPhone = "09111110030".replace(
        /\d/g,
        (d) => "۰۱۲۳۴۵۶۷۸۹"[Number(d)],
    );
    await fill("[name=phone]", faPhone);
    await submit("[data-testid=user-filters]");
    await until(
        "location.search.includes('phone=') && document.querySelectorAll('[data-user-id]').length===1",
    );
    await networkIdle();
    await directoryGoto(await evaluate("location.pathname+location.search"));
    p = await props();
    assert.equal(p.users.total, 1);
    assert.equal(p.users.data[0].id, fixture.ids[30]);
    assert.equal(p.filters.phone, "989111110030");
    assert.equal(p.users.data[0].is_active, false);
    assert(p.users.data[0].roles.some((r) => r.key === "coach"));
    await directoryGoto("/admin/users?user_id=" + fixture.admin_id);
    await evaluate(
        `document.querySelector('[data-user-id="${fixture.admin_id}"] button').click()`,
    );
    for (const key of ["student", "admin", "active"]) {
        await evaluate(
            `document.querySelector('[name=access_${key}]').click()`,
        );
    }
    await submit("[data-testid=user-access]");
    await until(
        "!!document.querySelector('[data-testid=user-access] .field-error')",
    );
    assert(
        (
            await evaluate(
                "document.querySelector('[data-testid=user-access]').innerText",
            )
        ).includes(
            "نمی‌توانید دسترسی مدیریت یا فعالیت حساب خودتان را حذف کنید.",
        ),
    );
    await directoryGoto("/admin/users?user_id=" + fixture.admin_id);
    p = await props();
    assert.equal(p.users.data[0].is_active, true);
    assert(p.users.data[0].roles.some((r) => r.key === "admin"));
    await fill("[name=q]", "no-matching-directory-name");
    await submit("[data-testid=user-filters]");
    await until(
        "document.body.innerText.includes('حسابی با این شرایط پیدا نشد')",
    );
    await evaluate(
        "[...document.querySelectorAll('main a')].find(a=>a.innerText.includes('پاک‌کردن فیلترها')).click()",
    );
    await until(
        "location.search==='' && document.querySelectorAll('[data-user-id]').length===25",
    );
    const cache = await evaluate(
        "fetch('/admin/users',{headers:{Accept:'text/html'}}).then(async r=>({cache:r.headers.get('cache-control'),status:r.status,body:(await r.text()).includes('PRIVATE-DIRECTORY-TOKEN')}))",
    );
    assert.equal(cache.status, 200);
    assert(cache.cache.includes("no-store"));
    assert.equal(cache.body, false);
    for (const role of ["student", "coach", "organization"]) {
        if (role === "organization")
            process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
        await login(role);
        const r = await evaluate(
            "fetch('/admin/users',{headers:{Accept:'text/html'}}).then(async r=>({status:r.status,body:(await r.text()).includes('Browser-directory')}))",
        );
        assert.equal(r.status, 403);
        assert.equal(r.body, false);
    }
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "actual-combined-admin-directory-filter-form-29-active-verified-students",
                "twenty-five-per-page-preserved-filters-no-duplicate-multirole-user",
                "escaped-name-and-private-account-payload-exclusion",
                "actual-grant-coach-deactivate-filter-disappearance-and-persian-phone-lookup",
                "self-lockout-prevention-error-and-unchanged-admin-access",
                "empty-clear-no-store-other-three-panels-denied",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
