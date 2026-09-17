<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\DTO\PlaceEnrichment;
use App\Domains\ExternalSources\Services\OutboundHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        if ($wikipedia === null && $wikidata === null) {
            return null;
        }

        /*
         * Plain arrays are cached, never the DTO itself. A serialised object in
         * the cache couples the store to the shape of a class, and any later
         * rename or signature change turns every warm entry into an
         * unserialisation error rather than a miss.
         */
        $key = 'wikimedia:v1:' . ($wikidata ?? $wikipedia);
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
                $imageFile = $wikidata !== null ? $this->wikidataImage($wikidata) : null;
                $image = $imageFile !== null ? $this->commonsImage($imageFile) : null;

                $payload = ($summary === null && $image === null)
                    ? ['empty' => true]
                    : ['summary' => $summary, 'image' => $image];

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

    /** @return array{extract:string,url:string,thumbnail:?string}|null */
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
            'thumbnail' => $body['thumbnail']['source'] ?? null,
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
