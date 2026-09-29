<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * @method bool hasVerifiedEmail()
 * @method void markEmailAsVerified()
 */
class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Значения по умолчанию.
     *
     * Роль дублирует default колонки, чтобы у только что созданной модели она
     * была и до `fresh()`: клиента создаёт ClientAuthController, роль он не
     * передаёт (она не fillable), и без этого `$user->role` был бы null сразу
     * после register — ровно там, где формируется ответ с полем `role`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'client',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * `role` здесь быть не должно: массовое присваивание роли из тела запроса
     * означало бы «зарегистрируйся администратором».
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'region',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be appended.
     */
    protected $appends = [
        'email_verified',
    ];

    /**
     * Get the attributes that should be cast.
     */
    public function roleNames(): array
    {
        $role = $this->role ?? Role::Client;
        $roles = [$role->value];
        if ($this->university_enrolled_at !== null && ! in_array('student', $roles, true)) {
            $roles[] = 'student';
        }

        return $roles;
    }

    /** Preserve education when an authorized operation assigns the non-student role. */
    public function setPrimaryRole(Role $role): self
    {
        if ($this->role === Role::Student && $role !== Role::Student) {
            $this->forceFill(['university_enrolled_at' => $this->university_enrolled_at ?? now()]);
        }

        return $this->forceFill(['role' => $role]);
    }

    public function hasAbility(string $ability): bool
    {
        foreach ($this->roleNames() as $role) {
            if (Role::from($role)->can($ability)) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'university_enrolled_at' => 'datetime',
            'password' => 'hashed',
            // Каст в enum: неизвестное значение в колонке роняет гидрацию
            // ValueError'ом, а не тихо превращается в «роль, которой нет».
            'role' => Role::class,
        ];
    }

    /**
     * Заявка на партнёрство, она же профиль партнёра.
     *
     * Существует и у клиента: заявка подаётся до одобрения, и роль в этот
     * момент ещё `client`. Наличие профиля — не право на Офис, право даёт роль.
     */
    public function partnerProfile(): HasOne
    {
        return $this->hasOne(PartnerProfile::class);
    }

    /**
     * Получить кошелёк пользователя.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get email verification status as boolean.
     */
    public function getEmailVerifiedAttribute(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
            'email_verified' => $this->email_verified,
            // Claim — снимок на момент выдачи: после смены роли он врёт до
            // истечения TTL. Поэтому им можно рисовать шапку в SSR без запроса
            // к auth-сервису, но нельзя решать вопрос доступа — это делает
            // проверка роли в БД (`can:` и /api/session/me).
            'role' => ($this->role ?? Role::Client)->value,
            'roles' => $this->roleNames(),
        ];
    }
}
