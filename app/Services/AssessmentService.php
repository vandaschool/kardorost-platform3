<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentVersion;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['assessment' => $message]);
    }

    private function admin(User $actor): void
    {
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
    }

    private function audit(User $actor, string $action, string $type, int $id): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id]);
    }

    public function definition(array $input): array
    {
        $d = Validator::make($input, [
            'title' => 'required|string|max:160', 'description' => 'required|string|max:3000', 'instructions' => 'required|string|max:5000', 'source' => 'required|string|max:2000',
            'options' => 'required|array|size:5', 'options.*' => 'required|string|max:150',
            'dimensions' => 'required|array|min:1|max:20', 'dimensions.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/D', 'distinct'], 'dimensions.*.title' => 'required|string|max:120', 'dimensions.*.description' => 'nullable|string|max:2000',
            'questions' => 'required|array|min:1|max:300', 'questions.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/D', 'distinct'], 'questions.*.text' => 'required|string|max:1000', 'questions.*.dimension' => 'required|string|max:40', 'questions.*.reverse' => 'required|boolean',
        ])->validate();
        if (! array_is_list($d['options']) || ! array_is_list($d['dimensions']) || ! array_is_list($d['questions'])) {
            $this->fail('گزینه‌ها، ابعاد و پرسش‌ها باید فهرست مرتب باشند.');
        }
        $keys = array_column($d['dimensions'], 'key');
        $used = [];
        foreach ($d['questions'] as $q) {
            if (! in_array($q['dimension'], $keys, true)) {
                $this->fail('بعد هر پرسش باید در فهرست ابعاد وجود داشته باشد.');
            }$used[] = $q['dimension'];
        }
        if (array_diff($keys, $used)) {
            $this->fail('برای هر بعد دست‌کم یک پرسش لازم است.');
        }

        return ['title' => $d['title'], 'description' => $d['description'], 'instructions' => $d['instructions'], 'source' => $d['source'], 'engine' => 'likert_sum_v1', 'options' => array_values($d['options']), 'dimensions' => array_map(fn ($x) => ['key' => $x['key'], 'title' => $x['title'], 'description' => $x['description'] ?? ''], $d['dimensions']), 'questions' => array_map(fn ($x) => ['key' => $x['key'], 'text' => $x['text'], 'dimension' => $x['dimension'], 'reverse' => (bool) $x['reverse']], $d['questions'])];
    }

    public function saveDraft(User $actor, Assessment $assessment, int $revision, array $input): void
    {
        $this->admin($actor);
        $definition = $this->definition($input);
        DB::transaction(function () use ($actor, $assessment, $revision, $definition) {
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($a->revision !== $revision) {
                $this->fail('نسخه فرم مدیریت قدیمی است؛ صفحه را تازه کنید.');
            }
            $a->update(['title' => $definition['title'], 'draft' => $definition, 'revision' => $a->revision + 1]);
            $this->audit($actor, 'assessment.draft_saved', 'assessment', $a->id);
        }, 3);
    }

    public function publish(User $actor, Assessment $assessment, int $revision): AssessmentVersion
    {
        $this->admin($actor);

        return DB::transaction(function () use ($actor, $assessment, $revision) {
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($a->revision !== $revision || ! $a->draft) {
                $this->fail('پیش‌نویس معتبر و نسخه تازه فرم لازم است.');
            }
            $d = $this->definition($a->draft);
            $hash = hash('sha256', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($a->versions()->where('number', $a->current_version)->value('content_hash') === $hash) {
                $this->fail('محتوا نسبت به آخرین انتشار تغییر نکرده است.');
            }
            $v = $a->versions()->create(['number' => $a->current_version + 1, 'definition' => $d, 'content_hash' => $hash, 'published_by' => $actor->id]);
            $a->update(['current_version' => $v->number, 'available' => true, 'revision' => $a->revision + 1]);
            $this->audit($actor, 'assessment.published', 'assessment_version', $v->id);

            return $v;
        }, 3);
    }

    public function availability(User $actor, Assessment $assessment, int $revision, bool $available): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $assessment, $revision, $available) {
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($a->revision !== $revision || ($available && ! $a->current_version)) {
                $this->fail('نسخه تازه فرم و انتشار آزمون لازم است.');
            }
            $a->update(['available' => $available, 'revision' => $a->revision + 1]);
            $this->audit($actor, 'assessment.availability_changed', 'assessment', $a->id);
        }, 3);
    }

    public function start(User $user, Assessment $assessment): AssessmentAttempt
    {
        abort_unless($user->is_active && $user->hasRole('student'), 403);

        return DB::transaction(function () use ($user, $assessment) {
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if (! $a->available || ! $a->current_version) {
                $this->fail('این پرسشنامه برای شروع تازه فعال نیست.');
            }
            $v = $a->versions()->where('number', $a->current_version)->firstOrFail();
            $existing = AssessmentAttempt::where('user_id', $user->id)->where('assessment_version_id', $v->id)->first();
            if ($existing) {
                return $existing;
            }
            $item = AssessmentAttempt::create(['user_id' => $user->id, 'assessment_version_id' => $v->id, 'answers' => []]);
            $this->audit($user, 'assessment.started', 'assessment_attempt', $item->id);

            return $item;
        }, 3);
    }

    private function owner(User $user, AssessmentAttempt $item): void
    {
        abort_unless($user->is_active && $user->hasRole('student') && $item->user_id === $user->id, 403);
    }

    public function save(User $user, AssessmentAttempt $item, int $revision, array $answers): void
    {
        $this->owner($user, $item);
        DB::transaction(function () use ($item, $revision, $answers) {
            $item = AssessmentAttempt::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'in_progress' || $item->revision !== $revision) {
                $this->fail('پاسخ‌ها در جای دیگری تغییر کرده یا نهایی شده‌اند؛ صفحه را تازه کنید.');
            }
            $keys = array_column($item->edition->definition['questions'], 'key');
            $merged = $item->answers;
            foreach ($answers as $key => $value) {
                if (! in_array($key, $keys, true) || ! (is_int($value) || (is_string($value) && preg_match('/^[1-5]$/D', $value))) || (int) $value < 1 || (int) $value > 5) {
                    $this->fail('شناسه پرسش یا گزینه پاسخ معتبر نیست.');
                }
                $merged[$key] = (int) $value;
            }
            $item->update(['answers' => $merged, 'revision' => $item->revision + 1]);
        }, 3);
    }

    public function score(array $definition, array $answers): array
    {
        $groups = [];
        foreach ($definition['dimensions'] as $dim) {
            $groups[$dim['key']] = ['key' => $dim['key'], 'title' => $dim['title'], 'description' => $dim['description'], 'sum' => 0, 'count' => 0];
        }
        foreach ($definition['questions'] as $q) {
            $value = $answers[$q['key']] ?? null;
            if (! is_int($value) || $value < 1 || $value > 5) {
                $this->fail('همه پرسش‌ها باید پاسخ معتبر داشته باشند.');
            }
            $groups[$q['dimension']]['sum'] += $q['reverse'] ? 6 - $value : $value;
            $groups[$q['dimension']]['count']++;
        }

        return ['engine' => 'likert_sum_v1', 'dimensions' => array_values(array_map(fn ($g) => [...$g, 'min' => $g['count'], 'max' => $g['count'] * 5, 'range_percent' => round(($g['sum'] - $g['count']) / ($g['count'] * 4) * 100, 1)], $groups))];
    }

    public function finish(User $user, AssessmentAttempt $item, int $revision): AssessmentAttempt
    {
        $this->owner($user, $item);

        return DB::transaction(function () use ($user, $item, $revision) {
            $item = AssessmentAttempt::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status === 'completed') {
                return $item;
            }
            if ($item->revision !== $revision) {
                $this->fail('نسخه پاسخ‌ها تغییر کرده است؛ ابتدا پاسخ‌های تازه را ببینید.');
            }
            $result = $this->score($item->edition->definition, $item->answers);
            $item->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'revision' => $item->revision + 1]);
            $this->audit($user, 'assessment.completed', 'assessment_attempt', $item->id);

            return $item;
        }, 3);
    }
}
