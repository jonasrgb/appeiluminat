<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyOrderController extends Controller
{
    public function getOrderStatus(Request $request, ?string $store = null)
    {
        // 1) Validare input business
        $request->validate([
            'telefon'    => 'required|string',
            'id_comanda' => 'required|string',
        ]);

        
        $storeKey = $store
            ?? $request->header('X-Store')
            ?? $request->input('nume');

        if (!$storeKey) {
            return response()->json(['error' => 'Lipsește identificatorul magazinului (store).'], 400);
        }

        $shopCfg = config("shopify_stores.$storeKey");
        if (!$shopCfg) {
            return response()->json(['error' => "Magazin necunoscut: {$storeKey}"], 404);
        }

        // 3) Construcție URL + token din config/env (sigur)
        $shopifyGraphQLUrl   = "https://{$shopCfg['shop']}/admin/api/{$shopCfg['version']}/graphql.json";
        $shopifyAccessToken  = env($shopCfg['token_env']);

        if (empty($shopifyAccessToken)) {
            return response()->json(['error' => "Lipsește tokenul pentru magazinul {$storeKey}. Verifică .env"], 500);
        }

        // 4) Context de log ca să evidențiezi sursa
        Log::withContext([
            'store'  => $storeKey,
            'shop'   => $shopCfg['shop'],
            'origin' => $request->headers->get('Origin'),
        ]);

        $orderNames = [
            "name:{$request->id_comanda}",
            "name:" . ltrim($request->id_comanda, '#'),
        ];

        foreach ($orderNames as $orderName) {
            $graphqlQuery = [
                'query' => '
                    query($id_comanda: String!) {
                        orders(first: 1, query: $id_comanda) {
                            edges {
                                node {
                                    id
                                    name
                                    email
                                    phone
                                    displayFinancialStatus
                                    displayFulfillmentStatus
                                    createdAt
                                    tags
                                }
                            }
                        }
                    }',
                'variables' => ['id_comanda' => $orderName],
            ];

            // Poți păstra debug doar în local
            if (config('app.debug')) {
                Log::debug('GraphQL Request Sent', ['query' => $graphqlQuery]);
            }

            try {
                $response = Http::withHeaders([
                    'Content-Type'             => 'application/json',
                    'X-Shopify-Access-Token'   => $shopifyAccessToken,
                ])->post($shopifyGraphQLUrl, $graphqlQuery);

                // Rate Limit (header Shopify)
                $apiCallLimit = $response->header('X-Shopify-Shop-Api-Call-Limit');
                if ($apiCallLimit) {
                    [$used, $limit] = explode('/', $apiCallLimit);
                    if ((int)$used >= (int)$limit) {
                        Log::warning("Shopify API Rate Limit Reached: $apiCallLimit");
                        return response()->json([
                            'error' => 'Prea multe cereri. Vă rugăm să așteptați puțin și să încercați din nou.'
                        ], 429);
                    }
                }

                $shopifyData = $response->json();

                if (config('app.debug')) {
                    Log::debug('Shopify API Response', ['response' => $shopifyData]);
                }

                if (isset($shopifyData['errors'])) {
                    Log::error("Shopify GraphQL Error", ['errors' => $shopifyData['errors']]);
                    continue;
                }

                if (!isset($shopifyData['data']['orders']['edges']) ||
                    count($shopifyData['data']['orders']['edges']) === 0) {
                    continue;
                }

                $order = $shopifyData['data']['orders']['edges'][0]['node'];

                if (
                    (isset($order['phone']) && str_ends_with($order['phone'], $request->telefon)) &&
                    strtolower(ltrim($order['name'], '#')) === strtolower(ltrim($request->id_comanda, '#'))
                ) {
                    $tags    = isset($order['tags']) && is_array($order['tags']) ? $order['tags'] : [];
                    $isCOD   = in_array('releasit_cod_form', $tags) ? "Plata la livrare" : "Alte metode de plată";

                    $orderStatusTranslation = [
                        "UNFULFILLED"         => "Neprocesată",
                        "PARTIALLY_FULFILLED" => "Parțial procesată",
                        "FULFILLED"           => "Procesată",
                        "ON_HOLD"             => "În așteptare",
                        "CANCELED"            => "Anulată",
                    ];
                    $orderStatus = $orderStatusTranslation[$order['displayFulfillmentStatus']]
                        ?? $order['displayFulfillmentStatus'];

                    $paymentStatusTranslation = [
                        "PENDING"             => "În așteptare",
                        "AUTHORIZED"          => "Autorizată",
                        "PARTIALLY_PAID"      => "Parțial plătită",
                        "PAID"                => "Plătită",
                        "PARTIALLY_REFUNDED"  => "Parțial rambursată",
                        "REFUNDED"            => "Rambursată",
                        "VOIDED"              => "Anulată",
                    ];
                    $paymentStatus = $paymentStatusTranslation[$order['displayFinancialStatus']]
                        ?? $order['displayFinancialStatus'];

                    return response()->json([
                        'store'          => $storeKey, // ← evidențiem sursa
                        'order_id'       => $order['name'],
                        'order_status'   => $orderStatus,
                        'payment_status' => $paymentStatus,
                        'payment_method' => $isCOD,
                        'created_at'     => $order['createdAt'],
                    ], 200);
                }

            } catch (\Exception $e) {
                Log::error("Shopify API Error", ['message' => $e->getMessage()]);
                return response()->json([
                    'error' => 'A apărut o problemă la interogarea comenzii. Încercați din nou mai târziu.'
                ], 500);
            }
        }

        return response()->json([
            'store' => $storeKey,
            'error' => 'Comanda nu a fost găsită. Verificați telefonul și numele comenzii.'
        ], 404);
    }
}
