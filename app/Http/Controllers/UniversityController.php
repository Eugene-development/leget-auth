<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

final class UniversityController extends Controller
{
    /** Public proof by certificate UUID; private student records remain protected. */
    public function verifyCertificate(Request $request)
    {
        $input = $request->validate([
            'certificate_id' => 'required|string|uuid',
        ], [
            'certificate_id.required' => 'Введите ID записи сертификата.',
            'certificate_id.string' => 'Введите ID записи сертификата.',
            'certificate_id.uuid' => 'Проверьте ID: он должен совпадать с номером записи в сертификате.',
        ]);
        $id = strtolower(trim($input['certificate_id']));
        $certificate = DB::table('university_certificates')->where('id', $id)->first();
        if (! $certificate) {
            return response()->json(['message' => 'Сертификат с таким ID не найден. Проверьте номер записи.'], 404)
                ->header('Cache-Control', 'private, no-store');
        }

        // Match the account, never the display name: different students can share a name.
        // Read issued snapshots, so archived/revised courses still verify correctly.
        $certificates = DB::table('university_certificates')->where('user_id', $certificate->user_id)
            ->orderByDesc('issued_at')->orderBy('id')
            ->get(['id', 'course_title', 'discipline', 'issued_at']);

        return response()->json([
            'designer_name' => $certificate->student_name,
            'matched_certificate_id' => $certificate->id,
            'certificates' => $certificates,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function catalog()
    {
        return response()->json(['courses' => DB::table('university_courses')->where('status', 'published')->orderBy('position')->get()
            ->map(fn ($course) => $this->present($course, false))]);
    }

    public function enroll(Request $request)
    {
        // Any authenticated role may add education without replacing its existing role.
        $user = DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $user->forceFill(['university_enrolled_at' => $user->university_enrolled_at ?? now()])->save();

            return $user;
        });

        return response()->json(['success' => true, 'token' => JWTAuth::fromUser($user), 'expires_in' => JWTAuth::factory()->getTTL() * 60]);
    }

    public function course(string $slug)
    {
        return response()->json(['course' => $this->present($this->find($slug), true)]);
    }

    public function dashboard(Request $request)
    {
        $id = $request->user()->id;

        return response()->json([
            'student' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'courses' => DB::table('university_courses')->where(fn ($q) => $q->where('status', 'published')->orWhere(fn ($q) => $q->where('status', 'archived')->whereIn('slug', DB::table('university_attempts')->where('user_id', $id)->select('course_slug'))))->orderBy('position')->get()->map(fn ($c) => $this->present($c, false)),
            'attempts' => DB::table('university_attempts')->where('user_id', $id)
                ->orderByDesc('id')->get(['id', 'course_slug', 'assessment', 'score', 'passed', 'created_at'])
                ->map(fn ($attempt) => (array) $attempt + ['grade' => $this->grade((int) $attempt->score)]),
            'certificates' => DB::table('university_certificates')->where('user_id', $id)->orderByDesc('issued_at')->get(),
        ]);
    }

    public function assess(Request $request, string $slug)
    {
        return DB::transaction(function () use ($request, $slug) {
            $course = $this->find($slug, true);
            $curriculum = json_decode($course->curriculum, true, 512, JSON_THROW_ON_ERROR);
            $input = $request->validate([
                'assessment' => 'required|string|max:80', 'request_key' => 'required|uuid',
                'answers' => 'required|array|min:1|max:50', 'answers.*' => 'required|integer|min:0|max:10',
            ]);
            $isExam = $input['assessment'] === 'exam';
            $lesson = collect($curriculum['lessons'])->firstWhere('id', $input['assessment']);
            abort_unless($isExam || $lesson, 404);
            $questions = $isExam ? $curriculum['exam'] : $lesson['questions'];
            $answers = array_map(static fn ($answer) => (int) $answer, $input['answers']);
            ksort($answers);
            abort_unless(count($answers) === count($questions), 422, 'Ответьте на все вопросы.');
            foreach ($questions as $question) {
                abort_unless(array_key_exists($question['id'], $answers) && isset($question['options'][$answers[$question['id']]]), 422, 'Некорректный вариант ответа.');
            }
            $hash = hash('sha256', json_encode([$slug, $input['assessment'], $answers]));
            $result = DB::transaction(function () use ($request, $input, $slug, $course, $curriculum, $questions, $answers, $hash, $isExam) {
                // Serializes attempts/certificate issuance for the same student.
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->hasAbility('university.study'), 403);
                $old = DB::table('university_attempts')->where('user_id', $user->id)->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Этот ключ уже использован для другого ответа.');

                    return $this->result($old, $questions, $answers);
                }
                if ($isExam) {
                    $passed = DB::table('university_attempts')->where('user_id', $user->id)->where('course_slug', $slug)
                        ->where('passed', true)->pluck('assessment')->all();
                    foreach ($curriculum['lessons'] as $lesson) {
                        abort_unless(in_array($lesson['id'], $passed, true), 422, 'Сначала сдайте тесты всех статей курса.');
                    }
                }
                $correct = count(array_filter($questions, fn ($q) => $answers[$q['id']] === $q['correct']));
                $score = (int) round($correct / count($questions) * 100);
                $passed = $score >= ($isExam ? 75 : 50);
                $id = DB::table('university_attempts')->insertGetId([
                    'user_id' => $user->id, 'course_slug' => $slug, 'assessment' => $input['assessment'],
                    'request_key' => $input['request_key'], 'payload_hash' => $hash,
                    'answers' => json_encode($answers), 'score' => $score, 'passed' => $passed, 'created_at' => now(),
                ]);
                if ($isExam && $passed) {
                    DB::table('university_certificates')->insertOrIgnore([
                        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'course_slug' => $slug,
                        'student_name' => $user->name, 'course_title' => $course->title, 'discipline' => $course->discipline,
                        'score' => $score, 'issued_at' => now(),
                    ]);
                }

                return $this->result(DB::table('university_attempts')->find($id), $questions, $answers);
            });

            return response()->json($result);
        });
    }

    public function certificate(Request $request, string $id)
    {
        $certificate = DB::table('university_certificates')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($certificate, 404);

        return response()->json(['certificate' => $certificate]);
    }

    public function interviews(?string $slug = null)
    {
        $query = DB::table('university_interviews')->where('status', 'published');
        if ($slug) {
            $row = $query->where('slug', $slug)->first();
            abort_unless($row, 404);
            $row->chapters = json_decode($row->chapters, true);
            $row->transcript = json_decode($row->transcript, true);

            return response()->json(['interview' => $row]);
        }

        return response()->json(['interviews' => $query->orderBy('position')->get(['slug', 'title', 'speaker', 'description', 'cover_id', 'video_id'])]);
    }

    public function resources()
    {
        return response()->json(['resources' => DB::table('university_resources')->where('status', 'published')->orderBy('position')->get()]);
    }

    private function find(string $slug, bool $lock = false): object
    {
        $query = DB::table('university_courses')->where('slug', $slug)->whereIn('status', ['published', 'archived']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $course = $query->first();
        abort_unless($course, 404);

        return $course;
    }

    private function present(object $course, bool $learning): array
    {
        $curriculum = json_decode($course->curriculum, true, 512, JSON_THROW_ON_ERROR);
        $clean = fn ($q) => ['id' => $q['id'], 'text' => $q['text'], 'options' => $q['options']];

        return [
            'slug' => $course->slug, 'discipline' => $course->discipline, 'title' => $course->title,
            'description' => $course->description, 'status' => $course->status,
            'cover_id' => $learning ? $course->cover_id : null,
            'lessons' => array_map(fn ($l) => ['id' => $l['id'], 'title' => $l['title']] + ($learning ? [
                'sections' => $l['sections'], 'practice' => $l['practice'] ?? null, 'video_id' => $l['video_id'] ?? null, 'questions' => array_map($clean, $l['questions']),
            ] : []), $curriculum['lessons']),
            'exam' => $learning ? array_map($clean, $curriculum['exam']) : [],
        ];
    }

    private function grade(int $score): int
    {
        return $score >= 90 ? 5 : ($score >= 75 ? 4 : ($score >= 50 ? 3 : 2));
    }

    private function result(object $attempt, array $questions, array $answers): array
    {
        $certificate = DB::table('university_certificates')->where('user_id', $attempt->user_id)->where('course_slug', $attempt->course_slug)->first();

        return ['id' => $attempt->id, 'score' => $attempt->score, 'passed' => (bool) $attempt->passed,
            'grade' => $this->grade((int) $attempt->score),
            'certificate_id' => $certificate?->id,
            'feedback' => array_map(fn ($q) => ['id' => $q['id'], 'correct' => $answers[$q['id']] === $q['correct'], 'explanation' => $q['explanation']], $questions)];
    }
}
