<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Services\FormSubmissionService;
use App\Services\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Заявка на партнёрство от вошедшего пользователя.
 *
 * Маршрут требует сессии, а не пары «регистрация + заявка» в одном запросе:
 * регистрация уже есть и своя (`/api/client/register`), и дублировать её здесь
 * значило бы завести второй способ создавать пользователей — с собственной
 * капчей, лимитом и правилами пароля, которые разъедутся с первым.
 * Гостя на странице `/partnership` сначала регистрирует leget-main, потом
 * шлёт заявку сюда: последовательность оркеструет тот, у кого есть сессия.
 *
 * Роль при подаче НЕ меняется — человек остаётся клиентом. Партнёром его
 * делает разбор заявки в панели платформы; форма права не выдаёт.
 */
final class PartnerApplicationController extends Controller
{
    public function apply(Request $request, UserRole $roles)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не аутентифицирован.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Сотрудник платформы не может быть её партнёром: это разные стороны
        // сделки, и совмещение сломало бы смысл разбора заявок.
        if (! in_array($user->role, [Role::Client, Role::Student, Role::Partner], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Для партнёрства используйте отдельный клиентский аккаунт.',
            ], Response::HTTP_FORBIDDEN);
        }

        $b2b = $request->input('form_id') === 'promo1-b2b-partner-registration';
        if ($b2b) {
            $site = $request->attributes->get('crm_site');
            abort_unless((int) ($site->template_id ?? 0) === 1
                && $site->is_active && $site->status !== 'suspended' && empty($site->deleted_at), 404);
        }

        $validated = $request->validate([
            'submission_key' => 'required|uuid',
            'partner_type' => ['required', 'string', Rule::in($b2b ? PartnerType::directoryValues() : PartnerType::values())],
            'company' => [$b2b ? 'required' : 'nullable', 'string', 'max:255'],
            'inn' => 'nullable|string|max:12',
            'website' => ['nullable', $b2b ? 'url:http,https' : 'string', 'max:255'],
            'city' => 'nullable|string|max:100',
            'comment' => 'nullable|string|max:2000',
        ] + ($b2b ? [
            'consent' => 'required|accepted',
            'source_url' => 'nullable|url:http,https|max:500',
        ] : []));

        $submissionKey = $validated['submission_key'];
        $intake = $b2b ? [
            'form_id' => 'promo1-b2b-partner-registration',
            'form_title' => 'Регистрация партнёра B2B',
            'consent' => true,
            'source_url' => $validated['source_url'] ?? null,
        ] : ['form_id' => 'platform-partner-application'];
        unset($validated['submission_key'], $validated['consent'], $validated['source_url']);

        [$profile, $submission] = DB::transaction(function () use ($user, $validated, $request, $submissionKey, $intake) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, [Role::Client, Role::Student, Role::Partner], true), 403);
            $profile = $user->partnerProfile()->lockForUpdate()->first()
                ?? $user->partnerProfile()->create($validated);
            $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'partner:'.$user->id.':'.$submissionKey);
            $retry = DB::table('service_requests')->where('submission_key', $key)->exists();

            $submission = app(FormSubmissionService::class)->accept($intake + [
                'service_type' => 'partner-application',
                'submission_key' => $key,
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'message' => $validated['comment'] ?? null,
                'partner_profile_id' => $profile->id,
            ] + array_diff_key($validated, ['comment' => true]), $request);

            if (! $retry) {
                $profile->fill($validated);
                if ($profile->isDirty('partner_type')) {
                    $profile->directory_published_at = null;
                }
                if ($profile->status === PartnerStatus::Rejected) {
                    $profile->status = PartnerStatus::Pending;
                    $profile->reviewed_by = null;
                    $profile->reviewed_at = null;
                    $profile->review_note = null;
                }
                $profile->save();
            }

            return [$profile, $submission];
        });

        $mail = $submission;

        return response()->json([
            'success' => true,
            'id' => $submission->id,
            'status' => $mail->status,
            'mail_status' => $mail->mail_status,
            'application' => $this->present($profile->fresh()),
            'message' => $profile->status === PartnerStatus::Approved
                ? 'Данные партнёра обновлены.'
                : 'Заявка принята. Мы свяжемся с вами после рассмотрения.',
        ], $mail->mail_status === 'sent' ? 200 : 202);
    }

    /**
     * Своя заявка — чтобы страница могла показать «на рассмотрении» вместо
     * пустой формы, которую человек заполнит второй раз.
     */
    public function mine(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не аутентифицирован.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $user->partnerProfile;

        return response()->json([
            'success' => true,
            'application' => $profile instanceof PartnerProfile ? $this->present($profile) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PartnerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'partner_type' => $profile->partner_type->value,
            'partner_type_label' => $profile->partner_type->label(),
            'status' => $profile->status->value,
            'status_label' => $profile->status->label(),
            'company' => $profile->company,
            'inn' => $profile->inn,
            'website' => $profile->website,
            'city' => $profile->city,
            'comment' => $profile->comment,
            'created_at' => $profile->created_at?->toIso8601String(),
            'reviewed_at' => $profile->reviewed_at?->toIso8601String(),
            'review_note' => $profile->review_note,
        ];
    }
}
