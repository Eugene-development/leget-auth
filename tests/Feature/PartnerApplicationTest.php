<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\User;
use App\Services\FormMailDelivery;
use App\Services\PartnerCompanyLogo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        $this->drainFormMail();

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
        $this->drainFormMail();

        $this->as($user)->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(),
            'partner_type' => PartnerType::Assembler->value,
            'city' => 'Казань',
        ])->assertSuccessful();
        $this->drainFormMail();

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
        $this->drainFormMail();

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
        $this->drainFormMail();
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
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    public function test_submission_survives_smtp_failure_and_http_retry_then_mail_retry(): void
    {
        $user = $this->user('retry@example.test');
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP offline'));
        $body = ['submission_key' => (string) Str::uuid(), 'partner_type' => 'designer', 'company' => 'Практика', 'inn' => '123456789012', 'website' => 'https://example.test', 'city' => 'Казань', 'comment' => 'Подключите партнёра'];
        $first = $this->as($user)->postJson('/api/partner/apply', $body)->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $this->drainFormMail();
        $this->as($user)->postJson('/api/partner/apply', $body)->assertStatus(202)->assertJsonPath('id', $first->json('id'));
        $this->drainFormMail();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('partner_profiles', 1);
        $this->assertDatabaseHas('service_requests', ['id' => $first->json('id'), 'recipient_email' => 'info@novostroy.org', 'message' => 'Подключите партнёра']);
        $this->assertDatabaseHas('partner_profiles', ['user_id' => $user->id, 'company' => 'Практика', 'inn' => '123456789012', 'city' => 'Казань']);
        $changed = $body;
        $changed['city'] = 'Москва';
        $this->as($user)->postJson('/api/partner/apply', $changed)->assertStatus(422);
        $this->assertDatabaseHas('partner_profiles', ['user_id' => $user->id, 'city' => 'Казань']);
        Mail::shouldReceive('send')->once();
        DB::table('service_request_mail_deliveries')->where('service_request_id', $first->json('id'))->update(['retry_at' => now()->subMinute()]);
        app(FormMailDelivery::class)->deliver($first->json('id'));
        $this->assertDatabaseHas('service_requests', ['id' => $first->json('id'), 'mail_status' => 'sent', 'mail_attempts' => 2]);
    }

    public function test_staff_cannot_apply_and_role_input_is_ignored(): void
    {
        foreach ([Role::Admin, Role::Manager, Role::Curator] as $role) {
            $this->as($this->user($role->value.'@example.test', $role))->postJson('/api/partner/apply', ['submission_key' => (string) Str::uuid(), 'partner_type' => 'designer'])->assertForbidden();
        }
    }

    public function test_b2b_application_uses_the_trusted_site_and_retries_without_duplicate_mail(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->integer('template_id')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
        });
        $owner = $this->user('owner@example.test', Role::Admin);
        $user = $this->user('applicant@example.test');
        DB::table('licenses')->insert(['id' => 'promo1', 'domain' => 'b2b.example.test', 'user_id' => $owner->id]);
        $payload = [
            'form_id' => 'promo1-b2b-partner-registration', 'submission_key' => (string) Str::uuid(),
            'partner_type' => 'manufacturer', 'company' => 'Мебельная фабрика', 'consent' => '1',
            'source_url' => 'https://b2b.example.test/b2b', 'website' => 'https://factory.example.test',
            'city' => 'Казань', 'comment' => 'Производим фасады', 'inn' => '123456789012',
        ];

        $this->as($user)->postJson('/api/partner/apply', $payload)->assertNotFound();
        $this->withHeaders($this->signedFormContext('b2b.example.test'));
        $this->as($user)->postJson('/api/partner/apply', array_replace($payload, ['partner_type' => 'designer']))
            ->assertUnprocessable()->assertJsonValidationErrors('partner_type');
        $this->as($user)->postJson('/api/partner/apply', array_replace($payload, ['consent' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors('consent');

        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $id = $this->as($user)->postJson('/api/partner/apply', $payload)
            ->assertStatus(202)->assertJsonPath('mail_status', 'pending')->json('id');
        $this->drainFormMail();
        $this->as($user)->postJson('/api/partner/apply', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        $row = DB::table('service_requests')->where('id', $id)->first();
        $this->assertSame('promo1-b2b-partner-registration', $row->form_id);
        $this->assertSame('promo1', $row->license_id);
        $this->assertSame($payload['source_url'], $row->source_url);
        $this->assertSame($payload['comment'], $row->message);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'recipient_email' => $owner->email, 'attempts' => 1]);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('partner_profiles', 1);
        $this->assertSame(Role::Client, $user->fresh()->role);

        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        app(FormMailDelivery::class)->deliver($id);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
    }

    public function test_only_an_approved_company_partner_manages_its_own_card(): void
    {
        $partner = $this->user('company@example.test', Role::Partner);
        $application = $partner->partnerProfile()->create(['partner_type' => 'supplier', 'inn' => '000000000000', 'comment' => 'Private']);
        $other = $this->user('other@example.test', Role::Partner);
        $other->partnerProfile()->create(['partner_type' => 'designer'])->forceFill(['status' => PartnerStatus::Approved])->save();
        $url = '/api/partner/company-card';
        $values = ['name' => 'Новая компания', 'description' => 'Оптовые поставки', 'city' => 'Тверь'];

        $this->getJson($url)->assertUnauthorized();
        $this->as($partner)->putJson($url, ['action' => 'save'] + $values)->assertForbidden();
        $this->as($other)->getJson($url)->assertOk()->assertJsonPath('card', null);
        $this->as($other)->putJson($url, ['action' => 'save', 'id' => $application->id] + $values)->assertForbidden();
        $application->forceFill(['status' => PartnerStatus::Approved])->save();

        $this->as($partner)->putJson($url, ['action' => 'save'] + $values)
            ->assertOk()->assertJsonPath('card.published', false)->assertJsonMissingPath('card.inn');
        $this->as($partner)->putJson($url, ['action' => 'publish'] + $values)
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        $this->as($partner)->putJson($url, ['action' => 'publish', 'email' => 'office@example.test', 'website' => 'file:///example'] + $values)
            ->assertUnprocessable()->assertJsonValidationErrors('website');
        $this->as($partner)->putJson($url, ['action' => 'publish', 'email' => 'office@example.test'] + $values)
            ->assertOk()->assertJsonPath('card.published', true)->assertJsonMissingPath('card.comment');
        $this->as($partner)->putJson($url, ['action' => 'save'] + $values)->assertConflict();
        $this->as($partner)->putJson($url, ['action' => 'unpublish'])
            ->assertOk()->assertJsonPath('card.published', false)->assertJsonPath('card.email', 'office@example.test');
        $this->as($partner)->getJson($url)
            ->assertOk()->assertJsonPath('card.id', $application->id)->assertJsonPath('card.name', 'Новая компания');
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_company_logo_can_be_replaced_and_removed_without_changing_the_card(): void
    {
        $disk = Storage::fake(PartnerCompanyLogo::DISK);
        $partner = $this->user('logo-owner@example.test', Role::Partner);
        $profile = $partner->partnerProfile()->create(['partner_type' => 'supplier']);
        $profile->forceFill([
            'status' => PartnerStatus::Approved, 'directory_name' => 'Фабрика',
            'directory_card' => ['description' => 'Описание', 'email' => 'office@example.test'],
            'directory_published_at' => now(),
        ])->save();
        $other = $this->user('other-logo@example.test', Role::Partner);
        $other->partnerProfile()->create(['partner_type' => 'designer'])
            ->forceFill(['status' => PartnerStatus::Approved])->save();
        $url = '/api/partner/company-card/logo';
        $logo = fn () => UploadedFile::fake()->createWithContent(
            'company.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jQ2kAAAAASUVORK5CYII='),
        );

        $this->postJson($url, ['logo' => $logo()])->assertUnauthorized();
        $this->as($other)->postJson($url, ['logo' => $logo()])->assertForbidden();
        $this->as($partner)->postJson($url, ['logo' => UploadedFile::fake()->createWithContent('company.svg', '<svg/>')])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->assertSame([], $disk->allFiles());

        $logoUrl = $this->as($partner)->postJson($url, ['logo' => $logo()])->assertOk()->json('logoUrl');
        $firstKey = $profile->fresh()->directory_card['logoKey'];
        $disk->assertExists($firstKey);
        $this->assertStringStartsWith('partner-logos/'.$profile->id.'/', $firstKey);
        $this->assertStringEndsWith('/'.$firstKey, $logoUrl);

        $values = ['action' => 'publish', 'name' => 'Фабрика с обновлением', 'description' => 'Обновили описание', 'email' => 'office@example.test'];
        $this->as($partner)->putJson('/api/partner/company-card', $values + ['logoUrl' => 'https://example.test/foreign-logo.png'])
            ->assertOk()->assertJsonPath('card.logoUrl', $logoUrl)->assertJsonMissingPath('card.logoKey');
        $this->as($partner)->postJson($url, ['logo' => $logo()])->assertOk();
        $newKey = $profile->fresh()->directory_card['logoKey'];
        $this->assertNotSame($firstKey, $newKey);
        $disk->assertMissing($firstKey);
        $disk->assertExists($newKey);

        $this->as($other)->deleteJson($url)->assertForbidden();
        $disk->assertExists($newKey);
        $this->as($partner)->deleteJson($url)->assertOk()->assertJsonPath('logoUrl', null);
        $disk->assertMissing($newKey);
        $this->as($partner)->getJson('/api/partner/company-card')->assertOk()
            ->assertJsonPath('card.logoUrl', null)->assertJsonPath('card.published', true)
            ->assertJsonPath('card.description', 'Обновили описание');
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
