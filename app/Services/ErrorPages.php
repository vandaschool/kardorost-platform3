<?php

namespace App\Services;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ErrorPages
{
    private const MESSAGES = [
        403 => ['این بخش در دسترس شما نیست', 'برای ادامه، پنل خودتان را باز کنید. اگر دسترسی شما تغییر کرده است، وضعیت فعلی را در پنل بررسی کنید.'],
        404 => ['این صفحه در دسترس نیست', 'ممکن است نشانی تغییر کرده یا دسترسی به این صفحه پایان یافته باشد. از صفحه اصلی یا پنل خودتان ادامه دهید.'],
        405 => ['از مسیر دیگری ادامه دهید', 'برای انجام این کار از لینک‌ها و دکمه‌های داخل سایت استفاده کنید.'],
        419 => ['نشست این فرم نیاز به تازه‌سازی دارد', 'از پنل دوباره وارد این بخش شوید و پیش از ارسال دوباره، وضعیت فعلی را بررسی کنید.'],
        429 => ['کمی به این بخش فرصت بدهید', 'تعداد درخواست‌های این بخش در این بازه به سقف رسیده است. پس از پایان زمان انتظار، از پنل ادامه دهید.'],
        500 => ['در انجام این درخواست مشکلی پیش آمد', 'اکنون امکان ادامه این درخواست فراهم نیست. کمی بعد وضعیت فعلی را از پنل بررسی کنید.'],
        503 => ['این بخش موقتاً در دسترس نیست', 'لطفاً کمی بعد دوباره از صفحه اصلی یا پنل وارد شوید.'],
    ];

    public function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if (! isset(self::MESSAGES[$status]) || $request->is('api/*') || $request->expectsJson() || str_contains(strtolower($response->headers->get('Content-Type', '')), 'json') || (config('app.debug') && $status >= 500)) {
            return $response;
        }
        $retry = $response->headers->get('Retry-After');
        $data = ['status' => $status, 'title' => self::MESSAGES[$status][0], 'description' => self::MESSAGES[$status][1], 'retry_after' => is_string($retry) && ctype_digit($retry) && (int) $retry > 0 && (int) $retry <= 86400 ? (int) $retry : null];
        if ($request->header('X-Inertia')) {
            Inertia::flushShared();
            try {
                $result = Inertia::render('ErrorPage', $data)->toResponse($request)->setStatusCode($status);
            } catch (Throwable) {
                $result = response()->view('errors.problem', $data, $status);
            }
        } else {
            $result = response()->view('errors.problem', $data, $status);
        }
        foreach (['Retry-After', 'Allow', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
            if ($response->headers->has($header)) {
                $result->headers->set($header, $response->headers->get($header));
            }
        }
        $result->headers->set('Cache-Control', 'private, no-store');
        $result->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $result->headers->set('Referrer-Policy', 'no-referrer');
        $result->headers->set('X-Content-Type-Options', 'nosniff');
        $result->headers->set('X-Frame-Options', 'DENY');

        return $result->prepare($request);
    }
}
