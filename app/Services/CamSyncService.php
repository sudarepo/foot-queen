<?php

namespace App\Services;

use App\Models\Cam;
use App\Services\Providers\CamProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CamSyncService
{
    /**
     * Run a full sync across all enabled providers.
     * Returns a per-provider count of upserted cams.
     */
    public function syncAll(): array
    {
        $results = [];

        foreach (config('cam-providers.enabled', []) as $providerClass) {
            /** @var CamProviderInterface $provider */
            $provider = app($providerClass);
            $name = $provider->getName();

            try {
                $cams = $provider->fetchCams();
                $count = $this->upsertProviderCams($name, $cams);
                $results[$name] = $count;
                Log::info("Synced {$count} cams from {$name}");
            } catch (\Throwable $e) {
                Log::error("Sync failed for {$name}: " . $e->getMessage());
                $results[$name] = 0;
            }
        }

        return $results;
    }

    /**
     * Upsert the cams from a single provider and mark any previously-online
     * cams from that provider that didn't appear this round as offline.
     */
    private function upsertProviderCams(string $provider, array $cams): int
    {
        $seenIds = [];
        $now = now();

        foreach (array_chunk($cams, 500) as $chunk) {
            $rows = array_map(function ($cam) use ($now) {
                return [
                    'provider'         => $cam['provider'],
                    'external_id'      => $cam['external_id'],
                    'username'         => $cam['username'],
                    'gender'           => $cam['gender'],
                    'age'              => $cam['age'],
                    'hair_color'       => $cam['hair_color'],
                    'body_type'        => $cam['body_type'],
                    'categories'       => json_encode($cam['categories'] ?? []),
                    'viewers'          => $cam['viewers'],
                    'thumbnail_url'    => $cam['thumbnail_url'],
                    'room_url'         => $cam['room_url'],
                    'room_subject'     => $cam['room_subject'] ?? null,
                    'country'          => $cam['country'] ?? null,
                    'spoken_languages' => $cam['spoken_languages'] ?? null,
                    'is_hd'            => $cam['is_hd'] ?? false,
                    'is_new'           => $cam['is_new'] ?? false,
                    'is_online'        => true,
                    'last_seen_at'     => $now,
                    'updated_at'       => $now,
                    'created_at'       => $now,
                ];
            }, $chunk);

            DB::table('cams')->upsert(
                $rows,
                ['provider', 'external_id'],            // unique key
                [                                        // columns to update on conflict
                    'username', 'gender', 'age', 'hair_color', 'body_type',
                    'categories', 'viewers', 'thumbnail_url', 'room_url',
                    'room_subject', 'country', 'spoken_languages',
                    'is_hd', 'is_new', 'is_online', 'last_seen_at', 'updated_at',
                ]
            );

            foreach ($chunk as $cam) {
                $seenIds[] = $cam['external_id'];
            }
        }

        // Mark cams from this provider that we didn't see as offline.
        // They're kept in the DB (useful for "recently online" features later)
        // but won't show up in the grid.
        Cam::where('provider', $provider)
            ->where('is_online', true)
            ->whereNotIn('external_id', $seenIds)
            ->update(['is_online' => false]);

        return count($seenIds);
    }
}
