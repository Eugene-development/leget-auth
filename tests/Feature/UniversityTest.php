<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class UniversityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_23_140000_create_university_tables.php')->up();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_27_160000_extend_university_content.php')->up();
        $courses = json_decode(file_get_contents(__DIR__.'/../../../leget-db/database/data/university.json'), true);
        foreach ($courses as $i => $c) {
            DB::table('university_courses')->insert(['slug' => $c['slug'], 'discipline' => $c['discipline'], 'title' => $c['title'], 'description' => $c['description'], 'position' => $i, 'curriculum' => json_encode($c['curriculum']), 'created_at' => now(), 'updated_at' => now()]);
        }
        Notification::fake();
        config(['services.smartcaptcha.secret' => null]);
    }

    public function test_registration_assigns_student_but_never_trusts_requested_role(): void
    {
        $this->postJson('/api/university/register', ['name' => 'Студент', 'email' => 'student@example.test', 'password' => 'secret-password', 'password_confirmation' => 'secret-password', 'role' => 'superadmin'])
            ->assertCreated()->assertJsonPath('user.role', 'student')->assertJsonPath('user.roles', ['student']);
        $this->assertDatabaseHas('users', ['email' => 'student@example.test', 'role' => 'student']);
    }

    public function test_catalog_has_no_answers_or_articles_and_learning_is_protected(): void
    {
        $catalog = $this->getJson('/api/university/catalog')->assertOk()->json('courses');
        $this->assertCount(14, $catalog);
        $this->assertArrayNotHasKey('sections', $catalog[0]['lessons'][0]);
        $this->assertSame([], $catalog[0]['exam']);
        $this->getJson('/api/university/courses/design-planning')->assertUnauthorized();
        $this->actingAs($this->student(Role::Client), 'api')->getJson('/api/university/courses/design-planning')->assertForbidden();
    }

    public function test_exam_requires_lessons_and_issues_exactly_one_private_certificate(): void
    {
        $student = $this->student();
        $this->actingAs($student, 'api');
        $this->postJson('/api/university/courses/design-planning/assess', $this->payload('exam'))->assertStatus(422);
        foreach (['lesson-1', 'lesson-2'] as $lesson) {
            $payload = $this->payload($lesson);
            $first = $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertOk()->assertJsonPath('score', 100)->json('id');
            $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertOk()->assertJsonPath('id', $first);
            $payload['answers']['q1'] = ($payload['answers']['q1'] + 1) % 3;
            $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertStatus(409);
        }
        $result = $this->postJson('/api/university/courses/design-planning/assess', $this->payload('exam'))->assertOk()->assertJsonPath('grade', 5)->json();
        $this->postJson('/api/university/courses/design-planning/assess', $this->payload('exam'))->assertOk();
        $this->assertDatabaseCount('university_certificates', 1);
        $this->getJson('/api/university/certificates/'.$result['certificate_id'])->assertOk()->assertJsonPath('certificate.student_name', 'Студент');
        $this->actingAs($this->student(), 'api')->getJson('/api/university/certificates/'.$result['certificate_id'])->assertNotFound();
        $this->getJson('/api/university/dashboard')->assertJsonCount(0, 'attempts')->assertJsonCount(0, 'certificates');
    }

    public function test_grading_ignores_claimed_score_and_rejects_invalid_answers(): void
    {
        $this->actingAs($this->student(), 'api');
        $course = $this->getJson('/api/university/courses/design-planning')->assertOk()->json('course');
        $this->assertArrayNotHasKey('correct', $course['lessons'][0]['questions'][0]);
        $this->assertArrayNotHasKey('explanation', $course['exam'][0]);
        $payload = $this->payload('lesson-1');
        foreach ($payload['answers'] as &$answer) {
            $answer = ($answer + 1) % 3;
        }
        unset($answer);
        $payload['score'] = 100;
        $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertOk()->assertJsonPath('score', 0)->assertJsonPath('passed', false);
        $payload = $this->payload('lesson-1');
        $payload['answers']['q1'] = 9;
        $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertStatus(422);
        $this->assertDatabaseCount('university_certificates', 0);
    }

    public function test_enrollment_does_not_overwrite_partner_or_staff_role(): void
    {
        $user = $this->student(Role::Partner);
        $this->actingAs($user, 'api')->postJson('/api/university/enroll')->assertOk();
        $this->assertSame(Role::Partner, $user->fresh()->role);
        $user = $this->student(Role::Client);
        $this->actingAs($user, 'api')->postJson('/api/university/enroll')->assertOk();
        $this->assertSame(Role::Client, $user->fresh()->role);
        $this->assertSame(['client', 'student'], $user->fresh()->roleNames());
        $this->assertTrue($user->fresh()->hasAbility('cabinet.view'));
        $this->assertTrue($user->fresh()->hasAbility('promo.client'));
        $this->actingAs($user->fresh(), 'api')->getJson('/api/university/dashboard')->assertOk();
    }

    public function test_every_seeded_article_and_exam_has_a_valid_assessment(): void
    {
        foreach (DB::table('university_courses')->get() as $course) {
            $curriculum = json_decode($course->curriculum, true);
            $this->assertCount(2, $curriculum['lessons']);
            $this->assertCount(4, $curriculum['exam']);
            foreach ($curriculum['lessons'] as $lesson) {
                $this->assertCount(3, $lesson['sections']);
                $this->assertCount(2, $lesson['questions']);
                foreach ($lesson['questions'] as $q) {
                    $this->assertCount(3, array_unique($q['options']));
                    $this->assertArrayHasKey($q['correct'], $q['options']);
                }
            }
        }
    }

    public function test_drafts_stay_private_and_archived_progress_stays_accessible(): void
    {
        DB::table('university_courses')->where('slug', 'design-light')->update(['status' => 'draft']);
        $this->getJson('/api/university/catalog')->assertJsonCount(13, 'courses');
        $this->actingAs($this->student(), 'api');
        $this->getJson('/api/university/courses/design-light')->assertNotFound();
        $this->postJson('/api/university/courses/design-light/assess', $this->payload('lesson-1'))->assertNotFound();
        $this->postJson('/api/university/courses/design-planning/assess', $this->payload('lesson-1'))->assertOk();
        DB::table('university_courses')->where('slug', 'design-planning')->update(['status' => 'archived']);
        $this->getJson('/api/university/courses/design-planning')->assertOk();
        $dashboard = $this->getJson('/api/university/dashboard')->assertOk()->json();
        $this->assertContains('design-planning', array_column($dashboard['courses'], 'slug'));
        $this->assertNotContains('design-light', array_column($dashboard['courses'], 'slug'));
        $this->assertCount(1, $dashboard['attempts']);
        $this->getJson('/api/university/interviews')->assertOk()->assertJsonCount(0, 'interviews');
        $this->getJson('/api/university/resources')->assertOk()->assertJsonCount(0, 'resources');
    }

    public function test_unsuccessful_retake_does_not_remove_a_pass(): void
    {
        $this->actingAs($this->student(), 'api');
        foreach (['lesson-1', 'lesson-2'] as $id) {
            $this->postJson('/api/university/courses/design-planning/assess', $this->payload($id))->assertOk();
        }
        $payload = $this->payload('lesson-1');
        foreach ($payload['answers'] as &$answer) {
            $answer = ($answer + 1) % 3;
        }
        unset($answer);
        $this->postJson('/api/university/courses/design-planning/assess', $payload)->assertOk()->assertJsonPath('passed', false);
        $this->postJson('/api/university/courses/design-planning/assess', $this->payload('exam'))->assertOk()->assertJsonPath('passed', true);
    }

    public function test_public_verification_returns_issued_name_and_only_same_accounts_certificates(): void
    {
        $owner = $this->student();
        $other = $this->student(); // Same display name must never merge different accounts.
        $first = $this->issueCertificate($owner, 'design-planning', 'Ирина Волкова');
        $second = $this->issueCertificate($owner, 'design-light', 'Ирина Волкова');
        $unrelated = $this->issueCertificate($other, 'design-planning', 'Ирина Волкова');
        DB::table('university_courses')->where('slug', 'design-planning')->update(['status' => 'archived']);
        DB::table('users')->where('id', $owner->id)->update(['name' => 'Новое имя профиля']);

        $data = $this->postJson('/api/university/verify-certificate', [
            'certificate_id' => strtoupper($first), 'user_id' => $other->id,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('designer_name', 'Ирина Волкова')
            ->assertJsonPath('matched_certificate_id', $first)->assertJsonCount(2, 'certificates')->json();
        $this->assertEqualsCanonicalizing([$first, $second], array_column($data['certificates'], 'id'));
        $this->assertNotContains($unrelated, array_column($data['certificates'], 'id'));
        $this->assertEqualsCanonicalizing(['designer_name', 'matched_certificate_id', 'certificates'], array_keys($data));
        foreach ($data['certificates'] as $certificate) {
            $this->assertEqualsCanonicalizing(['id', 'course_title', 'discipline', 'issued_at'], array_keys($certificate));
        }
        $this->assertDatabaseCount('university_certificates', 3);
        $this->assertDatabaseCount('university_attempts', 0);
        // Public confirmation is separate from the original private document and dashboard.
        $this->getJson('/api/university/certificates/'.$first)->assertUnauthorized();
        $this->getJson('/api/university/dashboard')->assertUnauthorized();
    }

    public function test_public_verification_validates_id_and_distinguishes_unknown_certificate(): void
    {
        $this->postJson('/api/university/verify-certificate', [])->assertUnprocessable();
        $this->postJson('/api/university/verify-certificate', ['certificate_id' => ['bad']])->assertUnprocessable();
        $this->postJson('/api/university/verify-certificate', ['certificate_id' => 'design-planning'])->assertUnprocessable();
        $this->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])
            ->assertNotFound()->assertJsonMissingPath('designer_name')->assertJsonMissingPath('certificates');
        $this->getJson('/api/university/verify-certificate')->assertStatus(405);
    }

    public function test_public_verification_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])->assertNotFound();
        }
        $this->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_signed_verification_context_gives_each_visitor_an_independent_limit(): void
    {
        config(['forms.context_secret' => str_repeat('s', 40)]);
        $id = (string) Str::uuid();
        $sign = function (string $ip, int $age = 0) use ($id): string {
            $payload = rtrim(strtr(base64_encode(json_encode([
                'purpose' => 'university-verification', 'timestamp' => time() - $age,
                'client_ip' => $ip, 'certificate_id' => $id,
            ])), '+/', '-_'), '=');

            return $payload.'.'.hash_hmac('sha256', $payload, config('forms.context_secret'));
        };
        $this->withHeader('X-Leget-University-Context', $sign('203.0.113.10'));
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/university/verify-certificate', ['certificate_id' => $id])->assertNotFound();
        }
        $this->postJson('/api/university/verify-certificate', ['certificate_id' => $id])->assertStatus(429);
        $this->withHeader('X-Leget-University-Context', $sign('203.0.113.11'))
            ->postJson('/api/university/verify-certificate', ['certificate_id' => $id])->assertNotFound();
        $this->withHeader('X-Leget-University-Context', $sign('203.0.113.12', 301))
            ->postJson('/api/university/verify-certificate', ['certificate_id' => $id])->assertForbidden();
        $this->withHeader('X-Leget-University-Context', $sign('203.0.113.12').'tampered')
            ->postJson('/api/university/verify-certificate', ['certificate_id' => $id])->assertForbidden();
        $this->withHeader('X-Leget-University-Context', $sign('203.0.113.12'))
            ->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_spoofed_forwarding_does_not_change_public_verification_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withHeader('X-Forwarded-For', '203.0.113.'.$i)
                ->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])->assertNotFound();
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.99')
            ->postJson('/api/university/verify-certificate', ['certificate_id' => (string) Str::uuid()])->assertStatus(429);
    }

    private function issueCertificate(User $user, string $slug, string $name): string
    {
        $course = DB::table('university_courses')->where('slug', $slug)->first();
        $id = (string) Str::uuid();
        DB::table('university_certificates')->insert([
            'id' => $id, 'user_id' => $user->id, 'course_slug' => $slug,
            'student_name' => $name, 'course_title' => $course->title, 'discipline' => $course->discipline,
            'score' => 100, 'issued_at' => now(),
        ]);

        return $id;
    }

    public function test_learning_is_additive_but_never_grants_staff_client_permissions(): void
    {
        $client = $this->student(Role::Client);
        $this->assertFalse($client->hasAbility('university.study'));
        $this->actingAs($client, 'api')->postJson('/api/university/enroll')->assertOk();
        $student = $client->fresh();
        $this->assertTrue($student->hasAbility('university.study'));
        $this->assertTrue($student->hasAbility('promo.client'));
        $this->assertSame(Role::Client, $student->role);
        $student->forceFill(['role' => Role::Curator])->save();
        $this->assertTrue($student->hasAbility('university.study'));
        $this->assertFalse($student->hasAbility('cabinet.view'));
        $this->assertFalse($student->hasAbility('promo.client'));
        $this->actingAs($student, 'api')->postJson('/api/university/enroll')->assertOk();
    }

    private function student(Role $role = Role::Student): User
    {
        $user = User::create(['name' => 'Студент', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('secret-password')]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function payload(string $assessment): array
    {
        $curriculum = json_decode(DB::table('university_courses')->where('slug', 'design-planning')->value('curriculum'), true);
        $questions = $assessment === 'exam' ? $curriculum['exam'] : collect($curriculum['lessons'])->firstWhere('id', $assessment)['questions'];

        return ['assessment' => $assessment, 'request_key' => (string) Str::uuid(), 'answers' => array_column($questions, 'correct', 'id')];
    }
}
