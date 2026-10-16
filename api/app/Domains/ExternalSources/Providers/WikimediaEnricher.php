<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\DTO\PlaceEnrichment;
use App\Domains\ExternalSources\Services\OutboundHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Editorial content from Wikipedia, Wikidata and Wikimedia Commons.
 *
 * This is how the catalogue gets real descriptions and real photographs of the
 * actual places, which is the honest alternative to stock imagery. Commons
 * images are freely licensed but almost never public domain, so the licence and
 * the photographer are fetched with the image and carried through to the UI —
 * we are obliged to display them, and an image we cannot attribute is one we
 * should not publish.
 */
class WikimediaEnricher implements PlaceEnricher
{
    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'wikimedia';
    }

    public function enrich(PlaceCandidate $candidate): ?PlaceEnrichment
    {
        $wikipedia = $candidate->externalRefs['wikipedia'] ?? null;
        $wikidata = $candidate->externalRefs['wikidata'] ?? null;
        $nameFallback = false;

        if ($wikipedia === null && $wikidata === null) {
            /* Google Places (and similar) never carry OSM wiki tags. Fall back
               to the place name so activation can still publish grounded copy;
               a miss returns null the same way an untagged OSM node would. */
            if (trim($candidate->name) === '') {
                return null;
            }

            $wikipedia = $candidate->name;
            $nameFallback = true;
        }

        /*
         * Plain arrays are cached, never the DTO itself. A serialised object in
         * the cache couples the store to the shape of a class, and any later
         * rename or signature change turns every warm entry into an
         * unserialisation error rather than a miss.
         */
        $key = 'wikimedia:v1:' . ($nameFallback
            ? 'name:' . Str::slug($candidate->name)
            : ($wikidata ?? $wikipedia));
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            try {
                if ($wikipedia === null && $wikidata !== null) {
                    /* OSM frequently tags a wikidata id without a wikipedia one.
                       Wikidata knows which article describes the entity, so ask
                       it rather than giving up on a description. */
                    $wikipedia = $this->wikipediaTitleFromWikidata($wikidata);
                }

                $summary = $wikipedia !== null ? $this->wikipediaSummary($wikipedia) : null;
                $wikidata = $wikidata ?? ($summary['wikidata'] ?? null);
                $imageFile = $wikidata !== null ? $this->wikidataImage($wikidata) : null;
                $image = $imageFile !== null ? $this->commonsImage($imageFile) : null;

                $payload = ($summary === null && $image === null)
                    ? ['empty' => true]
                    : ['summary' => $summary, 'image' => $image, 'wikidata' => $wikidata, 'wikipedia' => $wikipedia];

                /*
                 * Only a definitive answer is cached. "Wikipedia has nothing on
                 * this memorial" is worth remembering for a week; "we were rate
                 * limited just then" is not, and caching it would blank out a
                 * place's description until the entry expired.
                 */
                Cache::put($key, $payload, (int) config('experience.enrichment.cache_seconds', 604800));
            } catch (\Throwable $e) {
                Log::info('enrichment.unavailable', [
                    'ref' => $wikidata ?? $wikipedia,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($payload['empty'] ?? false) {
            return null;
        }

        $summary = $payload['summary'] ?? null;
        $image = $payload['image'] ?? null;
        $wikidata = $payload['wikidata'] ?? $wikidata;
        $wikipedia = $payload['wikipedia'] ?? $wikipedia;

        return new PlaceEnrichment(
            summary: $summary['extract'] ?? null,
            sourceName: $summary !== null ? 'Wikipedia' : null,
            sourceUrl: $summary['url'] ?? null,
            imageUrl: $image['url'] ?? $summary['thumbnail'] ?? null,
            imageLicence: $image['licence'] ?? null,
            imageLicenceUrl: $image['licence_url'] ?? null,
            imageCreator: $image['creator'] ?? null,
            imageSourceUrl: $image['source_url'] ?? null,
            meta: ['wikidata' => $wikidata, 'wikipedia' => $wikipedia],
        );
    }

    /**
     * A photograph of a city, for a destination hero.
     *
     * Wikipedia's own summary thumbnail for a city is usually a montage — four
     * postcards in a grid — which reads as a collage rather than a place. The
     * Wikidata "image" claim is a single chosen photograph instead, so the
     * article is used only to find the entity, and the picture comes from the
     * claim. Where there is no claim we fall back to the article thumbnail,
     * because a montage still beats an empty header.
     *
     * @return array{url:string,licence:?string,licence_url:?string,creator:?string,source_url:string}|null
     */
    public function cityImage(string $name, ?string $country = null): ?array
    {
        $key = 'wikimedia:city:v1:' . Str::slug($name . ' ' . ($country ?? ''));
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            try {
                $summary = $this->wikipediaSummary($name);
                $entity = $summary['wikidata'] ?? null;
                $file = $entity !== null ? $this->wikidataImage($entity) : null;
                $image = $file !== null ? $this->commonsImage($file) : null;

                if ($image === null && ($summary['thumbnail'] ?? null) !== null) {
                    $image = [
                        'url' => $summary['thumbnail'],
                        'licence' => 'See Wikipedia',
                        'licence_url' => null,
                        'creator' => null,
                        'source_url' => $summary['url'],
                    ];
                }

                $payload = $image === null ? ['empty' => true] : ['image' => $image];
                Cache::put($key, $payload, (int) config('experience.enrichment.cache_seconds', 604800));
            } catch (\Throwable $e) {
                Log::info('enrichment.city_image_unavailable', ['city' => $name, 'message' => $e->getMessage()]);

                return null;
            }
        }

        return ($payload['empty'] ?? false) ? null : $payload['image'];
    }

    /**
     * The licensed photograph attached to a Wikidata entity, if it has one.
     *
     * @return array{url:string,licence:?string,licence_url:?string,creator:?string,source_url:string}|null
     */
    public function imageForWikidata(string $entityId): ?array
    {
        $file = $this->wikidataImage($entityId);

        return $file === null ? null : $this->commonsImage($file);
    }

    /** @return array{extract:string,url:string,thumbnail:?string,wikidata:?string}|null */
    private function wikipediaSummary(string $reference): ?array
    {
        /* OSM stores "en:Tower of London". */
        [$language, $title] = str_contains($reference, ':')
            ? explode(':', $reference, 2)
            : ['en', $reference];

        $response = $this->http
            ->for($this->key())
            ->get(sprintf('https://%s.wikipedia.org/api/rest_v1/page/summary/%s', $language, rawurlencode($title)));

        if ($response->failed()) {
            return null;
        }

        $body = $response->json();
        $extract = $body['extract'] ?? null;

        if (! is_string($extract) || $extract === '') {
            return null;
        }

        return [
            'extract' => $extract,
            'url' => $body['content_urls']['desktop']['page'] ?? "https://{$language}.wikipedia.org/wiki/" . rawurlencode($title),
            'thumbnail' => $body['originalimage']['source'] ?? $body['thumbnail']['source'] ?? null,
            'wikidata' => $body['wikibase_item'] ?? null,
        ];
    }

    /** Resolves the English Wikipedia article title for a Wikidata entity. */
    private function wikipediaTitleFromWikidata(string $entityId): ?string
    {
        $response = $this->http
            ->for($this->key())
            ->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetentities',
                'ids' => $entityId,
                'props' => 'sitelinks',
                'sitefilter' => 'enwiki',
                'format' => 'json',
            ]);

        if ($response->failed()) {
            return null;
        }

        $title = $response->json("entities.{$entityId}.sitelinks.enwiki.title");

        return is_string($title) && $title !== '' ? 'en:' . $title : null;
    }

    private function wikidataImage(string $entityId): ?string
    {
        $response = $this->http
            ->for($this->key())
            ->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetclaims',
                'entity' => $entityId,
                'property' => 'P18',       // "image"
                'format' => 'json',
            ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('claims.P18.0.mainsnak.datavalue.value');
    }

    /** @return array{url:string,licence:?string,licence_url:?string,creator:?string,source_url:string}|null */
    private function commonsImage(string $fileName): ?array
    {
        $response = $this->http
            ->for($this->key())
            ->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'titles' => 'File:' . $fileName,
                'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata',
                'iiurlwidth' => 1200,
                'format' => 'json',
            ]);

        if ($response->failed()) {
            return null;
        }

        $pages = $response->json('query.pages') ?? [];
        $info = collect($pages)->first()['imageinfo'][0] ?? null;

        if ($info === null) {
            return null;
        }

        $meta = $info['extmetadata'] ?? [];
        $licence = $meta['LicenseShortName']['value'] ?? null;

        /* No licence, no image. We would rather show nothing than something we
           cannot lawfully attribute. */
        if ($licence === null) {
            return null;
        }

        return [
            'url' => $info['thumburl'] ?? $info['url'],
            'licence' => $licence,
            'licence_url' => $meta['LicenseUrl']['value'] ?? null,
            'creator' => $this->plainText($meta['Artist']['value'] ?? null),
            'source_url' => $info['descriptionurl'] ?? 'https://commons.wikimedia.org/wiki/File:' . rawurlencode($fileName),
        ];
    }

    /** Commons returns creator fields as HTML fragments. */
    private function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : mb_substr($text, 0, 160);
    }
}
