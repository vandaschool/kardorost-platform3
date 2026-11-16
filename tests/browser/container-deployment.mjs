import { spawnSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";
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

const environmentFile = process.env.CONTAINER_ENV_FILE;
assert(
    environmentFile?.startsWith("/tmp/"),
    "Use a private isolated local container environment file",
);
const settings = Object.fromEntries(
    readFileSync(environmentFile, "utf8")
        .split("\n")
        .filter((line) => line && !line.startsWith("#") && line.includes("="))
        .map((line) => {
            const i = line.indexOf("=");
            return [line.slice(0, i), line.slice(i + 1)];
        }),
);
assert(
    settings.COMPOSE_PROJECT_NAME?.startsWith("kardorost-validation-"),
    "Only synthetic validation projects may be mutated",
);
assert.equal(settings.APP_URL, base);
const secretValues = Object.entries(settings)
    .filter(
        ([key, value]) => /KEY|PASSWORD|MERCHANT/.test(key) && value.length > 8,
    )
    .map(([, value]) => value);
function sanitized(value) {
    let text = String(value);
    for (const secret of secretValues)
        text = text.split(secret).join("[redacted]");
    return text;
}
function docker(
    args,
    { input, allowedFailure = false, timeout = 240000 } = {},
) {
    const result = spawnSync("docker", args, {
        input,
        encoding: "utf8",
        timeout,
        env: { ...process.env, ...settings },
        maxBuffer: 8 * 1024 * 1024,
    });
    if (result.status !== 0 && !allowedFailure) {
        writeFileSync(
            "/tmp/kardorost-container-acceptance-error.log",
            sanitized(result.stderr + "\n" + result.stdout),
            { mode: 0o600 },
        );
        throw Error(
            "Local container command failed; inspect the sanitized acceptance error log.",
        );
    }
    return result;
}
function compose(args, options = {}) {
    return docker(["compose", "--env-file", environmentFile, ...args], options);
}
const composition = JSON.parse(compose(["config", "--format", "json"]).stdout);
assert(
    !("DB_ADMIN_PASSWORD" in composition.services.app.environment),
    "Database administrator secret must not reach the application",
);
for (const role of [
    "app",
    "worker-default",
    "worker-sms",
    "scheduler",
    "web",
    "postgres",
]) {
    assert.equal(composition.services[role].read_only, true);
    assert(composition.services[role].cap_drop.includes("ALL"));
}
assert(
    !composition.services.postgres.ports && !composition.services.app.ports,
    "Only loopback web traffic may be published",
);
assert.equal(composition.services.web.ports[0].host_ip, "127.0.0.1");
compose([
    "up",
    "-d",
    "--no-build",
    "--wait",
    "--wait-timeout",
    "120",
    "postgres",
]);
compose([
    "run",
    "--rm",
    "--no-deps",
    "app",
    "php",
    "artisan",
    "migrate",
    "--force",
    "--no-interaction",
]);
compose(["up", "-d", "--no-build", "--wait", "--wait-timeout", "180"]);
const payload = `<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(\\App\\Models\\User::count()!==0){throw new \\RuntimeException('Refusing to overwrite nonempty fixture accounts.');}
$roles=['student','coach','admin','org_admin'];$ids=[];
foreach($roles as $index=>$role){$u=\\App\\Models\\User::create(['name'=>'بررسی کانتینر '.$role,'phone'=>'98912123456'.$index,'password'=>'Browser-Only-2026','phone_verified_at'=>now()]);$u->roles()->attach(\\App\\Models\\Role::where('key',$role)->value('id'));$ids[$role]=$u->id;}
$org=\\App\\Models\\Organization::create(['name'=>'سازمان آزمایشی کانتینر','type'=>'company','status'=>'active']);$org->members()->attach($ids['org_admin'],['kind'=>'manager']);$org->members()->attach($ids['student'],['kind'=>'member']);
$lead=app(\\App\\Services\\ContactLeads::class)->receive(['request_key'=>(string)\\Illuminate\\Support\\Str::uuid(),'name'=>'درخواست آزمایشی کانتینر','phone'=>'09121112222','audience'=>'company','organization_name'=>'سازمان محلی کانتینر','subject'=>'training','message'=>'این پیام فقط برای بررسی رمزگذاری و بازیابی محلی کانتینر ساخته شده است.','consent'=>true]);
\\Illuminate\\Support\\Facades\\Storage::disk('support')->put('container-acceptance.txt','private-container-storage-fixture');
$role=\\Illuminate\\Support\\Facades\\DB::selectOne('select rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls from pg_roles where rolname=current_user');
echo json_encode(['users'=>$ids,'lead_id'=>$lead->id,'db_role_safe'=>!$role->rolsuper&&!$role->rolcreatedb&&!$role->rolcreaterole&&!$role->rolreplication&&!$role->rolbypassrls,'runtime_uid'=>posix_geteuid(),'no_embedded_env'=>!is_file(base_path('.env')),'no_embedded_sqlite'=>!is_file(database_path('database.sqlite')),'no_embedded_build_ca'=>!is_file('/run/secrets/build_ca_bundle'),'development_tools_absent'=>!class_exists('Faker\\Factory')&&!class_exists('Laravel\\Boost\\BoostServiceProvider'),'file_hash'=>hash('sha256',\\Illuminate\\Support\\Facades\\Storage::disk('support')->get('container-acceptance.txt'))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
`;
const fixture = JSON.parse(
    compose(["exec", "-T", "app", "php"], { input: payload }).stdout,
);
assert(
    fixture.db_role_safe &&
        fixture.runtime_uid !== 0 &&
        fixture.no_embedded_env &&
        fixture.no_embedded_sqlite &&
        fixture.no_embedded_build_ca &&
        fixture.development_tools_absent,
    "Least-privilege and clean-image checks must pass",
);
writeFileSync(
    "/tmp/kardorost-container-acceptance-fixture.json",
    JSON.stringify(fixture),
    { mode: 0o600 },
);
const forward = await (
    await fetch(base + "/", {
        headers: {
            "X-Forwarded-Proto": "https",
            "X-Forwarded-Port": "443",
            "X-Forwarded-Host": "attacker.invalid",
            "X-Forwarded-Prefix": "/forged",
        },
    })
).text();
assert(
    !forward.includes("attacker.invalid") &&
        !forward.includes("/forged/build/"),
    "Forwarded host/prefix must not affect generated links",
);
assert(
    forward.includes("https://127.0.0.1/build/"),
    "Trusted edge HTTPS must reach PHP URL generation",
);
for (const path of [
    "/.env",
    "/index.php",
    "/storage/app/support-private/container-acceptance.txt",
]) {
    const response = await fetch(base + path);
    assert(
        [403, 404].includes(response.status),
        `Private path must be blocked: ${path}`,
    );
}

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

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

try {
    for (const role of ["student", "coach", "admin", "organization"]) {
        await login(role);
        assert((await evaluate("document.body.innerText")).includes("کاردرست"));
        if (role === "student") {
            const result = await evaluate(
                "(async()=>{const r=await fetch('/admin/readiness',{headers:{Accept:'application/json'}});await r.text();return r.status})()",
            );
            assert.equal(result, 403);
        }
    }
    await login("admin");
    await goto("/admin/readiness");
    for (const signal of ["scheduler", "queue.default", "queue.sms"])
        await until(
            `document.querySelector('[data-readiness-check="${signal}"]').dataset.state==='pass'`,
        );
    const report = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.report",
    );
    for (const id of [
        "database",
        "migrations",
        "admin_account",
        "encryption_key",
        "private_storage",
        "built_assets",
        "durable_drivers",
        "runtime",
        "trusted_proxy_configuration",
    ])
        assert.equal(report.checks.find((row) => row.id === id).state, "pass");
    assert.equal(report.live_services_verified, false);
    assert.equal(report.deployment_verified, false);
    await goto("/admin/contact-leads/" + fixture.lead_id);
    await until("!!document.querySelector('[data-contact-lead]')");
    assert(
        (await evaluate("document.body.innerText")).includes(
            "درخواست آزمایشی کانتینر",
        ),
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Container UI overflow ${width}`,
        );
    }
    await networkIdle();
    compose([
        "up",
        "-d",
        "--no-build",
        "--force-recreate",
        "--wait",
        "--wait-timeout",
        "120",
        "app",
        "web",
    ]);
    const check = `<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo json_encode(['users'=>\\App\\Models\\User::count(),'lead_name'=>\\App\\Models\\ContactLead::findOrFail(${fixture.lead_id})->name,'file_hash'=>hash('sha256',\\Illuminate\\Support\\Facades\\Storage::disk('support')->get('container-acceptance.txt'))]);`;
    const persisted = JSON.parse(
        compose(["exec", "-T", "app", "php"], { input: check }).stdout,
    );
    assert.equal(persisted.users, 4);
    assert.equal(persisted.lead_name, "درخواست آزمایشی کانتینر");
    assert.equal(persisted.file_hash, fixture.file_hash);
    await login("admin");
    await goto("/admin/readiness");
    await until("!!document.querySelector('[data-readiness-summary]')");
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            local_storage_mode:
                "vfs_flattened_filesystem_and_verified_runtime_config",
            checks: [
                "real-production-images-without-development-tools-or-embedded-data",
                "nonroot-readonly-service-configuration",
                "separate-administrator-and-restricted-application-db-roles",
                "real-postgresql-migrations",
                "actual-default-sms-and-scheduler-probes",
                "four-panel-password-login-and-role-isolation",
                "trusted-https-forwarding-with-host-prefix-protection",
                "private-path-blocking",
                "encrypted-contact-data-and-private-storage-persist-after-recreation",
                "320-390-768-1440-layouts",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
