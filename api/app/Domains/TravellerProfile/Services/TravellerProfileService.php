<?php

declare(strict_types=1);

namespace App\Domains\TravellerProfile\Services;

use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Identity\Models\SocialIdentity;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\Money;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TravellerProfileService
{
    public function forActor(Actor $actor): TravellerProfile
    {
        $existing = TravellerProfile::with('interests')->ownedBy($actor)->first();

        if ($existing !== null) {
            return $existing;
        }

        return TravellerProfile::create($actor->ownerAttributes())->load('interests');
    }

    public function update(Actor $actor, array $data): TravellerProfile
    {
        $profile = $this->forActor($actor);

        return DB::transaction(function () use ($profile, $data) {
            $profile->fill(array_intersect_key($data, array_flip([
                'display_name', 'travel_pace', 'walking_tolerance', 'budget_level',
                'daily_experience_budget_minor', 'currency', 'iconic_vs_local',
                'food_adventurousness', 'prefers_private_tours', 'accessibility', 'languages',
            ])))->save();

            foreach ($data['interests'] ?? [] as $interest => $weight) {
                if (! in_array($interest, config('experience.interests'), true)) {
                    continue;
                }

                $profile->interests()->updateOrCreate(
                    ['interest' => $interest],
                    ['weight' => (int) $weight, 'source' => 'explicit'],
                );
            }

            return $profile->fresh('interests');
        });
    }

    /**
     * Nudge the learned part of the profile from what the traveller actually did
     * (spec s20). Explicit answers are never overwritten — they are the
     * traveller's own statement of preference.
     */
    public function learnFromBehaviour(Actor $actor): TravellerProfile
    {
        $profile = $this->forActor($actor);

        $signals = BehaviouralEvent::ownedBy($actor)
            ->whereIn('type', ['save', 'book', 'complete', 'skip'])
            ->where('subject_type', 'experience')
            ->latest('occurred_at')
            ->limit(200)
            ->get();

        if ($signals->isEmpty()) {
            return $profile;
        }

        $experiences = Experience::whereIn('id', $signals->pluck('subject_id')->filter())->get()->keyBy('id');
        $totals = [];

        foreach ($signals as $signal) {
            $experience = $experiences[$signal->subject_id] ?? null;
            if ($experience === null) {
                continue;
            }

            $direction = $signal->type === 'skip' ? -1 : 1;
            $strength = match ($signal->type) {
                'complete' => 1.0,
                'book' => 0.8,
                'save' => 0.5,
                'skip' => 0.4,
                default => 0.3,
            };

            foreach ((array) $experience->interest_affinity as $interest => $affinity) {
                if ($affinity < 50) {
                    continue;
                }
                $totals[$interest] = ($totals[$interest] ?? 0) + ($direction * $strength * ($affinity / 100));
            }
        }

        foreach ($totals as $interest => $delta) {
            if (! in_array($interest, config('experience.interests'), true)) {
                continue;
            }

            $current = $profile->interests()->where('interest', $interest)->first();

            if ($current?->source === 'explicit') {
                continue;
            }

            $weight = (int) max(0, min(100, ($current?->weight ?? 40) + round($delta * 4)));
            $profile->interests()->updateOrCreate(
                ['interest' => $interest],
                ['weight' => $weight, 'source' => 'learned'],
            );
        }

        $profile->update(['recomputed_at' => CarbonImmutable::now()]);

        return $profile->fresh('interests');
    }

    public function present(TravellerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'display_name' => $profile->display_name,
            'travel_pace' => $profile->travel_pace,
            'walking_tolerance' => $profile->walking_tolerance,
            'budget_level' => $profile->budget_level,
            'daily_experience_budget' => Money::of($profile->daily_experience_budget_minor, $profile->currency)?->toArray(),
            'currency' => $profile->currency,
            'iconic_vs_local' => $profile->iconic_vs_local,
            'food_adventurousness' => $profile->food_adventurousness,
            'prefers_private_tours' => $profile->prefers_private_tours,
            'accessibility' => $profile->accessibility,
            'languages' => $profile->languages,
            'interests' => $profile->interests->map(fn ($i) => [
                'interest' => $i->interest,
                'weight' => (int) $i->weight,
                'source' => $i->source,
            ])->sortByDesc('weight')->values()->all(),
            'is_guest' => $profile->user_id === null,
            'providers' => $this->providers($profile),
            'has_password' => $this->hasPassword($profile),
            'account' => $this->account($profile),
        ];
    }

    /**
     * Who is signed in, for the profile header and the avatar.
     *
     * The profile used to say only "Signed in", and the avatar stayed an
     * empty silhouette: nothing on screen confirmed which account this was.
     *
     * @return array{name: ?string, email: ?string, initials: ?string, member_since: string}|null
     */
    private function account(TravellerProfile $profile): ?array
    {
        if ($profile->user_id === null) {
            return null;
        }

        $user = User::query()->find($profile->user_id);
        if ($user === null) {
            return null;
        }

        $name = $profile->display_name ?: $user->name;

        return [
            'name' => $name,
            'email' => $user->email,
            'initials' => self::initials($name ?: $user->email),
            'member_since' => $user->created_at->toDateString(),
        ];
    }

    /** "Ada Lovelace" → "AL", "cher" → "C", "sam@x.com" → "S". */
    public static function initials(?string $source): ?string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return null;
        }

        $words = preg_split('/\s+/u', str_contains($source, '@') && ! str_contains($source, ' ') ? strstr($source, '@', true) : $source) ?: [];
        $letters = array_map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)), array_filter($words, fn ($w) => $w !== ''));

        if ($letters === []) {
            return null;
        }

        return count($letters) === 1 ? $letters[0] : $letters[0].end($letters);
    }

    /** @return list<string> */
    private function providers(TravellerProfile $profile): array
    {
        if ($profile->user_id === null) {
            return [];
        }

        return SocialIdentity::query()
            ->where('user_id', $profile->user_id)
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }

    private function hasPassword(TravellerProfile $profile): bool
    {
        if ($profile->user_id === null) {
            return false;
        }

        return User::query()->whereKey($profile->user_id)->whereNotNull('password')->exists();
    }

    /** Spec s2.3 — the consumer-facing summary of the profile. */
    public function experienceDna(TravellerProfile $profile): array
    {
        $interests = $profile->interests->sortByDesc('weight')->values();
        $top = $interests->take(3)->pluck('interest')->all();

        return [
            'interests' => $interests->map(fn ($i) => [
                'interest' => $i->interest,
                'label' => ucfirst(str_replace('_', ' ', $i->interest)),
                'percent' => (int) $i->weight,
                'learned' => $i->source === 'learned',
            ])->all(),
            'travel_style' => $this->styleLabel($profile, $top),
            'typical_spend' => Money::of($profile->daily_experience_budget_minor, $profile->currency)?->toArray(),
            'preferred_pace' => match ($profile->travel_pace) {
                'slow' => '2 major experiences a day',
                'fast' => '5 major experiences a day',
                default => '3 major experiences a day',
            },
            'is_complete' => $interests->count() >= 3,
        ];
    }

    private function styleLabel(TravellerProfile $profile, array $top): string
    {
        if ($profile->travel_style_label) {
            return $profile->travel_style_label;
        }

        $first = $top[0] ?? null;

        return match (true) {
            $profile->iconic_vs_local <= 35 => 'Local Wanderer',
            $first === 'food' => 'Appetite-Led Traveller',
            $first === 'history' => 'Curious Explorer',
            $first === 'nature' => 'Open-Air Traveller',
            $first === 'nightlife' => 'After-Dark Traveller',
            $profile->travel_pace === 'fast' => 'City Sprinter',
            default => 'Balanced Traveller',
        };
    }
}
