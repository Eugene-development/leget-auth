<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Server-owned snapshots for intake. Client ranges and tenant IDs are never accepted. */
final class GrowthFormDetails
{
    public function resolve(array $data, ?object $site): array
    {
        $details = [];
        if (! empty($data['attribution'])) {
            $input = $this->decode($data['attribution'], 'attribution');
            $details['attribution'] = Validator::make(['attribution' => $input], [
                'attribution' => 'array:utm_source,utm_medium,utm_campaign,yclid,metrika_client_id',
                'attribution.utm_source' => 'sometimes|string|max:120',
                'attribution.utm_medium' => 'sometimes|string|max:120',
                'attribution.utm_campaign' => 'sometimes|string|max:120',
                'attribution.yclid' => 'sometimes|string|regex:/^[0-9]{1,128}$/',
                'attribution.metrika_client_id' => 'sometimes|string|regex:/^[0-9]{1,128}$/',
            ])->validate()['attribution'];
        }
        if (! in_array($data['service_type'], ['selection-estimate', 'kitchen-estimate'], true)) {
            return $details;
        }
        if (! $site) {
            throw ValidationException::withMessages(['source_url' => 'Не удалось определить сайт. Повторите отправку с его страницы.']);
        }
        if ($data['service_type'] === 'selection-estimate') {
            $row = DB::table('project_selections')->where('license_id', $site->id)->where('token', $data['selection_token'])
                ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
            if (! $row) {
                throw ValidationException::withMessages(['selection_token' => 'Подборка больше недоступна. Создайте новую.']);
            }
            $details['selection'] = ['title' => $row->title, 'token' => $row->token, 'items' => json_decode($row->item_refs, true, flags: JSON_THROW_ON_ERROR)];

            return $details;
        }
        $row = DB::table('kitchen_estimate_settings')->where('license_id', $site->id)->where('enabled', true)->first();
        if (! $row || $row->version !== $data['estimate_version']) {
            throw ValidationException::withMessages(['estimate_version' => 'Правила расчёта изменились. Рассчитайте стоимость ещё раз.']);
        }
        $rules = json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR);
        $input = Validator::make($this->decode($data['estimate_inputs'], 'estimate_inputs'), [
            'run_cm' => 'required|integer|min:50|max:3000',
            'layout' => ['required', Rule::in(['straight', 'l', 'u', 'island'])],
            'material' => ['required', Rule::in(array_column($rules['materials'], 'key'))],
            'equipment' => 'required|integer|min:0|max:10',
        ])->validate();
        $material = collect($rules['materials'])->firstWhere('key', $input['material']);
        $factor = $input['run_cm'] / 100 * $rules['layouts'][$input['layout']] * $material['multiplier'];
        $details['estimate'] = [
            'inputs' => $input, 'version' => $row->version, 'material_label' => $material['label'],
            'min' => (int) floor(($rules['base_min'] * $factor + $rules['equipment_min'] * $input['equipment']) / 100) * 100,
            'max' => (int) ceil(($rules['base_max'] * $factor + $rules['equipment_max'] * $input['equipment']) / 100) * 100,
            'currency' => 'RUB', 'note' => $rules['note'] ?? '',
        ];

        return $details;
    }

    private function decode(mixed $value, string $field): array
    {
        try {
            $decoded = is_array($value) ? $value : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([$field => 'Некорректные параметры.']);
        }
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'Некорректные параметры.']);
        }

        return $decoded;
    }
}
