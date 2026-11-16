<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class LinkedInGuide
{
    public const METHOD = 'resume_snapshot_linkedin_sections_v1';

    public function __construct(private ResumeBuilder $builder) {}

    private function fail(): never
    {
        throw ValidationException::withMessages(['guide' => 'نسخه ثابت رزومه برای این راهنما قابل خواندن نیست؛ با پشتیبانی پیگیری کنید.']);
    }

    private function definition(ResumeVersion $version): array
    {
        try {
            $definition = $version->definition;
            if (! is_array($definition) || $version->number < 1 || $this->builder->definition($definition) !== $definition) {
                $this->fail();
            }
            $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (! hash_equals($version->content_hash, $hash)) {
                $this->fail();
            }

            return $definition;
        } catch (Throwable) {
            $this->fail();
        }
    }

    private function sections(array $definition): array
    {
        $english = $definition['locale'] === 'en';
        $labels = $english
            ? ['headline' => 'Headline', 'about' => 'About', 'experience' => 'Experience', 'education' => 'Education', 'projects' => 'Projects', 'skills' => 'Skills']
            : ['headline' => 'عنوان حرفه‌ای', 'about' => 'درباره من', 'experience' => 'سوابق کاری', 'education' => 'تحصیلات', 'projects' => 'پروژه‌ها', 'skills' => 'مهارت‌ها'];
        $sections = [];
        foreach (['headline' => 'headline', 'about' => 'summary'] as $key => $source) {
            if ($definition[$source] !== '') {
                $sections[] = ['key' => $key, 'label' => $labels[$key], 'text' => $definition[$source]];
            }
        }
        foreach (['experience', 'education', 'projects'] as $group) {
            foreach ($definition[$group] as $index => $entry) {
                $heading = match ($group) {
                    'experience' => $entry['title'].' — '.$entry['employer'],
                    'education' => $entry['degree'].' — '.$entry['institution'],
                    'projects' => $entry['title'],
                };
                $text = implode("\n", array_values(array_filter([$heading, $entry['period'] ?? '', $entry['description']], fn ($value) => $value !== '')));
                $sections[] = ['key' => $group.'-'.$index, 'label' => $labels[$group], 'text' => $text];
            }
        }
        $skills = array_values(array_filter($definition['skills'], fn ($skill) => $skill !== ''));
        if ($skills) {
            $sections[] = ['key' => 'skills', 'label' => $labels['skills'], 'text' => implode("\n", $skills)];
        }

        return array_map(fn ($section) => [...$section, 'characters' => mb_strlen($section['text'])], $sections);
    }

    public function prepare(User $actor, CareerResume $resume, ResumeVersion $version, bool $export = false): array
    {
        $ownerId = CareerResume::whereKey($resume->id)->firstOrFail(['id', 'user_id'])->user_id;

        return DB::transaction(function () use ($actor, $resume, $version, $ownerId, $export): array {
            $accounts = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id')->sharedLock()->get()->keyBy('id');
            $actor = $accounts->get($actor->id);
            abort_unless($actor?->is_active && $actor->phone_verified_at && ($actor->id === $ownerId || $actor->hasRole('admin')), 403);
            $resume = CareerResume::whereKey($resume->id)->sharedLock()->firstOrFail(['id', 'user_id']);
            abort_unless($resume->user_id === $ownerId, 403);
            $version = ResumeVersion::whereKey($version->id)->sharedLock()->firstOrFail();
            abort_unless($version->career_resume_id === $resume->id, 404);
            $definition = $this->definition($version);
            $sections = $this->sections($definition);
            $checks = [
                ['key' => 'headline', 'label' => 'عنوان حرفه‌ای روشن و متناسب با هدف شغلی', 'present' => $definition['headline'] !== ''],
                ['key' => 'about', 'label' => 'معرفی کوتاه بر پایه تجربه‌ها و هدف واقعی خودتان', 'present' => $definition['summary'] !== ''],
                ['key' => 'experience', 'label' => 'سابقه کاری با نقش و مسئولیت واقعی؛ اگر سابقه دارید', 'present' => count($definition['experience']) > 0],
                ['key' => 'education', 'label' => 'تحصیلات یا آموزش قابل توضیح با اطلاعات دقیق', 'present' => count($definition['education']) > 0],
                ['key' => 'projects', 'label' => 'پروژه‌های قابل توضیح؛ اگر پروژه‌ای انجام داده‌اید', 'present' => count($definition['projects']) > 0],
                ['key' => 'skills', 'label' => 'مهارت‌هایی که می‌توانید درباره کاربردشان توضیح دهید', 'present' => count(array_filter($definition['skills'], fn ($value) => $value !== '')) > 0],
            ];
            AuditEvent::create(['actor_id' => $actor->id, 'action' => $export ? 'resume.linkedin_exported' : 'resume.linkedin_viewed', 'subject_type' => 'career_resume', 'subject_id' => $resume->id, 'metadata' => ['version_id' => $version->id, 'version' => $version->number, 'method' => self::METHOD, 'scope' => $actor->id === $ownerId ? 'owner' : 'admin']]);

            return ['resume_id' => $resume->id, 'version' => $version->only('id', 'number', 'created_at'), 'name' => $definition['name'], 'locale' => $definition['locale'], 'method' => self::METHOD, 'sections' => $sections, 'checks' => $checks, 'roles' => $actor->roleKeys()];
        }, 3);
    }

    public function text(array $guide): string
    {
        $english = $guide['locale'] === 'en';
        $intro = $english ? 'LinkedIn profile draft — review before publishing' : 'پیش‌نویس پروفایل لینکدین — پیش از انتشار بازبینی کنید';
        $source = $english ? 'Saved resume version: ' : 'نسخه ثابت رزومه: ';
        $disclosure = $english
            ? 'Only text from this saved resume is arranged here. No translation or independent verification was performed. Contact fields and links are omitted; review free text for personal information. Nothing is published automatically.'
            : 'فقط متن همین نسخه ثابت رزومه مرتب شده است. ترجمه یا تأیید مستقل انجام نشده است. فیلدهای تماس و لینک‌ها منتقل نشده‌اند؛ متن آزاد را از نظر اطلاعات شخصی بازبینی کنید. انتشار خودکار انجام نمی‌شود.';
        $parts = [$intro, $guide['name'], $source.$guide['version']['number'], $disclosure];
        foreach ($guide['sections'] as $section) {
            $parts[] = $section['label']."\n".$section['text'];
        }

        return implode("\n\n", $parts)."\n";
    }
}
