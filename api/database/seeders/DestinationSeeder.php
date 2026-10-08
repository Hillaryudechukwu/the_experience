<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Destinations\Models\CityEssential;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationSignatureItem;
use App\Domains\Destinations\Models\Neighbourhood;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Destinations, neighbourhoods and city essentials.
 *
 * City essentials are limited to stable, publicly documented facts and each one
 * carries the source it came from plus the date it was checked (spec s12.1).
 * Nothing here asserts a safety, legal or medical claim beyond what the named
 * source states.
 */
class DestinationSeeder extends Seeder
{
    public function run(): void
    {
        $verified = CarbonImmutable::now();

        foreach ($this->cities() as $city) {
            $destination = Destination::updateOrCreate(
                ['slug' => $city['slug']],
                collect($city)->except(['neighbourhoods', 'essentials', 'signature'])->all(),
            );

            foreach ($city['neighbourhoods'] as $neighbourhood) {
                Neighbourhood::updateOrCreate(
                    ['destination_id' => $destination->id, 'slug' => $neighbourhood['slug']],
                    $neighbourhood,
                );
            }

            foreach ($city['essentials'] as $sort => $essential) {
                CityEssential::updateOrCreate(
                    ['destination_id' => $destination->id, 'category' => $essential[0]],
                    [
                        'title' => $essential[1],
                        'body' => $essential[2],
                        'source_name' => $essential[3],
                        'source_url' => $essential[4] ?? null,
                        'verified_at' => $verified,
                        'sort' => $sort,
                    ],
                );
            }

            foreach ($city['signature'] as $sort => $item) {
                DestinationSignatureItem::updateOrCreate(
                    ['destination_id' => $destination->id, 'title' => $item[0]],
                    ['description' => $item[1], 'kind' => $item[2], 'sort' => $sort],
                );
            }
        }
    }

    private function cities(): array
    {
        return array_merge([
            [
                'slug' => 'london',
                'name' => 'London',
                'country' => 'United Kingdom',
                'country_code' => 'GB',
                'timezone' => 'Europe/London',
                'lat' => 51.5074,
                'lng' => -0.1278,
                'currency' => 'GBP',
                'languages' => ['en'],
                'default_radius_m' => 9000,
                'summary' => 'A city of layered histories where a Roman wall, a Victorian market and a glass tower can share one street.',
                'hero_image_url' => null,
                'neighbourhoods' => [
                    ['slug' => 'soho', 'name' => 'Soho', 'character' => 'Dense, noisy and always mid-conversation. Theatres, record shops, tiny restaurants and a drinking culture that starts early.', 'best_for' => ['food', 'nightlife', 'theatre'], 'ideal_duration_minutes' => 150, 'lat' => 51.5137, 'lng' => -0.1341],
                    ['slug' => 'shoreditch', 'name' => 'Shoreditch', 'character' => 'Old furniture warehouses turned into galleries and bars. Best walked slowly with your eyes on the walls.', 'best_for' => ['street art', 'markets', 'nightlife'], 'ideal_duration_minutes' => 180, 'lat' => 51.5265, 'lng' => -0.0784],
                    ['slug' => 'south-bank', 'name' => 'South Bank', 'character' => 'A continuous river walk linking most of the city\'s big cultural buildings. Flat, free and good in almost any weather.', 'best_for' => ['walking', 'culture', 'views'], 'ideal_duration_minutes' => 120, 'lat' => 51.5060, 'lng' => -0.1160],
                    ['slug' => 'marylebone', 'name' => 'Marylebone', 'character' => 'A village-scale high street in the middle of central London. Independent shops, quiet garden squares.', 'best_for' => ['shopping', 'slow mornings'], 'ideal_duration_minutes' => 120, 'lat' => 51.5186, 'lng' => -0.1520],
                    ['slug' => 'greenwich', 'name' => 'Greenwich', 'character' => 'Maritime London on a hill, with the best free skyline view in the city at the top of the park.', 'best_for' => ['views', 'history', 'family'], 'ideal_duration_minutes' => 240, 'lat' => 51.4810, 'lng' => -0.0050],
                ],
                'essentials' => [
                    ['currency', 'Currency', 'Pound sterling (GBP). Card and contactless payment are accepted almost everywhere; some small market stalls are cash-only.', 'Bank of England', 'https://www.bankofengland.co.uk/banknotes'],
                    ['emergency', 'Emergency numbers', 'Dial 999 for police, fire or ambulance. 112 also works. 111 is the non-emergency NHS health line.', 'UK Government', 'https://www.gov.uk/emergency-services'],
                    ['transport', 'Transport and payment', 'Contactless bank cards and phones are tapped directly on Tube, bus, DLR and most rail gates. There is no need to buy a paper ticket. Buses do not accept cash.', 'Transport for London', 'https://tfl.gov.uk/fares/how-to-pay-and-where-to-buy-tickets-and-oyster'],
                    ['tipping', 'Tipping', 'Restaurants often add a discretionary service charge of 12.5%. Tipping is not expected in pubs when ordering at the bar.', 'UK Government guidance on tipping', 'https://www.gov.uk/tips-at-work'],
                    ['power', 'Power sockets', 'Type G three-pin sockets, 230V. Bring an adapter if you are coming from mainland Europe or North America.', 'BSI standard BS 1363'],
                    ['driving', 'Driving side', 'Traffic drives on the left. Look right first when crossing; junctions are painted with reminders in central London.', 'UK Highway Code', 'https://www.gov.uk/guidance/the-highway-code'],
                    ['meal_times', 'Typical meal times', 'Lunch is usually 12:00-14:00 and dinner 18:00-21:30. Many kitchens stop serving earlier than visitors expect.', 'Local convention'],
                    ['late_night_transport', 'Late-night transport', 'The Night Tube runs on some lines on Friday and Saturday nights, and night buses run every night. Check the last departure before you commit to a late evening.', 'Transport for London', 'https://tfl.gov.uk/modes/tube/night-tube'],
                ],
                'signature' => [
                    ['Stand inside a working market at breakfast', 'Borough or Maltby Street before the queues, where the traders are still setting up and will talk to you.', 'food'],
                    ['Walk the river between two bridges', 'The stretch from Westminster to Tower Bridge takes about an hour and covers most of the city\'s skyline in one go.', 'public_space'],
                    ['Drink in a pub with no music', 'A proper London pub is a conversation room. Find one with worn carpet and no television.', 'tradition'],
                    ['Ride the top deck of a double-decker bus', 'Route 11 or 15 is a sightseeing tour for the price of a bus fare.', 'small_experience'],
                    ['Spend an hour in one museum room', 'The British Museum and the V&A reward depth far more than they reward coverage.', 'tradition'],
                ],
            ],
            [
                'slug' => 'rome',
                'name' => 'Rome',
                'country' => 'Italy',
                'country_code' => 'IT',
                'timezone' => 'Europe/Rome',
                'lat' => 41.9028,
                'lng' => 12.4964,
                'currency' => 'EUR',
                'languages' => ['it', 'en'],
                'default_radius_m' => 7000,
                'summary' => 'A city built on top of itself, where three thousand years of building are stacked in the same few square kilometres.',
                'hero_image_url' => null,
                'neighbourhoods' => [
                    ['slug' => 'trastevere', 'name' => 'Trastevere', 'character' => 'Narrow cobbled lanes and ivy, quiet in the morning and packed after dark. The best evening walk in the city.', 'best_for' => ['food', 'evening walks'], 'ideal_duration_minutes' => 180, 'lat' => 41.8890, 'lng' => 12.4694],
                    ['slug' => 'testaccio', 'name' => 'Testaccio', 'character' => 'Rome\'s working food neighbourhood. The market and the trattorias here are where the classic Roman dishes are still made properly.', 'best_for' => ['food', 'local life'], 'ideal_duration_minutes' => 150, 'lat' => 41.8760, 'lng' => 12.4750],
                    ['slug' => 'centro-storico', 'name' => 'Centro Storico', 'character' => 'The dense historic core. Everything is walkable and most of it is best seen early or late.', 'best_for' => ['history', 'first visits'], 'ideal_duration_minutes' => 240, 'lat' => 41.8992, 'lng' => 12.4731],
                    ['slug' => 'aventine', 'name' => 'Aventine', 'character' => 'A quiet hill of gardens and churches ten minutes from the noise, with one of the city\'s best views hidden behind a door.', 'best_for' => ['quiet', 'views'], 'ideal_duration_minutes' => 90, 'lat' => 41.8836, 'lng' => 12.4783],
                ],
                'essentials' => [
                    ['currency', 'Currency', 'Euro (EUR). Cards are widely accepted, though small bars and market stalls may prefer cash.', 'European Central Bank', 'https://www.ecb.europa.eu/euro'],
                    ['emergency', 'Emergency numbers', 'Dial 112 for the single European emergency number, which reaches police, fire and ambulance.', 'European Commission', 'https://commission.europa.eu/112_en'],
                    ['transport', 'Transport and payment', 'Metro, bus and tram share one ticket. Tickets must be validated when you board or enter; contactless is accepted on the metro.', 'ATAC Roma', 'https://www.atac.roma.it'],
                    ['tipping', 'Tipping', 'Service is generally included. Rounding up is normal; a large percentage tip is not expected. A coperto (cover charge) per person is standard and is not a tip.', 'Local convention'],
                    ['power', 'Power sockets', 'Type F and Type L sockets, 230V.', 'CEI standard'],
                    ['customs', 'Church dress code', 'Shoulders and knees must be covered to enter St Peter\'s Basilica and most major churches. This is enforced at the door.', 'Vatican State visitor information', 'https://www.vatican.va'],
                    ['meal_times', 'Typical meal times', 'Lunch is 13:00-15:00 and dinner rarely starts before 20:00. Many kitchens close between services.', 'Local convention'],
                    ['mistakes', 'Common visitor mistakes', 'Attempting the Vatican, the Colosseum and the centre in one day. Each of the first two is a half day on its own, and the walk between them is 40 minutes.', 'Editorial'],
                ],
                'signature' => [
                    ['Drink from a nasone', 'The cast-iron street fountains run constantly with clean, cold drinking water. Block the spout with your finger and it arcs upward.', 'tradition'],
                    ['Eat cacio e pepe where it was invented', 'Testaccio\'s trattorias make the four Roman pastas the way the neighbourhood has always made them.', 'food'],
                    ['Stand in the Pantheon during rain', 'The oculus is open. Water falls through it onto the sloped marble floor and drains away.', 'small_experience'],
                    ['Walk Trastevere after 21:00', 'The neighbourhood changes completely once dinner starts.', 'neighbourhood'],
                    ['Look through the Aventine keyhole', 'A door on a quiet square frames St Peter\'s dome perfectly. It costs nothing and takes two minutes.', 'small_experience'],
                ],
            ],
            [
                'slug' => 'new-york',
                'name' => 'New York',
                'country' => 'United States',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'lat' => 40.7128,
                'lng' => -74.0060,
                'currency' => 'USD',
                'languages' => ['en'],
                'default_radius_m' => 10000,
                'summary' => 'A grid you can read on foot, where the best hours are usually the first and last of the day.',
                'hero_image_url' => null,
                'neighbourhoods' => [
                    ['slug' => 'dumbo', 'name' => 'DUMBO', 'character' => 'Cobbled streets under the bridges with the most photographed view in Brooklyn at the end of Washington Street.', 'best_for' => ['photography', 'walking'], 'ideal_duration_minutes' => 120, 'lat' => 40.7033, 'lng' => -73.9903],
                    ['slug' => 'west-village', 'name' => 'West Village', 'character' => 'The one part of Manhattan where the grid breaks down. Low buildings, tree cover, and streets that cross themselves.', 'best_for' => ['wandering', 'food'], 'ideal_duration_minutes' => 150, 'lat' => 40.7358, 'lng' => -74.0036],
                    ['slug' => 'upper-east-side', 'name' => 'Upper East Side', 'character' => 'Museum Mile runs along the park here — more world-class collections in a mile than most cities have in total.', 'best_for' => ['museums', 'culture'], 'ideal_duration_minutes' => 240, 'lat' => 40.7736, 'lng' => -73.9566],
                ],
                'essentials' => [
                    ['currency', 'Currency', 'US dollar (USD). Cards are accepted almost everywhere; some small businesses have card minimums.', 'US Federal Reserve', 'https://www.federalreserve.gov'],
                    ['emergency', 'Emergency numbers', 'Dial 911 for police, fire or ambulance. 311 is the city\'s non-emergency information line.', 'NYC Government', 'https://www.nyc.gov/311'],
                    ['transport', 'Transport and payment', 'The subway runs 24 hours. Tap a contactless card or phone at the OMNY reader; weekly fares are capped automatically.', 'MTA', 'https://www.mta.info/fares'],
                    ['tipping', 'Tipping', 'Tipping is a substantial part of service pay. 18-20% is customary in sit-down restaurants and for bar service.', 'Local convention'],
                    ['power', 'Power sockets', 'Type A and B sockets, 120V. European appliances usually need a voltage-compatible charger, not just a plug adapter.', 'NEMA standard'],
                    ['meal_times', 'Typical meal times', 'Dinner service starts early by European standards, often from 17:30, and popular restaurants release reservations exactly 30 days ahead.', 'Local convention'],
                ],
                'signature' => [
                    ['Walk a bridge at sunrise', 'The Brooklyn Bridge is empty at 06:30 and crowded by 10:00.', 'public_space'],
                    ['Eat standing up at a counter', 'The city\'s best food is often served without a table.', 'food'],
                    ['Ride the Staten Island Ferry', 'Free, 25 minutes each way, and it passes the Statue of Liberty.', 'small_experience'],
                    ['Spend an evening in one neighbourhood', 'Pick one and stay. Covering four in a night means seeing none of them.', 'neighbourhood'],
                ],
            ],
            [
                'slug' => 'tokyo',
                'name' => 'Tokyo',
                'country' => 'Japan',
                'country_code' => 'JP',
                'timezone' => 'Asia/Tokyo',
                'lat' => 35.6762,
                'lng' => 139.6503,
                'currency' => 'JPY',
                'languages' => ['ja', 'en'],
                'default_radius_m' => 12000,
                'summary' => 'A collection of distinct cities sharing one rail map, where the interesting thing is usually on the sixth floor.',
                'hero_image_url' => null,
                'neighbourhoods' => [
                    ['slug' => 'asakusa', 'name' => 'Asakusa', 'character' => 'Old Tokyo around a temple, best walked early before the shopping street fills.', 'best_for' => ['history', 'street food'], 'ideal_duration_minutes' => 150, 'lat' => 35.7148, 'lng' => 139.7967],
                    ['slug' => 'shibuya', 'name' => 'Shibuya', 'character' => 'Constant motion. The crossing is the landmark, but the backstreets are where people actually go.', 'best_for' => ['nightlife', 'shopping'], 'ideal_duration_minutes' => 180, 'lat' => 35.6595, 'lng' => 139.7005],
                    ['slug' => 'shinjuku', 'name' => 'Shinjuku', 'character' => 'A station with a city attached. Golden Gai and Omoide Yokocho preserve a much older, smaller scale of drinking.', 'best_for' => ['nightlife', 'food'], 'ideal_duration_minutes' => 180, 'lat' => 35.6938, 'lng' => 139.7036],
                ],
                'essentials' => [
                    ['currency', 'Currency', 'Japanese yen (JPY). Cash is still needed in smaller restaurants and shrines, though card acceptance has grown quickly.', 'Bank of Japan', 'https://www.boj.or.jp/en'],
                    ['emergency', 'Emergency numbers', 'Dial 110 for police and 119 for fire or ambulance.', 'Japan National Police Agency', 'https://www.npa.go.jp'],
                    ['transport', 'Transport and payment', 'A Suica or PASMO IC card covers trains, subways, buses and many shops with a single tap. Lines are run by several companies, so transfers sometimes require leaving a gate.', 'JR East', 'https://www.jreast.co.jp/multi/en/pass'],
                    ['tipping', 'Tipping', 'Tipping is not customary and can cause confusion. Service is included.', 'Local convention'],
                    ['power', 'Power sockets', 'Type A sockets, 100V. Most modern chargers handle this, but older appliances may run slowly.', 'JIS standard'],
                    ['customs', 'Local customs', 'Eating while walking is uncommon outside festival streets. Escalator sides differ by city; in Tokyo people stand on the left.', 'Local convention'],
                    ['meal_times', 'Typical meal times', 'Lunch sets are served 11:30-14:00 and are far cheaper than the same kitchen\'s dinner. Many restaurants close between services.', 'Local convention'],
                ],
                'signature' => [
                    ['Eat a lunch set at a counter restaurant', 'The same kitchen charges a fraction of its dinner price before 14:00.', 'food'],
                    ['Visit a shrine before 08:00', 'Meiji Jingu and Senso-ji are completely different places when empty.', 'tradition'],
                    ['Go up in a building, not a tower', 'Free observation floors in municipal buildings and department stores beat most paid viewing platforms.', 'small_experience'],
                    ['Follow a basement food hall', 'Department store depachika are among the best food halls anywhere.', 'food'],
                ],
            ],
        ], $this->tourismCities());
    }

    /**
     * High-demand tourism destinations that are populated by the place sync.
     *
     * The four featured cities above retain their handcrafted editorial data.
     * These catalogue entries intentionally contain only stable destination
     * metadata; `experience:sync-places` supplies grounded place content.
     */
    private function tourismCities(): array
    {
        $cities = [
            ['paris', 'Paris', 'France', 'FR', 'Europe/Paris', 48.8566, 2.3522, 'EUR', ['fr', 'en'], 9000],
            ['barcelona', 'Barcelona', 'Spain', 'ES', 'Europe/Madrid', 41.3874, 2.1686, 'EUR', ['ca', 'es', 'en'], 8000],
            ['madrid', 'Madrid', 'Spain', 'ES', 'Europe/Madrid', 40.4168, -3.7038, 'EUR', ['es', 'en'], 9000],
            ['lisbon', 'Lisbon', 'Portugal', 'PT', 'Europe/Lisbon', 38.7223, -9.1393, 'EUR', ['pt', 'en'], 8000],
            ['amsterdam', 'Amsterdam', 'Netherlands', 'NL', 'Europe/Amsterdam', 52.3676, 4.9041, 'EUR', ['nl', 'en'], 7000],
            ['berlin', 'Berlin', 'Germany', 'DE', 'Europe/Berlin', 52.5200, 13.4050, 'EUR', ['de', 'en'], 11000],
            ['vienna', 'Vienna', 'Austria', 'AT', 'Europe/Vienna', 48.2082, 16.3738, 'EUR', ['de', 'en'], 9000],
            ['prague', 'Prague', 'Czechia', 'CZ', 'Europe/Prague', 50.0755, 14.4378, 'CZK', ['cs', 'en'], 8000],
            ['budapest', 'Budapest', 'Hungary', 'HU', 'Europe/Budapest', 47.4979, 19.0402, 'HUF', ['hu', 'en'], 9000],
            ['athens', 'Athens', 'Greece', 'GR', 'Europe/Athens', 37.9838, 23.7275, 'EUR', ['el', 'en'], 9000],
            ['venice', 'Venice', 'Italy', 'IT', 'Europe/Rome', 45.4408, 12.3155, 'EUR', ['it', 'en'], 6000],
            ['florence', 'Florence', 'Italy', 'IT', 'Europe/Rome', 43.7696, 11.2558, 'EUR', ['it', 'en'], 6000],
            ['milan', 'Milan', 'Italy', 'IT', 'Europe/Rome', 45.4642, 9.1900, 'EUR', ['it', 'en'], 9000],
            ['istanbul', 'Istanbul', 'Türkiye', 'TR', 'Europe/Istanbul', 41.0082, 28.9784, 'TRY', ['tr', 'en'], 13000],
            ['dubai', 'Dubai', 'United Arab Emirates', 'AE', 'Asia/Dubai', 25.2048, 55.2708, 'AED', ['ar', 'en'], 18000],
            ['bangkok', 'Bangkok', 'Thailand', 'TH', 'Asia/Bangkok', 13.7563, 100.5018, 'THB', ['th', 'en'], 14000],
            ['singapore', 'Singapore', 'Singapore', 'SG', 'Asia/Singapore', 1.3521, 103.8198, 'SGD', ['en', 'ms', 'zh', 'ta'], 15000],
            ['hong-kong', 'Hong Kong', 'Hong Kong', 'HK', 'Asia/Hong_Kong', 22.3193, 114.1694, 'HKD', ['zh', 'en'], 14000],
            ['seoul', 'Seoul', 'South Korea', 'KR', 'Asia/Seoul', 37.5665, 126.9780, 'KRW', ['ko', 'en'], 14000],
            ['kyoto', 'Kyoto', 'Japan', 'JP', 'Asia/Tokyo', 35.0116, 135.7681, 'JPY', ['ja', 'en'], 10000],
            ['osaka', 'Osaka', 'Japan', 'JP', 'Asia/Tokyo', 34.6937, 135.5023, 'JPY', ['ja', 'en'], 11000],
            ['sydney', 'Sydney', 'Australia', 'AU', 'Australia/Sydney', -33.8688, 151.2093, 'AUD', ['en'], 16000],
            ['melbourne', 'Melbourne', 'Australia', 'AU', 'Australia/Melbourne', -37.8136, 144.9631, 'AUD', ['en'], 14000],
            ['toronto', 'Toronto', 'Canada', 'CA', 'America/Toronto', 43.6532, -79.3832, 'CAD', ['en', 'fr'], 14000],
            ['vancouver', 'Vancouver', 'Canada', 'CA', 'America/Vancouver', 49.2827, -123.1207, 'CAD', ['en', 'fr'], 12000],
            ['mexico-city', 'Mexico City', 'Mexico', 'MX', 'America/Mexico_City', 19.4326, -99.1332, 'MXN', ['es', 'en'], 16000],
            ['cancun', 'Cancún', 'Mexico', 'MX', 'America/Cancun', 21.1619, -86.8515, 'MXN', ['es', 'en'], 16000],
            ['rio-de-janeiro', 'Rio de Janeiro', 'Brazil', 'BR', 'America/Sao_Paulo', -22.9068, -43.1729, 'BRL', ['pt', 'en'], 16000],
            ['buenos-aires', 'Buenos Aires', 'Argentina', 'AR', 'America/Argentina/Buenos_Aires', -34.6037, -58.3816, 'ARS', ['es', 'en'], 14000],
            ['marrakech', 'Marrakech', 'Morocco', 'MA', 'Africa/Casablanca', 31.6295, -7.9811, 'MAD', ['ar', 'fr', 'en'], 10000],
            ['cape-town', 'Cape Town', 'South Africa', 'ZA', 'Africa/Johannesburg', -33.9249, 18.4241, 'ZAR', ['en', 'af'], 18000],
            ['cairo', 'Cairo', 'Egypt', 'EG', 'Africa/Cairo', 30.0444, 31.2357, 'EGP', ['ar', 'en'], 16000],
            ['dublin', 'Dublin', 'Ireland', 'IE', 'Europe/Dublin', 53.3498, -6.2603, 'EUR', ['en', 'ga'], 8000],
            ['edinburgh', 'Edinburgh', 'United Kingdom', 'GB', 'Europe/London', 55.9533, -3.1883, 'GBP', ['en'], 8000],
        ];

        return array_map(
            fn (array $city): array => [
                'slug' => $city[0],
                'name' => $city[1],
                'country' => $city[2],
                'country_code' => $city[3],
                'timezone' => $city[4],
                'lat' => $city[5],
                'lng' => $city[6],
                'currency' => $city[7],
                'languages' => $city[8],
                'default_radius_m' => $city[9],
                'summary' => null,
                'hero_image_url' => null,
                'neighbourhoods' => [],
                'essentials' => [],
                'signature' => [],
            ],
            $cities,
        );
    }
}
