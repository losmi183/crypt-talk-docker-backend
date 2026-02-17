<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use App\Services\JWTServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FcmTokenController extends Controller
{   
    private JWTServices $jWTServices;
    public function __construct(JWTServices $jWTServices) {
        $this->jWTServices = $jWTServices;
    }

    // Čuva token kada se korisnik uloguje ili osvezi stranicu
    public function store(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'platform' => 'required|in:web,android,ios',
        ]);

        $user = $this->jWTServices->getContent();

        try {
            FcmToken::updateOrCreate(
                [
                    'user_id' => $user['id'],
                    'token'   => $request->token,
                ],
                [
                    'platform' => $request->platform,
                ]
            );
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
        }

        return response()->json(['message' => 'Token saved']);
    }

    // Brise token kada se korisnik izloguje
    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        FcmToken::where('user_id', auth()->id())
            ->where('token', $request->token)
            ->delete();

        return response()->json(['message' => 'Token removed']);
    }
}