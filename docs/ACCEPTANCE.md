# V1 acceptance criteria (spec §35)

Every criterion below is covered by a test. Run them with `cd api && php artisan test`.

| # | Criterion | Where it is proved |
|---|---|---|
| 51 | A guest can browse without creating an account | `Feature/GuestBrowsingTest::test_a_guest_gets_a_session_token_on_first_contact_and_can_browse`, `::test_a_guest_can_get_recommendations_without_an_account` |
| 52 | A traveller can create a profile with interests, budget and pace | `Feature/TravellerAndJourneyTest::test_a_traveller_profile_stores_interests_budget_and_pace` |
| 53 | A journey can store reason, companions, mission and anchors | `Feature/TravellerAndJourneyTest::test_a_journey_stores_reason_companions_mission_and_anchors` |
| 54 | The engine produces an explainable score from traveller + journey + live context | `Unit/ExperienceScoreTest::test_a_score_is_explainable_and_bounded`, `::test_journey_purpose_can_outrank_raw_interest_fit` |
| 55 | A time-boxed request returns only options that fit, travel time included | `Feature/TimeBoxedDiscoveryTest` (all three cases) |
| 56 | An itinerary never overlaps a fixed anchor | `Feature/ItineraryTest::test_the_planner_builds_around_anchors_and_never_through_them`, `::test_manually_adding_an_item_over_an_anchor_is_refused` |
| 57 | An experience page distinguishes dynamic facts from descriptive content | `Feature/ExperienceDetailTest::test_dynamic_facts_are_separated_from_description_and_carry_provenance` |
| 58 | At least one provider supplies bookable offers or a compliant redirect | `Feature/BookingFlowTest::test_a_native_booking_walks_the_state_machine_to_confirmed`, `::test_a_redirect_booking_hands_the_traveller_to_the_merchant` |
| 59 | Provider failure does not break unrelated discovery content | `Feature/ProviderResilienceTest` (all four cases) |
| 60 | The app can suggest weather-appropriate alternatives | `Feature/WeatherAwareTest::test_rain_moves_the_indoor_option_above_the_outdoor_one` |
| 61 | The AI guide cannot invent live price, inventory or opening hours | `Unit/GroundingGuardTest` (five cases), `Feature/AssistantTest::test_the_guide_answers_from_tool_results_and_says_which_tools_it_used` |
| 62 | A user can save and later revisit experiences | `Feature/GuestBrowsingTest::test_a_guest_can_save_an_experience_and_read_it_back`, `::test_registering_adopts_what_the_guest_already_did` |
| 63 | Analytics record impressions, saves, bookings and completions | `Feature/AnalyticsAndPassportTest` (six cases) |
| 64 | Admin can correct provider mappings and inspect failed syncs | `Feature/AdminOperationsTest::test_a_provider_mapping_can_be_pointed_at_the_right_canonical_record`, `::test_failed_syncs_can_be_inspected_and_resolved` |
| 65 | Location use is optional and stored context can be deleted | `Feature/PrivacyTest` (six cases) |

## Beyond the checklist

Behaviour the spec calls for that is also pinned by tests:

| Spec | Behaviour | Test |
|---|---|---|
| §3.3 | Journey Mission becomes structured soft goals, and a refusal lowers a goal rather than raising it | `Unit/MissionInterpreterTest` |
| §6.1 | Component weights must sum to 1.0 | `Unit/ExperienceScoreTest::test_weights_are_configured_to_sum_to_one` |
| §6.1 | A component with no data is neutral, not zero | `Unit/ExperienceScoreTest::test_a_component_without_data_is_neutral_rather_than_zero` |
| §6.4 | Transparent signals instead of a "tourist trap" label | `Feature/ExperienceDetailTest::test_the_page_reports_transparent_signals_rather_than_a_tourist_trap_label` |
| §8.2 | Confirmed bookings are never rescheduled silently | `Unit/BookingStateMachineTest::test_confirmed_and_processing_bookings_are_locked_against_silent_rescheduling` |
| §8.3 | Replanning proposes; the current plan stands until accepted | `Feature/ReplanTest` (three cases) |
| §9.4 | Illegal booking transitions are refused; retries replay | `Unit/BookingStateMachineTest` (five cases) |
| §10 | Related experiences come from the Experience Graph | `Feature/ExperienceDetailTest::test_related_experiences_come_from_the_experience_graph` |
| §13.2 | Accessibility claims are attributed, never assumed | `Feature/ExperienceDetailTest::test_accessibility_claims_are_attributed_and_never_assumed` |
| §15.1 | The guide answers practical questions from sourced city essentials | `Feature/AssistantTest::test_practical_questions_are_answered_from_sourced_city_essentials` |
| §22 | Repeated failures open the circuit | `Feature/ProviderResilienceTest::test_repeated_failures_open_the_circuit_and_the_registry_stops_calling` |
| §23.1 | Public API tokens are scoped; admin endpoints are closed to travellers | `Feature/AdminOperationsTest::test_operations_endpoints_are_closed_to_ordinary_travellers` |
| §23.2 | Journal entries are private by default | `Feature/AnalyticsAndPassportTest::test_a_journal_entry_is_private_unless_the_traveller_chooses_otherwise` |
| §24 | Any past recommendation can be explained | `Feature/AdminOperationsTest::test_any_recommendation_can_be_explained_after_the_fact` |
| — | Anchor times without an offset are destination-local | `Feature/TravellerAndJourneyTest::test_an_anchor_time_without_an_offset_is_read_in_the_destinations_timezone` |
| — | Opening hours: unknown is never rendered as open | `Unit/OpeningHoursTest` (five cases) |
