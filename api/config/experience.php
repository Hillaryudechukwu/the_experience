<?php

/**
 * The Experience — engine configuration.
 *
 * Scoring weights, journey playbooks and freshness policy live here rather than
 * in controllers or the UI (spec s6.1: "Weights should be configurable,
 * testable and eventually personalised").
 */
return [

    'engine_version' => 'score-v1.3',

    'scoring' => [
        /*
         * Component weights from spec s6.1. They must sum to 1.0; the scorer
         * asserts this at boot so an experiment cannot silently skew ranking.
         */
        'weights' => [
            'personal_interest_fit' => 0.20,
            'journey_purpose_fit'   => 0.20,
            'quality_confidence'    => 0.15,
            'uniqueness'            => 0.10,
            'current_time_fit'      => 0.10,
            'location_convenience'  => 0.10,
            'value'                 => 0.05,
            'weather_fit'           => 0.05,
            'companion_fit'         => 0.05,
        ],

        /* Hard filters are applied before scoring — they are never traded off. */
        'hard_filters' => [
            'respect_opening_hours'   => true,
            'respect_time_window'     => true,
            'respect_accessibility'   => true,
            'respect_avoid_list'      => true,
            'exclude_completed'       => true,
        ],

        'cache_ttl_seconds' => 300,
        'max_candidates'    => 400,
    ],

    'interests' => [
        'history', 'food', 'culture', 'architecture', 'nature', 'art', 'music',
        'shopping', 'nightlife', 'sport', 'photography', 'wellness', 'family',
        'adventure', 'local_life',
    ],

    'moods' => [
        'adventurous', 'relaxed', 'romantic', 'curious', 'hungry',
        'social', 'inspired', 'energetic', 'different',
    ],

    'time_windows' => [30, 60, 120, 180, 300, 600],

    /*
     * Reason-for-travel playbooks (spec s3.5). Each playbook biases ranking;
     * none of them can override a hard constraint.
     */
    'playbooks' => [
        'holiday' => [
            'label' => 'Holiday / city break',
            'interest_boost' => [],
            'tag_boost' => ['iconic' => 10, 'must_experience' => 10, 'local_favourite' => 5],
            'tag_penalty' => [],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 120,
            'goals' => ['balanced_highlights', 'good_food'],
        ],
        'business' => [
            'label' => 'Business trip',
            'interest_boost' => ['food' => 10, 'architecture' => 5],
            'tag_boost' => ['quick_experience' => 25, 'food_experience' => 15, 'iconic' => 10],
            'tag_penalty' => ['full_day' => -45, 'adventure' => -15],
            'max_travel_minutes' => 25,
            'preferred_duration_minutes' => 75,
            'goals' => ['close_to_base', 'low_travel_uncertainty', 'evening_friendly', 'client_dining'],
        ],
        'conference' => [
            'label' => 'Conference or trade event',
            'interest_boost' => ['culture' => 5, 'food' => 10],
            'tag_boost' => ['quick_experience' => 25, 'iconic' => 15, 'food_experience' => 10],
            'tag_penalty' => ['full_day' => -50],
            'max_travel_minutes' => 30,
            'preferred_duration_minutes' => 90,
            'goals' => ['fits_programme_gaps', 'networking_friendly', 'destination_highlight'],
        ],
        'honeymoon' => [
            'label' => 'Honeymoon',
            'interest_boost' => ['food' => 10, 'nature' => 10, 'photography' => 10],
            'tag_boost' => ['romantic' => 40, 'hidden_gem' => 10],
            'tag_penalty' => ['family' => -20, 'nightlife' => -10],
            'max_travel_minutes' => 50,
            'preferred_duration_minutes' => 150,
            'goals' => ['romantic', 'scenic', 'memorable_dining', 'small_group'],
        ],
        'anniversary' => [
            'label' => 'Anniversary',
            'interest_boost' => ['food' => 10, 'culture' => 5],
            'tag_boost' => ['romantic' => 35, 'culture' => 10],
            'tag_penalty' => ['family' => -15],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 140,
            'goals' => ['romantic', 'memorable_dining', 'scenic'],
        ],
        'birthday' => [
            'label' => 'Birthday trip',
            'interest_boost' => ['food' => 10, 'nightlife' => 10],
            'tag_boost' => ['food_experience' => 15, 'nightlife' => 15],
            'tag_penalty' => [],
            'max_travel_minutes' => 40,
            'preferred_duration_minutes' => 120,
            'goals' => ['celebratory', 'social'],
        ],
        'family' => [
            'label' => 'Family holiday',
            'interest_boost' => ['family' => 25, 'nature' => 5],
            'tag_boost' => ['family' => 40, 'beginner_friendly' => 10],
            'tag_penalty' => ['nightlife' => -60, 'adventure' => -10],
            'max_travel_minutes' => 30,
            'preferred_duration_minutes' => 100,
            'goals' => ['age_appropriate', 'interactive', 'short_queues', 'food_and_toilets'],
        ],
        'visiting_friends' => [
            'label' => 'Visiting friends/family',
            'interest_boost' => ['local_life' => 15, 'food' => 10],
            'tag_boost' => ['local_favourite' => 25, 'hidden_gem' => 10],
            'tag_penalty' => ['iconic' => -10],
            'max_travel_minutes' => 40,
            'preferred_duration_minutes' => 110,
            'goals' => ['local_life', 'social'],
        ],
        'romantic' => [
            'label' => 'Romantic getaway',
            'interest_boost' => ['food' => 10, 'photography' => 5],
            'tag_boost' => ['romantic' => 40],
            'tag_penalty' => ['family' => -20],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 140,
            'goals' => ['romantic', 'scenic'],
        ],
        'solo' => [
            'label' => 'Solo adventure',
            'interest_boost' => ['culture' => 10, 'local_life' => 10],
            'tag_boost' => ['hidden_gem' => 15, 'culture' => 10, 'free' => 5],
            'tag_penalty' => [],
            'max_travel_minutes' => 50,
            'preferred_duration_minutes' => 120,
            'goals' => ['safety_conscious', 'reflective', 'flexible_pace', 'optional_social'],
        ],
        'backpacking' => [
            'label' => 'Backpacking',
            'interest_boost' => ['local_life' => 15, 'adventure' => 10],
            'tag_boost' => ['free' => 30, 'hidden_gem' => 15, 'local_favourite' => 10],
            'tag_penalty' => [],
            'max_travel_minutes' => 60,
            'preferred_duration_minutes' => 150,
            'goals' => ['budget_first', 'local_life'],
        ],
        'cultural' => [
            'label' => 'Cultural exploration',
            'interest_boost' => ['culture' => 20, 'history' => 15, 'art' => 10],
            'tag_boost' => ['culture' => 25, 'must_experience' => 10],
            'tag_penalty' => ['shopping' => -10],
            'max_travel_minutes' => 50,
            'preferred_duration_minutes' => 150,
            'goals' => ['depth', 'context'],
        ],
        'food' => [
            'label' => 'Food-focused trip',
            'interest_boost' => ['food' => 30, 'local_life' => 10],
            'tag_boost' => ['food_experience' => 45, 'local_favourite' => 15],
            'tag_penalty' => [],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 120,
            'goals' => ['authentic_food', 'market_and_producers'],
        ],
        'pilgrimage' => [
            'label' => 'Religious or pilgrimage travel',
            'interest_boost' => ['history' => 10, 'culture' => 15],
            'tag_boost' => ['culture' => 20],
            'tag_penalty' => ['nightlife' => -60],
            'max_travel_minutes' => 60,
            'preferred_duration_minutes' => 150,
            'goals' => ['reflective', 'respectful_timing'],
        ],
        'education' => [
            'label' => 'Education',
            'interest_boost' => ['culture' => 15, 'history' => 15],
            'tag_boost' => ['culture' => 20, 'free' => 10],
            'tag_penalty' => [],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 150,
            'goals' => ['learning', 'budget_aware'],
        ],
        'medical' => [
            'label' => 'Medical travel',
            'interest_boost' => ['wellness' => 20, 'nature' => 10],
            'tag_boost' => ['quick_experience' => 20, 'free' => 10],
            'tag_penalty' => ['adventure' => -40, 'nightlife' => -40],
            'max_travel_minutes' => 20,
            'preferred_duration_minutes' => 60,
            'goals' => ['low_effort', 'close_to_base', 'restful'],
        ],
        'relocation' => [
            'label' => 'Relocation research',
            'interest_boost' => ['local_life' => 30],
            'tag_boost' => ['local_favourite' => 30, 'free' => 10],
            'tag_penalty' => ['iconic' => -25],
            'max_travel_minutes' => 60,
            'preferred_duration_minutes' => 120,
            'goals' => ['neighbourhood_feel', 'everyday_living', 'parks_and_services'],
        ],
        'shopping' => [
            'label' => 'Shopping',
            'interest_boost' => ['shopping' => 30],
            'tag_boost' => ['shopping' => 40],
            'tag_penalty' => [],
            'max_travel_minutes' => 35,
            'preferred_duration_minutes' => 120,
            'goals' => ['retail_districts', 'markets'],
        ],
        'sports_event' => [
            'label' => 'Sports event',
            'interest_boost' => ['sport' => 25, 'food' => 10],
            'tag_boost' => ['quick_experience' => 15, 'local_favourite' => 10],
            'tag_penalty' => ['full_day' => -45],
            'max_travel_minutes' => 30,
            'preferred_duration_minutes' => 90,
            'goals' => ['fixture_is_anchor', 'pre_match_atmosphere', 'transport_buffer'],
        ],
        'festival' => [
            'label' => 'Concert or festival',
            'interest_boost' => ['music' => 25, 'food' => 10],
            'tag_boost' => ['nightlife' => 20, 'quick_experience' => 10],
            'tag_penalty' => ['full_day' => -35],
            'max_travel_minutes' => 35,
            'preferred_duration_minutes' => 90,
            'goals' => ['event_is_anchor', 'late_transport'],
        ],
        'photography' => [
            'label' => 'Photography trip',
            'interest_boost' => ['photography' => 30, 'architecture' => 15],
            'tag_boost' => ['iconic' => 15, 'hidden_gem' => 15],
            'tag_penalty' => [],
            'max_travel_minutes' => 55,
            'preferred_duration_minutes' => 120,
            'goals' => ['golden_hour', 'viewpoints'],
        ],
        'retreat' => [
            'label' => 'Personal retreat',
            'interest_boost' => ['wellness' => 30, 'nature' => 20],
            'tag_boost' => ['free' => 10],
            'tag_penalty' => ['nightlife' => -50],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 150,
            'goals' => ['restful', 'reflective', 'nature'],
        ],
        'layover' => [
            'label' => 'Layover',
            'interest_boost' => [],
            'tag_boost' => ['quick_experience' => 45, 'iconic' => 20],
            'tag_penalty' => ['full_day' => -80, 'half_day' => -40, 'book_ahead' => -30],
            'max_travel_minutes' => 40,
            'preferred_duration_minutes' => 60,
            'goals' => ['return_buffer', 'luggage_aware', 'high_value_short'],
        ],
        'other' => [
            'label' => 'Something else',
            'interest_boost' => [],
            'tag_boost' => [],
            'tag_penalty' => [],
            'max_travel_minutes' => 45,
            'preferred_duration_minutes' => 120,
            'goals' => [],
        ],
    ],

    /*
     * Data freshness policy (spec s22.1). Anything past its TTL is surfaced to
     * the client as stale rather than presented as current truth.
     */
    'freshness' => [
        'static'         => ['ttl_seconds' => 2592000, 'label' => 'Rarely changes'],
        'semi_dynamic'   => ['ttl_seconds' => 86400,   'label' => 'Checked daily'],
        'highly_dynamic' => ['ttl_seconds' => 900,     'label' => 'Checked live'],
    ],

    /*
     * Shared outbound HTTP policy. Several sources this product depends on are
     * free, community-run infrastructure whose usage policies require an
     * identifying agent string and restrained request rates.
     */
    'http' => [
        'user_agent' => env('EXPERIENCE_HTTP_USER_AGENT', 'TheExperience/1.0'),
        'contact_email' => env('CONTACT_EMAIL'),
        'rate_limits' => [
            'default' => 60,
            'osm' => 20,          // Overpass is a shared community resource
            'nominatim' => 55,    // policy is 1 req/sec
            'osrm' => 60,
            'wikimedia' => 200,
            'google_places' => 300,
            'frankfurter' => 60,
        ],
    ],

    /* Canonical place data (spec s9.2, s11). */
    'place_data' => [
        'driver' => env('EXPERIENCE_PLACE_DATA_DRIVER', 'osm'),   // osm | google
        'osm' => [
            'overpass_url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
            'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
            'cache_seconds' => 86400,
            'geocode_cache_seconds' => 604800,
        ],
        'google' => [
            'api_key' => env('GOOGLE_PLACES_API_KEY'),
            /* Google's terms restrict retention of most Places content, so this
               is request coalescing rather than a content cache. */
            'cache_seconds' => 900,
        ],
    ],

    /* Editorial content and imagery (spec s7). */
    'enrichment' => [
        'driver' => env('EXPERIENCE_ENRICHMENT_DRIVER', 'wikimedia'),
        'cache_seconds' => 604800,
    ],

    'currency' => [
        'driver' => env('EXPERIENCE_CURRENCY_DRIVER', 'frankfurter'),
        'base_url' => env('FRANKFURTER_URL', 'https://api.frankfurter.dev'),
        'cache_seconds' => 21600,
    ],

    'routing' => [
        'driver' => env('EXPERIENCE_ROUTING_DRIVER', 'osrm'),
        'cache_seconds' => 86400,
        /* Past this, nobody is walking, so we stop asking a foot router. */
        'max_route_metres' => 5000,
        'osrm' => [
            'base_url' => env('OSRM_URL', 'https://router.project-osrm.org'),
        ],
        'walking_kmh' => 4.6,
        'walking_detour_factor' => 1.25,   // straight line -> street network
        'transit_threshold_km' => 2.0,
        'transit_kmh' => 22.0,
        'transit_access_minutes' => 9,     // walk to stop, wait, walk from stop
        'max_walk_minutes' => [
            'low' => 15,
            'medium' => 25,
            'high' => 45,
        ],
    ],

    'itinerary' => [
        'engine_version' => 'itinerary-v1.2',
        'default_day_start' => '09:00',
        'default_day_end' => '21:00',
        'anchor_safety_buffer_minutes' => 15,
        'min_gap_between_items_minutes' => 10,
        'meal_windows' => [
            'lunch' => ['12:00', '14:30'],
            'dinner' => ['18:30', '21:00'],
        ],
        'max_items_per_day' => [
            'slow' => 2,
            'moderate' => 3,
            'fast' => 5,
        ],
        'consecutive_high_energy_limit' => 2,
        'beam_width' => 4,
    ],

    'weather' => [
        'driver' => env('EXPERIENCE_WEATHER_DRIVER', 'seeded'),
        'cache_seconds' => 900,
        'poor_conditions' => ['rain', 'heavy_rain', 'snow', 'storm', 'sleet'],
    ],

    'assistant' => [
        'driver' => env('EXPERIENCE_ASSISTANT_DRIVER', 'rules'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'max_tool_rounds' => 4,
        /* Patterns the grounding guard refuses to let an LLM state unsourced. */
        'guarded_fact_patterns' => [
            '/(?<![\w])(?:£|\$|€)\s?\d[\d,.]*/u',            // prices
            '/\b\d{1,2}[:.]\d{2}\s?(?:am|pm)?\b/i',          // clock times
            '/\b\d{1,3}\s?(?:min|mins|minutes|hours?)\b/i',  // durations
        ],
    ],

    'providers' => [
        'ticket' => env('EXPERIENCE_TICKET_PROVIDER', 'deeplink'),
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'open_seconds' => 120,
        ],
        'deeplink' => [
            'affiliate_id' => env('DEEPLINK_AFFILIATE_ID', 'demo-partner'),
            'base_url' => env('DEEPLINK_BASE_URL', 'https://partner.example.com/checkout'),
        ],
        'viator' => ['api_key' => env('VIATOR_API_KEY')],
        'getyourguide' => ['api_key' => env('GETYOURGUIDE_API_KEY')],
    ],

    /* Canonical place resolution thresholds (spec s11). */
    'resolution' => [
        'match_radius_metres' => 250,
        'close_radius_metres' => 120,
        'name_similarity_match' => 0.60,
        'name_similarity_strict' => 0.80,
        'name_similarity_review' => 0.45,
    ],

    'privacy' => [
        'store_location_history' => false,
        'context_snapshot_retention_days' => 30,
    ],
];
