<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PlatformSettingObserver;
use Database\Factories\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One platform-wide setting: `key` => JSON `value` (Req 9.3 / A9; design § Base URL
 * §U.2).
 *
 * Read it through `App\Services\Platform\PlatformSettings`, never directly — the
 * service is where the cache, the audit trail, and the typed accessors live. This model
 * exists so writes are ordinary Eloquent writes, which is what lets the observer hang
 * validation and invalidation off them no matter who does the writing (the admin screen
 * of task 32.6, a console command, a seeder, tinker).
 *
 * **Platform-owned, not tenant-owned.** No `tenant_id` and no `BelongsToTenant`: a
 * setting belongs to the deployment and is read by every tenant, like `plans`. Only a
 * platform admin may write one, which is enforced at the panel/RBAC boundary rather than
 * here — a model cannot know who is asking.
 *
 * @property string $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy([PlatformSettingObserver::class])]
class PlatformSetting extends Model
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `json` rather than `array`: a setting is as often a string or a flag as it is
        // a map, and this cast round-trips all three.
        return [
            'value' => 'json',
        ];
    }
}
