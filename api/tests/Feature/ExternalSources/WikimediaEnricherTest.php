<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Providers\WikimediaEnricher;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Licensed imagery is only usable if the licence travels with it. */
class WikimediaEnricherTest extends TestCase
{
    public function test_it_returns_a_description_and_an_attributed_image(): void
    {
        Http::fake($this->happyPath());

        $result = app(WikimediaEnricher::class)->enrich($this->candidate());

        $this->assertNotNull($result);
        $this->assertStringContainsString('historic citadel', $result->summary);
        $this->assertSame('Wikipedia', $result->sourceName);
        $this->assertTrue($result->hasImage());
        $this->assertSame('CC BY-SA 3.0', $result->imageAttribution()['licence']);
        $this->assertSame('Bob Collowân', $result->imageAttribution()['creator']);
    }

    public function test_an_image_without_a_licence_is_not_used(): void
    {
        Http::fake(array_merge($this->happyPath(), [
            'commons.wikimedia.org/*' => Http::response([
                'query' => ['pages' => [['imageinfo' => [['thumburl' => 'https://example.test/x.jpg', 'extmetadata' => []]]]]],
            ]),
        ]));

        $result = app(WikimediaEnricher::class)->enrich($this->candidate());

        $this->assertNotNull($result->summary);
        $this->assertNull($result->imageLicence);
        $this->assertFalse($result->hasImage(), 'An image we cannot attribute is one we must not publish.');
    }

    public function test_a_place_with_no_external_references_is_skipped_without_a_request(): void
    {
        Http::fake();

        $result = app(WikimediaEnricher::class)->enrich(new PlaceCandidate(
            provider: 'osm',
            providerId: 'node/1',
            name: 'Unremarkable Bench',
            point: new GeoPoint(51.5, -0.12),
        ));

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_it_finds_the_article_through_wikidata_when_osm_did_not_tag_one(): void
    {
        Http::fake($this->happyPath());

        $result = app(WikimediaEnricher::class)->enrich(new PlaceCandidate(
            provider: 'osm',
            providerId: 'way/2',
            name: 'Tower of London',
            point: new GeoPoint(51.5081, -0.0759),
            externalRefs: ['wikidata' => 'Q62378'],     // no wikipedia tag
        ));

        $this->assertNotNull($result?->summary);
    }

    public function test_a_definitive_empty_answer_is_cached(): void
    {
        Http::fake([
            'wikidata.org/*' => Http::response(['entities' => ['Q1' => ['sitelinks' => []]]]),
            '*' => Http::response([], 404),
        ]);

        $candidate = new PlaceCandidate(
            provider: 'osm', providerId: 'node/3', name: 'Nothing Here',
            point: new GeoPoint(51.5, -0.12), externalRefs: ['wikidata' => 'Q1'],
        );

        $this->assertNull(app(WikimediaEnricher::class)->enrich($candidate));
        $this->assertTrue(Cache::has('wikimedia:v1:Q1'));
    }

    public function test_a_transient_failure_is_not_cached_as_an_absence(): void
    {
        /* Caching "we were rate limited" would blank out this place's
           description for a week. Only definitive answers are remembered. */
        Http::fake(fn () => throw new \RuntimeException('Rate limit reached'));

        $candidate = new PlaceCandidate(
            provider: 'osm', providerId: 'node/4', name: 'Temporarily Unreachable',
            point: new GeoPoint(51.5, -0.12), externalRefs: ['wikidata' => 'Q2'],
        );

        $this->assertNull(app(WikimediaEnricher::class)->enrich($candidate));
        $this->assertFalse(Cache::has('wikimedia:v1:Q2'));
    }

    private function candidate(): PlaceCandidate
    {
        return new PlaceCandidate(
            provider: 'osm',
            providerId: 'way/123456',
            name: 'Tower of London',
            point: new GeoPoint(51.5081, -0.0759),
            externalRefs: ['wikidata' => 'Q62378', 'wikipedia' => 'en:Tower of London'],
        );
    }

    private function happyPath(): array
    {
        return [
            'en.wikipedia.org/*' => Http::response([
                'extract' => 'The Tower of London is a historic citadel on the north bank of the River Thames.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Tower_of_London']],
            ]),
            'www.wikidata.org/*' => Http::response([
                'claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => 'Tower of London.jpg']]]]],
                'entities' => ['Q62378' => ['sitelinks' => ['enwiki' => ['title' => 'Tower of London']]]],
            ]),
            'commons.wikimedia.org/*' => Http::response([
                'query' => ['pages' => [[
                    'imageinfo' => [[
                        'thumburl' => 'https://upload.wikimedia.org/tower.jpg',
                        'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Tower.jpg',
                        'extmetadata' => [
                            'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
                            'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/3.0'],
                            'Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Bob">Bob Collowân</a>'],
                        ],
                    ]],
                ]]],
            ]),
        ];
    }
}
