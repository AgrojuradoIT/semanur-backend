<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function canViewUsers(User $user): bool
    {
        $role = strtolower((string) $user->role);

        return in_array($role, ['admin', 'almacenista'], true);
    }

    public function login(ApiLoginRequest $request)
    {
        $credentials = $request->validated();

        $user = User::query()
            ->with('empleado')
            ->where('email', $credentials['email'])
            ->first();

        if (! $user
            || ! Hash::check($credentials['password'], $user->password)
            || ! $user->isOperationallyActive()) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => AuthenticatedUserResource::make($user)->resolve($request),
        ]);
    }

    public function logout(Request $request)
    {
        DB::transaction(function () use ($request): void {
            $authenticatedUser = $request->user();
            $accessToken = $authenticatedUser->currentAccessToken();
            $user = User::query()->findOrFail($authenticatedUser->getKey());
            $user->forceFill([
                'fcm_token' => null,
                'fcm_token_updated_at' => null,
            ])->save();
            $accessToken?->delete();
        });

        return response()->json(['message' => 'Sesion cerrada correctamente']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        $tokenName = $request->input('device_name', $currentToken?->name ?? 'flutter_app');
        $currentToken?->delete();

        return response()->json([
            'token' => $user->createToken($tokenName)->plainTextToken,
            'user' => AuthenticatedUserResource::make($user)->resolve($request),
        ]);
    }

    public function logoutAll(Request $request)
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->findOrFail($request->user()->getKey());
            $user->tokens()->delete();
            $user->forceFill([
                'fcm_token' => null,
                'fcm_token_updated_at' => null,
            ])->save();
        });

        return response()->json(['message' => 'Todas las sesiones fueron cerradas']);
    }

    public function user(Request $request)
    {
        return response()->json(
            AuthenticatedUserResource::make($request->user())->resolve($request)
        );
    }

    public function index(Request $request)
    {
        $authUser = $request->user();

        if (! $this->canViewUsers($authUser)) {
            return response()->json(['message' => 'No autorizado para consultar usuarios'], 403);
        }

        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'phone', 'license_number', 'cargo'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'license_number' => $user->license_number,
                'cargo' => $user->cargo,
            ]);

        return response()->json($users);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'license_number' => 'sometimes|nullable|string|max:50',
        ]);

        $user = $request->user();
        $user->fill($request->only(['name', 'phone', 'license_number']));
        $user->save();

        return response()->json(
            AuthenticatedUserResource::make($user)->resolve($request)
        );
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => ['present', 'nullable', 'string', 'max:4096'],
        ]);

        $user = User::query()->findOrFail($request->user()->getKey());
        $user->fcm_token = $request->input('fcm_token');
        $user->fcm_token_updated_at = $request->filled('fcm_token') ? now() : null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Token FCM registrado con éxito.',
        ]);
    }
}
