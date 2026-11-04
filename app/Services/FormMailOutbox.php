<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable per-recipient deliveries; the parent remains the CRM receipt. */
final class FormMailOutbox
{
    public const TABLE = 'service_request_mail_deliveries';

    /** Must run inside the transaction accepting the submission. */
    public function enqueue(object $request): void
    {
        if ($override = config('forms.recipient_override')) {
            $this->insert($request->id, 'override', 'override', null, $override);
        } elseif ($request->license_id) {
            $managers = DB::table('crm_memberships')->join('users', 'users.id', '=', 'crm_memberships.user_id')
                ->where('license_id', $request->license_id)->where('status', 'approved')
                ->where('users.role', 'manager')->orderBy('users.id')->get(['users.id', 'users.email']);
            foreach ($managers as $manager) {
                $this->insert($request->id, 'manager:'.$manager->id, 'manager', $manager->id, $manager->email);
            }
            if ($managers->isEmpty()) {
                $this->owner($request);
            }
        } else {
            $this->insert($request->id, 'admin', 'admin', null, config('forms.recipient'));
        }
        // Kept for old readers only; deliveries are the authoritative recipient list.
        DB::table('service_requests')->where('id', $request->id)->update([
            'recipient_email' => DB::table(self::TABLE)->where('service_request_id', $request->id)->orderBy('id')->value('recipient_email'),
        ]);
    }

    private function insert(string $id, string $key, string $kind, ?int $user, ?string $email): void
    {
        // Parent-row serialization and the unique key protect fallback creation and HTTP retries.
        if (! DB::table(self::TABLE)->where('service_request_id', $id)->where('recipient_key', $key)->exists()) {
            DB::table(self::TABLE)->insert([
                'id' => (string) Str::ulid(), 'service_request_id' => $id, 'recipient_key' => $key,
                'kind' => $kind, 'user_id' => $user, 'recipient_email' => $email,
                'status' => 'pending', 'retry_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function owner(object $request): void
    {
        $owner = DB::table('licenses')->join('users', 'users.id', '=', 'licenses.user_id')
            ->where('licenses.id', $request->license_id)->first(['users.id', 'users.email']);
        $this->insert($request->id, 'owner', 'owner', $owner?->id, $owner?->email);
    }

    private function due(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where(fn ($p) => $p->where('status', 'pending')->where('retry_at', '<=', now()))
                ->orWhere(fn ($s) => $s->where('status', 'sending')->where('locked_at', '<=', now()->subMinutes(10)));
        });
    }

    public function claim(?string $requestId = null): ?object
    {
        $candidate = $this->due(DB::table(self::TABLE))
            ->when($requestId, fn ($q) => $q->where('service_request_id', $requestId))
            ->orderBy('retry_at')->orderBy('id')->first();
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($candidate) {
            // All delivery mutations lock parent -> child, including completion and operator retry.
            $parent = DB::table('service_requests')->where('id', $candidate->service_request_id)->lockForUpdate()->first();
            $row = $this->due(DB::table(self::TABLE)->where('id', $candidate->id))->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            if ($row->status === 'sending' && $row->attempts >= config('forms.max_attempts')) {
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status' => 'failed', 'retry_at' => null, 'locked_at' => null, 'lock_token' => null,
                    'error_code' => 'InterruptedDelivery', 'updated_at' => now(),
                ]);
                $this->summarize($parent);

                // A recovery consumed one unit of work even though there is no SMTP attempt.
                return (object) ['recovered' => true];
            }
            $row->lock_token = (string) Str::uuid();
            $row->attempts++;
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'sending', 'attempts' => $row->attempts, 'locked_at' => now(),
                'lock_token' => $row->lock_token, 'updated_at' => now(),
            ]);
            $this->summarize($parent);

            return $row;
        }, 3);
    }

    /** Null means revoked; an absent/invalid address is retriable, never an intake error. */
    public function recipient(object $delivery): ?string
    {
        $parent = DB::table('service_requests')->where('id', $delivery->service_request_id)->first();
        $userId = $delivery->user_id;
        if ($delivery->kind === 'manager') {
            $user = DB::table('crm_memberships')->join('users', 'users.id', '=', 'crm_memberships.user_id')
                ->where('license_id', $parent->license_id)->where('user_id', $delivery->user_id)
                ->where('status', 'approved')->where('users.role', 'manager')->first(['users.email']);
            if (! $user) {
                return null;
            }
            $email = $user->email;
        } elseif ($delivery->kind === 'owner') {
            $owner = DB::table('licenses')->join('users', 'users.id', '=', 'licenses.user_id')
                ->where('licenses.id', $parent->license_id)->first(['users.id', 'users.email']);
            $email = $owner?->email;
            $userId = $owner?->id;
        } elseif ($delivery->kind === 'admin') {
            $email = config('forms.recipient');
        } else {
            // Overrides and migrated deliveries retain their explicitly fixed destination.
            $email = $delivery->recipient_email;
        }
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('FormRecipientInvalid');
        }
        // A stale worker must not send after another process has reclaimed its lease.
        DB::table(self::TABLE)->where('id', $delivery->id)->where('status', 'sending')
            ->where('lock_token', $delivery->lock_token)->update(['recipient_email' => $email, 'user_id' => $userId]);
        if (! DB::table(self::TABLE)->where('id', $delivery->id)->where('status', 'sending')->where('lock_token', $delivery->lock_token)->exists()) {
            return null;
        }

        return $email;
    }

    public function finish(object $delivery, string $status, ?string $error = null): void
    {
        DB::transaction(function () use ($delivery, $status, $error) {
            $parent = DB::table('service_requests')->where('id', $delivery->service_request_id)->lockForUpdate()->first();
            $changed = DB::table(self::TABLE)->where('id', $delivery->id)->where('status', 'sending')
                ->where('lock_token', $delivery->lock_token)->update([
                    'status' => $status, 'sent_at' => $status === 'sent' ? now() : null,
                    'retry_at' => $status === 'pending' ? now()->addMinutes(min(60, 2 ** min($delivery->attempts, 6))) : null,
                    'locked_at' => null, 'lock_token' => null, 'error_code' => $error, 'updated_at' => now(),
                ]);
            if ($changed) {
                $this->summarize($parent);
            }
        }, 3);
    }

    /** Called only with the parent row locked; mail state does not change CRM's edit version. */
    private function summarize(object $parent): void
    {
        $rows = DB::table(self::TABLE)->where('service_request_id', $parent->id)->get();
        if ($rows->every(fn ($r) => $r->status === 'skipped') && $parent->license_id) {
            $this->owner($parent);
            $rows = DB::table(self::TABLE)->where('service_request_id', $parent->id)->get();
        }
        $status = $rows->contains('status', 'sending') ? 'sending'
            : ($rows->contains('status', 'pending') ? 'pending'
                : ($rows->contains('status', 'failed') ? 'failed' : 'sent'));
        DB::table('service_requests')->where('id', $parent->id)->update([
            'mail_status' => $status, 'mail_attempts' => $rows->sum('attempts'),
            'mail_sent_at' => $status === 'sent' ? $rows->max('sent_at') : null,
            'mail_retry_at' => $rows->where('status', 'pending')->min('retry_at'),
            'mail_locked_at' => $rows->where('status', 'sending')->min('locked_at'),
            'mail_error' => $rows->first(fn ($r) => in_array($r->status, ['pending', 'failed'], true) && $r->error_code)?->error_code,
            'updated_at' => now(),
        ]);
    }

    public function retry(string $id): void
    {
        DB::transaction(function () use ($id) {
            $parent = DB::table('service_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $parent || in_array($parent->mail_status, ['sent', 'legacy'], true)) {
                return;
            }
            if (! DB::table(self::TABLE)->where('service_request_id', $id)->exists()) {
                $this->insert($id, 'legacy', 'legacy', null, $parent->recipient_email);
                DB::table(self::TABLE)->where('service_request_id', $id)->update(['attempts' => $parent->mail_attempts]);
            }
            DB::table(self::TABLE)->where('service_request_id', $id)->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'pending', 'retry_at' => now(), 'updated_at' => now()]);
            $this->summarize($parent);
        }, 3);
    }
}
