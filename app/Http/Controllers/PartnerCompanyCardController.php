<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Services\PartnerCompanyLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PartnerCompanyCardController extends Controller
{
    private const FIELDS = ['description', 'city', 'address', 'phone', 'email', 'website'];
    private const LOGO_FIELDS = ['logoKey', 'logoUrl'];

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->partnerProfile;

        return response()->json([
            'card' => $this->eligible($request->user(), $profile) ? $this->present($profile) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['save', 'publish', 'unpublish'])]])['action'];
        $publishing = $action === 'publish';
        $required = $publishing ? 'required' : 'nullable';
        $fields = $action === 'unpublish' ? [] : $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string', 'max:6000'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => [$publishing ? 'required_without:email' : 'sometimes', 'nullable', 'string', 'regex:/^\+?[0-9() .-]{7,32}$/', function ($attribute, $value, $fail) {
                $length = strlen(preg_replace('/\D/', '', $value));
                if ($length < 7 || $length > 15) {
                    $fail('Введите корректный рабочий телефон.');
                }
            }],
            'email' => [$publishing ? 'required_without:phone' : 'sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $card = DB::transaction(function () use ($request, $action, $fields, $publishing): array {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $profile = $user->partnerProfile()->lockForUpdate()->first();
            abort_unless($this->eligible($user, $profile), 403, 'Публикация доступна одобренным производителям и поставщикам.');

            if ($action !== 'unpublish') {
                abort_if($action === 'save' && $profile->directory_published_at, 409, 'Карточка уже опубликована. Обновите её или снимите с публикации.');
                $profile->directory_name = $fields['name'] ?? null;
                $profile->directory_card = Arr::only($fields, self::FIELDS)
                    + Arr::only($profile->directory_card ?? [], self::LOGO_FIELDS);
            }
            $profile->directory_published_at = $publishing ? ($profile->directory_published_at ?? now()) : null;
            $profile->save();

            return $this->present($profile);
        });

        return response()->json(['card' => $card])->header('Cache-Control', 'private, no-store');
    }

    public function uploadLogo(Request $request, PartnerCompanyLogo $logos): JsonResponse
    {
        $user = $request->user();
        $profile = $user->partnerProfile;
        abort_unless($this->eligible($user, $profile), 403);

        $file = $request->validate([
            'logo' => ['bail', 'required', 'mimetypes:image/jpeg,image/png,image/webp', File::image()
                ->max(PartnerCompanyLogo::MAX_KILOBYTES)
                ->dimensions(Rule::dimensions()->minWidth(1)->minHeight(1)->maxWidth(1024)->maxHeight(1024))],
        ])['logo'];

        try {
            $newLogo = $logos->store($profile->id, $file);
        } catch (\Throwable $error) {
            report($error);
            abort(503, 'Не удалось загрузить логотип. Попробуйте ещё раз.');
        }

        try {
            [$id, $oldKey] = $this->replaceLogo($user, $newLogo, $profile->id);
        } catch (HttpExceptionInterface $error) {
            $logos->remove($profile->id, $newLogo['logoKey']);
            throw $error;
        }
        $logos->remove($id, $oldKey);

        return response()->json(['logoUrl' => $newLogo['logoUrl']])->header('Cache-Control', 'private, no-store');
    }

    public function removeLogo(Request $request, PartnerCompanyLogo $logos): JsonResponse
    {
        [$id, $oldKey] = $this->replaceLogo($request->user());
        $logos->remove($id, $oldKey);

        return response()->json(['logoUrl' => null])->header('Cache-Control', 'private, no-store');
    }

    /** @return array{string, ?string} */
    private function replaceLogo(User $user, array $logo = [], ?string $expectedId = null): array
    {
        return DB::transaction(function () use ($user, $logo, $expectedId): array {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $user->partnerProfile()->lockForUpdate()->first();
            abort_unless($this->eligible($user, $profile) && ($expectedId === null || $profile->id === $expectedId), 403);

            $card = $profile->directory_card ?? [];
            $oldKey = $card['logoKey'] ?? null;
            $profile->directory_card = $logo + Arr::except($card, self::LOGO_FIELDS);
            $profile->save();

            return [$profile->id, $oldKey];
        });
    }

    private function eligible(User $user, ?PartnerProfile $profile): bool
    {
        return $user->role === Role::Partner && $profile?->status === PartnerStatus::Approved
            && in_array($profile->partner_type->value, PartnerType::directoryValues(), true);
    }

    private function present(PartnerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'type' => $profile->partner_type->value,
            'typeLabel' => $profile->partner_type->label(),
            'name' => $profile->directory_name,
            'published' => $profile->directory_published_at !== null,
            'logoUrl' => $profile->directory_card['logoUrl'] ?? null,
        ] + Arr::only($profile->directory_card ?? [], self::FIELDS);
    }
}
