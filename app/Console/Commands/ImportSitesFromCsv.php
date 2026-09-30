<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\HomepageLayout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

#[Signature('sites:import-csv {path : Absolute or relative path to the CSV file} {--dry-run : Parse the file and report changes without writing to the database}')]
#[Description('Import new Site records from a CSV file in the Site_Copy_By_Domain_v2 format')]
class ImportSitesFromCsv extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $fullPath = file_exists($path) ? $path : base_path($path);
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            $this->error("CSV file not found or not readable: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            $this->error("Failed to open CSV file: {$path}");

            return self::FAILURE;
        }

        $rawHeaders = fgetcsv($handle);

        if (! is_array($rawHeaders)) {
            fclose($handle);
            $this->error('The CSV appears to be empty.');

            return self::FAILURE;
        }

        $headers = $this->normalizeHeaders($rawHeaders);
        $knownSeoRegistries = $this->knownSeoRegistries();

        $existingSlugs = Site::query()->pluck('slug')->map(fn (string $slug) => Str::lower($slug))->all();
        $existingDomains = Site::query()
            ->pluck('domains')
            ->flatten()
            ->filter(fn (mixed $domain): bool => is_string($domain) && filled($domain))
            ->map(fn (string $domain) => Str::lower(trim($domain)))
            ->all();

        $created = 0;
        $skipped = 0;
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $record = $this->rowToRecord($headers, $row, $knownSeoRegistries);

            if ($record === null) {
                $skipped++;
                $this->warn("Line {$line}: missing required values (site_name and Domains). Skipped.");

                continue;
            }

            $duplicateDomain = collect($record['domains'])
                ->contains(fn (string $domain): bool => in_array(Str::lower($domain), $existingDomains, true));

            if ($duplicateDomain || in_array(Str::lower($record['slug']), $existingSlugs, true)) {
                $skipped++;
                $this->line("Line {$line}: {$record['name']} skipped (duplicate domain or slug).");

                continue;
            }

            if ($dryRun) {
                $created++;
                $this->line(sprintf('Line %d: would create %s (%s).', $line, $record['name'], implode(', ', $record['domains'])));
            } else {
                Site::query()->create($record);

                $created++;
                $this->info(sprintf('Line %d: created %s (%s).', $line, $record['name'], implode(', ', $record['domains'])));
            }

            $existingSlugs[] = Str::lower($record['slug']);

            foreach ($record['domains'] as $domain) {
                $existingDomains[] = Str::lower($domain);
            }
        }

        fclose($handle);

        $mode = $dryRun ? 'Dry run complete' : 'Import complete';
        $this->newLine();
        $this->info("{$mode}: {$created} created, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $rawHeaders
     * @return array<int, string>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        return array_map(
            fn (string $header): string => Str::of($header)->trim()->lower()->replace('/', ' ')->replace('-', ' ')->squish()->toString(),
            $rawHeaders,
        );
    }

    /**
     * @param  array<int, string>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        return collect($row)->every(fn (mixed $value): bool => blank(trim((string) $value)));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $row
     * @param  array<int, string>  $knownSeoRegistries
     * @return array<string, mixed>|null
     */
    private function rowToRecord(array $headers, array $row, array $knownSeoRegistries): ?array
    {
        $data = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));

        if (! is_array($data)) {
            return null;
        }

        $name = trim((string) Arr::get($data, 'site_name'));
        $slug = trim((string) Arr::get($data, 'slug'));

        $domains = $this->parseList((string) Arr::get($data, 'domains'));

        if (blank($name) || $domains === []) {
            return null;
        }

        $slug = filled($slug) ? Str::slug($slug) : Str::slug($name);

        if (blank($slug)) {
            return null;
        }

        $status = Str::lower(trim((string) Arr::get($data, 'active default site')));
        $seoRegistry = trim((string) Arr::get($data, 'seo pages registry'));
        $seoRegistry = in_array($seoRegistry, $knownSeoRegistries, true) ? $seoRegistry : null;

        $tags = $this->parseList((string) Arr::get($data, 'keywords tags'));

        return [
            'name' => $name,
            'slug' => $slug,
            'domains' => $domains,
            'is_active' => Str::contains($status, 'active'),
            'is_default' => Str::contains($status, 'default'),
            'home_layout_desktop' => $this->parseHomepageLayout((string) Arr::get($data, 'desktop visitors'))->value,
            'home_layout_mobile' => $this->parseHomepageLayout((string) Arr::get($data, 'mobile visitors'))->value,
            'gender' => $this->parseGender((string) Arr::get($data, 'gender')),
            'tags' => $tags,
            'default_category' => $this->nullableTrim((string) Arr::get($data, 'default category')),
            'home_h1' => $this->nullableTrim((string) Arr::get($data, 'homepage_heading')),
            'home_title' => $this->nullableTrim((string) Arr::get($data, 'homepage <title>')),
            'tagline' => $this->nullableTrim((string) Arr::get($data, 'tagline')),
            'home_meta' => $this->nullableTrim((string) Arr::get($data, 'homepage_meta_description')),
            'feed_meta' => $this->nullableTrim((string) Arr::get($data, 'feed_meta_description')),
            'meta_keywords' => $this->nullableTrim((string) Arr::get($data, 'meta keywords')),
            'profile_title_live' => $this->nullableTrim((string) Arr::get($data, 'profile_title_live')),
            'profile_title_offline' => $this->nullableTrim((string) Arr::get($data, 'profile_title_offline')),
            'profile_noun' => $this->nullableTrim((string) Arr::get($data, 'profile_description_phrase')),
            'seo_pages' => $seoRegistry,
            'ga_measurement_id' => $this->nullableTrim((string) Arr::get($data, 'google analytics measurement id')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseList(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $item): string => Str::lower(trim($item)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function parseHomepageLayout(string $value): HomepageLayout
    {
        return match (Str::lower(trim($value))) {
            'feed', 'feed only' => HomepageLayout::Feed,
            'grid', 'grid only' => HomepageLayout::Grid,
            default => HomepageLayout::AbTest,
        };
    }

    private function parseGender(string $value): ?string
    {
        return match (Str::lower(trim($value))) {
            'female', 'male', 'trans', 'couple' => Str::lower(trim($value)),
            default => null,
        };
    }

    private function nullableTrim(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private function knownSeoRegistries(): array
    {
        return collect(glob(config_path('seo-pages/*.php')) ?: [])
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->values()
            ->all();
    }
}
