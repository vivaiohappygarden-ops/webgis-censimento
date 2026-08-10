<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'organization' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('email', $data['email'])
            ->where('is_active', true);

        if (! empty($data['organization'])) {
            $query->whereIn(
                'tenant_id',
                Organization::where('slug', $data['organization'])->pluck('id')
            );
        }

        $candidates = $query->get()->filter(
            fn (User $u) => $u->password && Hash::check($data['password'], $u->password)
        );

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages(['email' => 'Credenziali non valide.']);
        }

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages([
                'organization' => 'Questa email è presente in più organizzazioni: specifica lo slug nel campo organization.',
            ]);
        }

        /** @var User $user */
        $user = $candidates->first();
        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($data['device_name'] ?? 'api');

        Auth::setUser($user);
        Audit::log('auth.login', $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): Response
    {
        Audit::log('auth.logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function userPayload(User $user): array
    {
        $organization = Organization::find($user->tenant_id);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'locale' => $user->locale,
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ] : null,
        ];
    }
}
