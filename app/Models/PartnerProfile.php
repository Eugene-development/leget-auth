<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка на партнёрство, она же профиль партнёра.
 *
 * `status` и `reviewed_*` не в `$fillable`: заявитель заполняет реквизиты,
 * а решение принимает разбор. Массово присвоить `status = approved` из формы
 * означало бы одобрить себя самому.
 */
class PartnerProfile extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'partner_type',
        'company',
        'inn',
        'website',
        'city',
        'comment',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'status' => PartnerStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
