<?php

namespace App\Services;

use App\Models\User;
use FilesystemIterator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class OperationalReadiness
{
    private function publicUrl(): bool
    {
        $url = config('app.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! $host) {
            return false;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return (bool) filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        foreach (['localhost', 'test', 'invalid', 'example', 'example.com', 'example.org', 'example.net', 'local'] as $reserved) {
            if ($host === $reserved || str_ends_with($host, '.'.$reserved)) {
                return false;
            }
        }

        return str_contains($host, '.');
    }

    private function encryptionKey(): bool
    {
        $key = config('app.key');
        if (! is_string($key)) {
            return false;
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && is_string(config('app.cipher')) && Encrypter::supported($key, config('app.cipher'));
    }

    private function canonicalPath(string $path): string
    {
        if ($resolved = realpath($path)) {
            return $resolved;
        }
        $parent = dirname($path);
        if ($parent === $path) {
            return $path;
        }
        $base = basename($path);
        $resolved = $this->canonicalPath($parent);

        return $base === '..' ? dirname($resolved) : ($base === '.' ? $resolved : $resolved.DIRECTORY_SEPARATOR.$base);
    }

    private function inside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function privateStorage(): bool
    {
        $public = $this->canonicalPath(public_path());
        $roots = [];
        foreach (['learning', 'support', 'receipts', 'refunds'] as $disk) {
            $settings = config('filesystems.disks.'.$disk);
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'local' || ($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? true) !== false || ! is_string($settings['root'] ?? null) || ! str_starts_with($settings['root'], DIRECTORY_SEPARATOR)) {
                return false;
            }
            $root = $this->canonicalPath($settings['root']);
            if ($this->inside($root, $public) || $this->inside($public, $root)) {
                return false;
            }
            $ancestor = $root;
            while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            if (! is_dir($ancestor) || ! is_writable($ancestor)) {
                return false;
            }
            $roots[] = $root;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if (! $file->isLink()) {
                continue;
            }
            $target = $file->getRealPath();
            if (! $target) {
                continue;
            }
            foreach ($roots as $root) {
                if ($this->inside($target, $root) || $this->inside($root, $target)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function builtAssets(): bool
    {
        if (is_file(public_path('hot')) || ! is_file(public_path('build/manifest.json'))) {
            return false;
        }
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['resources/js/app.js', 'resources/js/public.js'] as $entry) {
            if (! isset($manifest[$entry]['file'])) {
                return false;
            }
        }
        foreach ($manifest as $asset) {
            foreach ([$asset['file'] ?? null, ...($asset['css'] ?? [])] as $file) {
                if (! is_string($file) || str_contains($file, '..') || ! is_file(public_path('build/'.$file))) {
                    return false;
                }
            }
        }

        return true;
    }

    public function report(): array
    {
        $checks = [];
        $check = function (string $id, string $label, bool $passed, string $help, bool $blocking = true, array $data = []) use (&$checks): void {
            $checks[] = ['id' => $id, 'label' => $label, 'state' => $passed ? 'pass' : ($blocking ? 'fail' : 'warning'), 'blocking' => $blocking, 'help' => $help, ...$data];
        };
        $check('environment', 'محیط بهره‌برداری', app()->environment('production'), 'محیط سرور عمومی باید production باشد.');
        $check('debug', 'نمایش خطاهای داخلی', config('app.debug') === false, 'نمایش جزئیات خطا در سرور عمومی باید خاموش باشد.');
        $check('public_url', 'نشانی عمومی HTTPS', $this->publicUrl(), 'دامنه واقعی HTTPS را تنظیم کنید؛ صحت DNS و گواهی TLS جداگانه بررسی می‌شود.');
        $check('trusted_proxy_configuration', 'تنظیم مرز پراکسی', config('trustedproxy.invalid_count', 0) === 0, 'فقط IP یا CIDR صریح پراکسی مورد اعتماد را تنظیم کنید؛ wildcard و شبکه صفر مجاز نیستند.');
        $check('encryption_key', 'کلید رمزگذاری', $this->encryptionKey(), 'کلید معتبر و پایدار لازم است؛ نسخه امن مستقل آن را نگه دارید و بدون برنامه بازیابی عوض نکنید.');
        $check('runtime', 'نسخه و افزونه‌های PHP', PHP_VERSION_ID >= 80400 && extension_loaded('pdo_pgsql') && extension_loaded('mbstring') && extension_loaded('openssl') && extension_loaded('fileinfo'), 'PHP 8.4 یا بالاتر با افزونه‌های PostgreSQL، متن، رمزگذاری و تشخیص فایل لازم است.');
        $check('locale_timezone', 'زبان و ساعت', config('app.timezone') === 'Asia/Tehran' && config('app.locale') === 'fa', 'زبان فارسی و ساعت تهران برای تاریخ‌ها و سررسیدها لازم‌اند.');
        $check('session_security', 'امنیت نشست', config('session.secure') === true && config('session.http_only') === true && config('session.encrypt') === true && in_array(config('session.same_site'), ['lax', 'strict'], true), 'کوکی امن و HttpOnly، رمزگذاری نشست و SameSite مناسب را فعال کنید.');
        $check('durable_drivers', 'ذخیره پایدار نشست، صف و کش', config('session.driver') === 'database' && config('queue.default') === 'database' && config('cache.default') === 'database' && config('queue.connections.database.retry_after') > 30, 'این نسخه با نشست، کش و صف database و retry_after بیش از زمان job سی‌ثانیه‌ای بررسی شده است.');
        try {
            $check('private_storage', 'محل فایل‌های خصوصی', $this->privateStorage(), 'پوشه‌های یادگیری، پشتیبانی و مدارک مالی باید خصوصی، قابل نوشتن و خارج از دسترسی مستقیم public باشند.');
        } catch (Throwable) {
            $check('private_storage', 'محل فایل‌های خصوصی', false, 'بررسی مسیرهای خصوصی موفق نبود؛ دسترسی پوشه‌ها و لینک‌ها را در سرور بررسی کنید.');
        }
        $writable = true;
        foreach ([storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            $writable = $writable && is_dir($path) && is_writable($path);
        }
        $check('writable_runtime', 'دسترسی نوشتن زمان اجرا', $writable, 'پوشه‌های اجرایی storage و bootstrap/cache باید برای کاربر برنامه قابل نوشتن باشند.');
        try {
            $check('built_assets', 'فایل‌های ساخته‌شده رابط', $this->builtAssets(), 'خروجی کامل build عمومی و پنل لازم است و فایل hot توسعه نباید روی سرور عمومی باشد.');
        } catch (Throwable) {
            $check('built_assets', 'فایل‌های ساخته‌شده رابط', false, 'manifest یا فایل‌های build کامل و معتبر نیستند.');
        }
        try {
            $connection = DB::connection();
            $connection->select('select 1');
            $check('database', 'پایگاه داده PostgreSQL', $connection->getDriverName() === 'pgsql', 'اتصال فعال PostgreSQL لازم است؛ این بررسی داده تجاری را تغییر نمی‌دهد.');
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
            $ran = $migrator->getRepository()->getRan();
            $check('migrations', 'مهاجرت‌های پایگاه داده', ! array_diff($files, $ran), 'پیش از انتشار، همه مهاجرت‌های همین نسخه باید اجرا شده باشند.');
            $check('admin_account', 'حساب مدیر فعال', User::where('is_active', true)->whereNotNull('phone_verified_at')->whereHas('roles', fn ($query) => $query->where('key', 'admin'))->exists(), 'حداقل یک ادمین فعال با موبایل تأییدشده برای رسیدگی عملیاتی لازم است.');
        } catch (Throwable) {
            $check('database_inspection', 'بررسی اتصال و ساختار پایگاه داده', false, 'اتصال یا ساختار پایگاه داده قابل بررسی نبود؛ جزئیات اتصال فقط در سرور بررسی شوند.');
        }
        foreach (['scheduler' => 'زمان‌بند هر دقیقه', 'queue.default' => 'کارگر صف عمومی', 'queue.sms' => 'کارگر صف پیامک'] as $signal => $label) {
            try {
                $status = app(OperationalHeartbeats::class)->status($signal);
                $check($signal, $label, $status['fresh'], 'باید در سه دقیقه اخیر نشانه اجرا ثبت شده باشد؛ ثبت فرمان زمان‌بند به‌تنهایی اجرای کارگر صف را تأیید نمی‌کند.', true, ['last_seen_at' => $status['last_seen_at']]);
            } catch (Throwable) {
                $check($signal, $label, false, 'نشانه اجرا قابل خواندن نبود؛ کش پایدار و اتصال آن را بررسی کنید.');
            }
        }
        try {
            $jobs = DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table'))->whereIn('queue', ['default', 'sms'])->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp);
            $oldest = (clone $jobs)->min('available_at');
            $check('queue_delay', 'انتظار صف‌های آماده', $oldest === null || now()->timestamp - (int) $oldest <= 300, 'کار آماده نباید بیش از پنج دقیقه منتظر بماند؛ صف رزروشده یا زمان‌دار آینده در این شمارش نیست.', true, ['pending_count' => (clone $jobs)->count()]);
        } catch (Throwable) {
            $check('queue_delay', 'انتظار صف‌های آماده', false, 'جدول صف قابل بررسی نبود.');
        }
        $client = app(MelipayamakClient::class);
        $check('otp_configuration', 'تنظیم پیامک ثبت‌نام', $client->configured(config('platform.sms.melipayamak.otp_body_id')), 'ثبت‌نام واقعی به تنظیم معتبر قالب OTP ملی‌پیامک نیاز دارد؛ این بررسی پیام نمی‌فرستد.');
        foreach (['invitations' => 'دعوت', 'reminders' => 'یادآوری'] as $feature => $label) {
            if (config('platform.sms.melipayamak.'.$feature.'_enabled')) {
                $body = $feature === 'invitations' ? 'invitation_body_id' : 'reminder_body_id';
                $check('sms_'.$feature, 'تنظیم پیامک '.$label, $client->configured(config('platform.sms.melipayamak.'.$body)), 'قالب مستقل و معتبر برای قابلیت فعال‌شده لازم است.');
            }
        }
        if (config('platform.sms.melipayamak.delivery_enabled')) {
            $check('sms_delivery', 'تنظیم دریافت وضعیت پیامک', config('platform.sms.melipayamak.mode') === 'rest' && $client->configured(config('platform.sms.melipayamak.otp_body_id')), 'دریافت وضعیت در این نسخه فقط با اتصال REST مستند ملی‌پیامک پشتیبانی می‌شود.');
        }
        $gateway = config('platform.zibal.enabled');
        $merchant = config('platform.zibal.merchant');
        $check('gateway_configuration', 'تنظیم درگاه زیبال', $gateway && is_string($merchant) && trim($merchant) !== '' && strtolower(trim($merchant)) !== 'zibal', 'برای فروش پولی، مرچنت واقعی و درگاه فعال لازم است؛ پذیرش پرداخت زنده جداگانه بررسی می‌شود.', (bool) $gateway);
        $failures = count(array_filter($checks, fn ($row) => $row['blocking'] && $row['state'] === 'fail'));

        return ['checked_at' => now()->toIso8601String(), 'scope' => 'configuration_and_local_runtime_only', 'blocking_count' => $failures, 'checks_passed' => $failures === 0, 'checks' => $checks, 'live_services_verified' => false, 'deployment_verified' => false, 'manual_acceptance' => ['آزمون واقعی پیامک، درگاه، پخش و ورود کلاس با حساب‌های سرویس مالک', 'آزمون HTTPS، DNS و کوکی روی دامنه نهایی', 'پشتیبان خارج از سرور، نگهداری مستقل کلید رمزگذاری و تمرین بازیابی', 'آزمون نهایی چهار پنل و تصمیم‌های تجاری بازپرداخت باقی‌مانده']];
    }
}
