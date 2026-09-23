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
            ->assertCreated()->assertJsonPath('user.role', 'student');
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
        $this->actingAs($user, 'api')->postJson('/api/university/enroll')->assertForbidden();
        $this->assertSame(Role::Partner, $user->fresh()->role);
        $user = $this->student(Role::Client);
        $this->actingAs($user, 'api')->postJson('/api/university/enroll')->assertOk();
        $this->assertSame(Role::Student, $user->fresh()->role);
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

        return ['assessment' => $assessment, 'request_key' => (string) Str::uuid(), 'answers' => array_column($questions,'correct','id')];
    }
}
