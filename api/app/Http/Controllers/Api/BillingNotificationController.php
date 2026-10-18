<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Billing\Services\AppleNotificationMapper;
use App\Domains\Billing\Services\AppleNotificationVerifier;
use App\Domains\Billing\Services\GoogleNotificationMapper;
use App\Domains\Billing\Services\GooglePubSubVerifier;
use App\Domains\Billing\Services\SubscriptionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the stores tell us what they did (Subscription JEM §11 P3).
 *
 * Both endpoints are public, because both stores call them, and both answer
 * 200 to anything they have accepted — a store that gets an error retries,
 * and a store that retries forever against a payload we will never apply is
 * noise we have to page someone about. So "understood" and "acted on" are
 * different answers: a notification for a transaction no account has claimed
 * is recorded and acknowledged, and applied when the device posts its receipt
 * and tells us whose month it is.
 *
 * What is never 200 is an unverified body. That path is the difference
 * between a billing system and a free subscription for anyone who can POST.
 */
class BillingNotificationController extends ApiController
{
    public function __construct(
        private readonly SubscriptionWriter $writer,
        private readonly AppleNotificationVerifier $appleVerifier,
        private readonly AppleNotificationMapper $appleMapper,
        private readonly GooglePubSubVerifier $googleVerifier,
        private readonly GoogleNotificationMapper $googleMapper,
    ) {}

    public function apple(Request $request): JsonResponse
    {
        $signed = (string) $request->input('signedPayload', '');

        if ($signed === '') {
            return response()->json(['code' => 'malformed'], Response::HTTP_BAD_REQUEST);
        }

        $claims = $this->appleVerifier->verify($signed);

        if ($claims === null) {
            return response()->json(['code' => 'unverified'], Response::HTTP_UNAUTHORIZED);
        }

        $notification = $this->appleMapper->map($claims);

        if ($notification === null) {
            /* A real notification of a type that changes nothing here. Taking
               it is what stops Apple resending it. */
            return response()->json(['code' => 'ignored']);
        }

        $this->writer->apply($notification);

        return response()->json(['code' => 'applied']);
    }

    public function google(Request $request): JsonResponse
    {
        if (! $this->googleVerifier->verify($request)) {
            return response()->json(['code' => 'unverified'], Response::HTTP_UNAUTHORIZED);
        }

        $encoded = (string) $request->input('message.data', '');
        $decoded = $encoded === '' ? null : base64_decode($encoded, true);
        $payload = $decoded === false || $decoded === null ? null : json_decode($decoded, true);

        if (! is_array($payload)) {
            return response()->json(['code' => 'malformed'], Response::HTTP_BAD_REQUEST);
        }

        $notification = $this->googleMapper->map($payload);

        if ($notification === null) {
            return response()->json(['code' => 'ignored']);
        }

        $this->writer->apply($notification);

        return response()->json(['code' => 'applied']);
    }
}
