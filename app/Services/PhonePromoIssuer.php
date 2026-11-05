<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Uses the existing promo registry and its one-deal-per-code constraint. No HTTP or SMTP here. */
final class PhonePromoIssuer
{
    public function issue(string $phone, ?string $licenseId, string $requestId): object
    {
        $key = hash('sha256', ($licenseId ?? 'platform').':'.$phone);
        $existing = DB::table('promo_codes')->where('phone_issuance_key', $key)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $id = (string) Str::ulid();
            $code = 'LG-';
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            try {
                // A savepoint lets PostgreSQL/MySQL safely recover from a concurrent insert.
                DB::transaction(function () use ($id, $code, $key, $phone, $requestId) {
                    DB::table('promo_codes')->insert([
                        'id' => $id, 'code' => $code, 'client_id' => null, 'created_by' => null,
                        'client_phone' => $phone, 'phone_issuance_key' => $key,
                        'service_request_id' => $requestId,
                        'subject_type' => 'custom', 'subject_title' => 'Любая продукция или услуга',
                        'discount_type' => 'percent', 'discount_value' => '2.00',
                        'terms' => 'Скидка 2% на любую продукцию или услугу. Персональный промокод для одного заказа.',
                        'status' => 'activated', 'starts_at' => now(), 'activated_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    foreach (['created', 'activated'] as $action) {
                        DB::table('promo_code_events')->insert([
                            'id' => (string) Str::ulid(), 'action' => 'promo.'.$action,
                            'subject_type' => 'promo_code', 'subject_id' => $id, 'promo_code_id' => $id,
                            'from_status' => $action === 'activated' ? 'created' : null,
                            'to_status' => $action, 'actor_role' => 'system', 'created_at' => now(),
                        ]);
                    }
                });

                return DB::table('promo_codes')->where('id', $id)->first();
            } catch (UniqueConstraintViolationException $e) {
                // A simultaneous request for this phone wins; random code collision retries.
                $existing = DB::table('promo_codes')->where('phone_issuance_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }
        }
        throw new RuntimeException('Could not issue a unique promo code');
    }
}
