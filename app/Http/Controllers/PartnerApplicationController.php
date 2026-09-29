<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Services\FormMailDelivery;
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

        $validated = $request->validate([
            'submission_key' => 'required|uuid',
            'partner_type' => ['required', 'string', Rule::in(PartnerType::values())],
            'company' => 'nullable|string|max:255',
            'inn' => 'nullable|string|max:12',
            'website' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'comment' => 'nullable|string|max:2000',
        ]);

        $submissionKey = $validated['submission_key'];
        unset($validated['submission_key']);

        [$profile, $submission] = DB::transaction(function () use ($user, $validated, $request, $submissionKey) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, [Role::Client, Role::Student, Role::Partner], true), 403);
            $profile = $user->partnerProfile;

            if ($profile instanceof PartnerProfile) {
                $profile->fill($validated);

                // Повторная подача после отказа снова уходит в разбор. Одобренную
                // заявку правки реквизитов не роняют обратно в очередь: партнёр
                // уже работает, и лишать его Офиса из-за нового адреса сайта нельзя.
                if ($profile->status === PartnerStatus::Rejected) {
                    $profile->status = PartnerStatus::Pending;
                    $profile->reviewed_by = null;
                    $profile->reviewed_at = null;
                    $profile->review_note = null;
                }

                $profile->save();
            } else {
                $profile = $user->partnerProfile()->create($validated);
            }

            $submission = app(FormSubmissionService::class)->accept([
                'service_type' => 'partner-application', 'form_id' => 'platform-partner-application',
                'submission_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'partner:'.$user->id.':'.$submissionKey),
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'message' => $validated['comment'] ?? null,
                'partner_profile_id' => $profile->id,
            ] + array_diff_key($validated, ['comment' => true]), $request);

            return [$profile, $submission];
        });
        app(FormMailDelivery::class)->deliver($submission->id);

        $mail = DB::table('service_requests')->where('id', $submission->id)->first();

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
