<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RequestPasswordRecovery;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordRecovery
{
    public function __construct(private PasswordRecoveryToken $tokens) {}

    public function sendLink(string $email): void
    {
        RequestPasswordRecovery::dispatch($email);
    }

    public function issueLink(string $email): void
    {
        $id = $this->resolveUserId($email);
        if ($id === null) {
            return;
        }

        DB::transaction(function () use ($id, $email): void {
            // Serialize link issuance and consumption on the account, not an absent token row.
            $user = $this->lockUser($id, $email);
            if ($user === null) {
                return;
            }

            Password::broker('users')->sendResetLink(['email' => $user->email]);
        });
    }

    public function reset(#[\SensitiveParameter] array $credentials): bool
    {
        $credentials['email'] = strtolower(trim($credentials['email']));
        $token = $this->tokens->verify($credentials['email'], $credentials['token']);
        if ($token === null) {
            return false;
        }
        $credentials['token'] = $token;
        $id = $this->resolveUserId($credentials['email']);
        if ($id === null) {
            return false;
        }

        return DB::transaction(function () use ($id, $credentials): bool {
            $user = $this->lockUser($id, $credentials['email']);
            if ($user === null) {
                return false;
            }

            $credentials['email'] = $user->email;

            $status = Password::broker('users')->reset($credentials, function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'token_version' => $user->token_version + 1,
                ])->save();

                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                DB::afterCommit(static function () use ($user): void {
                    try {
                        event(new PasswordReset($user));
                    } catch (\Throwable $e) {
                        // Listener failure cannot turn a committed password reset into a retry.
                        Log::error('Password reset event listener failed.', ['exception' => $e::class]);
                    }
                });
            });

            return $status === Password::PASSWORD_RESET;
        });
    }

    private function resolveUserId(string $email): ?int
    {
        // LOWER(email) cannot use the email index reliably. Lock by the primary key
        // after resolving case instead of locking every scanned account in MySQL.
        // Keep this consistent read outside the transaction: a MySQL repeatable-read
        // snapshot established before waiting for the lock could see a consumed token.
        $id = User::query()->whereRaw('LOWER(email) = ?', [$email])->value('id');

        return $id === null ? null : (int) $id;
    }

    private function lockUser(int $id, string $email): ?User
    {
        $user = User::query()->lockForUpdate()->find($id);

        return $user !== null && strtolower($user->email) === $email ? $user : null;
    }
}
