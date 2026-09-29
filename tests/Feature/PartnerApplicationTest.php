<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\User;
use App\Services\FormMailDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FormTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PartnerApplicationTest extends FormTestCase
{
    use RefreshDatabase;

    /**
     * Заявку подаёт КЛИЕНТ — партнёром он станет только после разбора.
     * Если бы маршрут требовал `can:partner.cabinet`, он был бы закрыт ровно
     * для тех, ради кого существует.
     */
    public function test_client_applies_and_stays_a_client(): void
    {
        $user = $this->user('ivan@example.test');

        $this->as($user)
            ->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
                'partner_type' => PartnerType::Manufacturer->value,
                'company' => 'ООО Мебельщик',
                'inn' => '7701234567',
                'city' => 'Москва',
            ])
            ->assertSuccessful()
            ->assertJsonPath('application.status', PartnerStatus::Pending->value)
            ->assertJsonPath('application.partner_type', PartnerType::Manufacturer->value)
            ->assertJsonPath('application.partner_type_label', 'Производитель');

        // Роль не изменилась: форма не выдаёт прав.
        $this->assertSame(Role::Client, $user->fresh()->role);
        $this->assertDatabaseHas('partner_profiles', [
            'user_id' => $user->id,
            'status' => PartnerStatus::Pending->value,
            'company' => 'ООО Мебельщик',
        ]);
    }

    public function test_unknown_partner_type_is_rejected(): void
    {
        $this->as($this->user('ivan@example.test'))
            ->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(), 'partner_type' => 'astronaut'])
            ->assertStatus(422);
    }

    public function test_guest_cannot_apply(): void
    {
        $this->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Designer->value,
        ])->assertUnauthorized();
    }

    /**
     * Сотрудник платформы и её партнёр — разные стороны сделки; совмещение
     * лишило бы разбор заявок смысла.
     */
    public function test_superadmin_cannot_apply(): void
    {
        $this->as($this->user('boss@example.test', Role::Superadmin))
            ->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
                'partner_type' => PartnerType::Supplier->value,
            ])
            ->assertForbidden();
    }

    /**
     * Повторная подача правит существующую заявку, а не плодит вторую:
     * иначе разбирать пришлось бы каждую.
     */
    public function test_second_application_updates_the_first(): void
    {
        $user = $this->user('ivan@example.test');

        $this->as($user)->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Designer->value,
            'city' => 'Москва',
        ])->assertSuccessful();

        $this->as($user)->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Assembler->value,
            'city' => 'Казань',
        ])->assertSuccessful();

        $this->assertSame(1, $user->fresh()->partnerProfile()->count());
        $this->assertDatabaseHas('partner_profiles', [
            'user_id' => $user->id,
            'partner_type' => PartnerType::Assembler->value,
            'city' => 'Казань',
        ]);
    }

    /**
     * Правка реквизитов одобренным партнёром не роняет его обратно в очередь:
     * он уже работает, и лишать его Офиса из-за нового адреса сайта нельзя.
     */
    public function test_approved_application_keeps_its_status(): void
    {
        $user = $this->user('partner@example.test', Role::Partner);
        $user->partnerProfile()->create(['partner_type' => PartnerType::Supplier->value]);
        $user->partnerProfile->forceFill(['status' => PartnerStatus::Approved])->save();

        $this->as($user)->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Supplier->value,
            'website' => 'https://example.test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('partner_profiles', [
            'user_id' => $user->id,
            'status' => PartnerStatus::Approved->value,
            'website' => 'https://example.test',
        ]);
    }

    public function test_rejected_application_returns_to_review(): void
    {
        $user = $this->user('ivan@example.test');
        $user->partnerProfile()->create(['partner_type' => PartnerType::Designer->value]);
        $user->partnerProfile->forceFill([
            'status' => PartnerStatus::Rejected,
            'review_note' => 'Нет реквизитов',
        ])->save();

        $this->as($user)->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Designer->value,
            'company' => 'ИП Иванов',
        ])->assertSuccessful()
            ->assertJsonPath('application.status', PartnerStatus::Pending->value)
            ->assertJsonPath('application.review_note', null);
    }

    public function test_own_application_is_readable(): void
    {
        $user = $this->user('ivan@example.test');

        $this->as($user)->getJson('/api/partner/application')
            ->assertOk()
            ->assertJsonPath('application', null);

        $user->partnerProfile()->create(['partner_type' => PartnerType::Designer->value]);

        $this->as($user->fresh())->getJson('/api/partner/application')
            ->assertOk()
            ->assertJsonPath('application.status', PartnerStatus::Pending->value)
            ->assertJsonPath('application.status_label', 'На рассмотрении');
    }

    private function as(User $user): self
    {
        // Guard и singleton JWTAuth кэшируют разобранный токен, поэтому каждый
        // запрос в тесте ставит заголовок заново, а гвардов сбрасываем.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    public function test_submission_survives_smtp_failure_and_http_retry_then_mail_retry(): void
    {
        $user = $this->user('retry@example.test');
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP offline'));
        $body = ['submission_key' => (string) Str::uuid(), 'partner_type' => 'designer', 'company' => 'Практика', 'inn' => '123456789012', 'website' => 'https://example.test', 'city' => 'Казань', 'comment' => 'Подключите партнёра'];
        $first = $this->as($user)->postJson('/api/partner/apply', $body)->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $this->as($user)->postJson('/api/partner/apply', $body)->assertStatus(202)->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('partner_profiles', 1);
        $this->assertDatabaseHas('service_requests', ['id' => $first->json('id'), 'recipient_email' => 'info@novostroy.org', 'message' => 'Подключите партнёра']);
        $this->assertDatabaseHas('partner_profiles', ['user_id' => $user->id, 'company' => 'Практика', 'inn' => '123456789012', 'city' => 'Казань']);
        $changed = $body;
        $changed['city'] = 'Москва';
        $this->as($user)->postJson('/api/partner/apply', $changed)->assertStatus(422);
        $this->assertDatabaseHas('partner_profiles', ['user_id' => $user->id, 'city' => 'Казань']);
        Mail::shouldReceive('send')->once();
        DB::table('service_requests')->where('id', $first->json('id'))->update(['mail_retry_at' => now()->subMinute()]);
        app(FormMailDelivery::class)->deliver($first->json('id'));
        $this->assertDatabaseHas('service_requests', ['id' => $first->json('id'), 'mail_status' => 'sent', 'mail_attempts' => 2]);
    }

    public function test_staff_cannot_apply_and_role_input_is_ignored(): void
    {
        foreach ([Role::Admin, Role::Manager, Role::Curator] as $role) {
            $this->as($this->user($role->value.'@example.test', $role))->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(), 'partner_type' => 'designer'])->assertForbidden();
        }
    }

    private function user(string $email, Role $role = Role::Client): User
    {
        $user = User::query()->create([
            'name' => 'Тест',
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);

        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
