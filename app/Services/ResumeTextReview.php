<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ResumeCheck;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ResumeTextReview
{
    public const METHOD = 'structure_keyword_overlap_v1';

    private function normalize(string $text): string
    {
        $text = mb_strtolower(strtr($text, ['ي' => 'ی', 'ك' => 'ک', '‌' => ' ']));

        $text = preg_replace('~https?://\S+~u', ' ', $text);
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]+/u', ' ', $text);
        preg_match_all('/(?:c\+\+|c#|\.net|[\p{L}\p{N}]+(?:\.[\p{L}\p{N}]+)*)/u', $text, $matches);

        return implode(' ', $matches[0]);
    }

    private function values(array $data): string
    {
        $values = [];
        array_walk_recursive($data, function ($value) use (&$values) {
            if (is_string($value)) {
                $values[] = $value;
            }
        });

        return implode(' ', $values);
    }

    public function report(array $definition, string $jobText): array
    {
        $text = $this->normalize($this->values(array_diff_key($definition, ['locale' => true])));
        $job = $this->normalize($jobText);
        $stop = explode(' ', 'برای درباره دارای توانایی نیاز نیازمند همکاری شرکت تجربه کاری کار های است هست این آن یک با به از در و یا را که تا ما شما خود درون بیشتر همچنین مانند باید خواهد شود شدن جهت مورد تحت بسیار کسب سال روز ماه the and for with that this from your you our are will have has be can to of in on at as is an or a we it by years experience work role team company about their into skills ability required requirements');
        $frequencies = [];
        foreach (explode(' ', $job) as $word) {
            if ((mb_strlen($word) >= 3 || in_array($word, ['ai', 'ml', 'ui', 'ux', 'js', 'ts', 'go', 'r', 'c', 'c#'], true)) && preg_match('/\p{L}/u', $word) && ! in_array($word, $stop, true)) {
                $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
            }
        }
        arsort($frequencies);
        $terms = array_slice(array_keys($frequencies), 0, 40);
        $words = array_flip(explode(' ', $text));
        $matched = array_values(array_filter($terms, fn ($term) => isset($words[$term])));
        $missing = array_values(array_filter($terms, fn ($term) => ! isset($words[$term])));

        return ['method' => self::METHOD, 'word_count' => count(array_filter(explode(' ', $text))), 'checks' => [
            ['key' => 'name', 'present' => $definition['name'] !== ''],
            ['key' => 'headline', 'present' => $definition['headline'] !== ''],
            ['key' => 'contact', 'present' => $definition['email'] !== '' || $definition['phone'] !== ''],
            ['key' => 'summary', 'present' => $definition['summary'] !== ''],
            ['key' => 'experience', 'present' => count($definition['experience']) > 0],
            ['key' => 'education', 'present' => count($definition['education']) > 0],
            ['key' => 'skills', 'present' => count($definition['skills']) > 0],
            ['key' => 'experience_details', 'present' => count(array_filter($definition['experience'], fn ($entry) => ! empty($entry['description']))) > 0],
        ], 'job_provided' => trim($jobText) !== '', 'extracted_terms' => $terms, 'matched_terms' => $matched, 'missing_terms' => $missing];
    }

    public function check(User $actor, ResumeVersion $version, string $jobText): ResumeCheck
    {
        Validator::make(['job_text' => $jobText], ['job_text' => 'nullable|string|max:12000'])->validate();
        $jobText = trim($jobText);

        return DB::transaction(function () use ($actor, $version, $jobText) {
            $resume = app(ResumeBuilder::class)->locked($actor, $version->resume);
            $version = $resume->versions()->findOrFail($version->id);
            $hash = hash('sha256', $jobText);
            $existing = $version->checks()->where('job_hash', $hash)->where('method', self::METHOD)->first();
            if ($existing) {
                return $existing;
            }
            $check = $version->checks()->create(['created_by' => $actor->id, 'job_text' => $jobText, 'job_hash' => $hash, 'method' => self::METHOD, 'report' => $this->report($version->definition, $jobText)]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'resume.text_reviewed', 'subject_type' => 'career_resume', 'subject_id' => $resume->id, 'metadata' => ['version_id' => $version->id, 'method' => self::METHOD]]);

            return $check;
        });
    }
}
