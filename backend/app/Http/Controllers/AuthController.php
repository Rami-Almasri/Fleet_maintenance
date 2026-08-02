<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserActivityService;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly UserActivityService $activity) {}

    /** Every user account (admin-only) — name, email, status and roles/permissions. */
    public function index()
    {
        return response()->json([
            "success" => true,
            "msg" => "OK",
            "data" => UserResource::collection(User::with('roles')->orderBy('name')->get()),
        ]);
    }

    public function signup(Request $request)
    {
        try {
            $validatedData = Validator::make($request->all(), [
                'name' => 'required|regex:/^[a-zA-Z\s]+$/',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|min:6',
                'status' => 'nullable|in:active,suspended',
                // Admin-only endpoint: the creating admin picks the role. Must be a
                // real Spatie role; defaults to read-only `viewer` when omitted.
                'role' => 'nullable|exists:roles,name',
            ], [
                'name.regex' => 'The name must not contain numbers or special characters.',
                'email.email' => 'The email must be a valid email address.',
            ]);

            if ($validatedData->fails()) {
                return response()->json([
                    "msg" => $validatedData->errors(),
                    "success" => false,
                    'data' => []
                ], 422);
            }

            // Role ceiling: the two all-powerful roles (`super-admin` / `admin`) may be granted
            // ONLY by a super-admin. An `admin` holds `users.manage` (so it can reach this endpoint)
            // but NOT the Gate::before bypass, so without this guard an admin could mint a
            // super-admin — an account more privileged than itself (vertical escalation). Every
            // other role stays assignable by any `users.manage` holder, exactly as before.
            $requestedRole = $request->role ?? 'viewer';
            if (in_array($requestedRole, ['super-admin', 'admin'], true)
                && ! $request->user()?->hasRole('super-admin')) {
                return response()->json([
                    "success" => false,
                    "msg" => "You are not allowed to grant the '{$requestedRole}' role.",
                    "data" => []
                ], 403);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => $request->status ?? 'active',
                // Audit trail: who minted this account (null for bootstrap accounts).
                'created_by' => $request->user()?->id,
            ]);

            // An admin created this account and may hand it a role directly;
            // absent an explicit choice it starts read-only (`viewer`).
            $user->assignRole($requestedRole);

            return response()->json([
                "success" => true,
                "msg" => "User created successfully",
                "data" => ["user" => UserResource::make($user)]
            ], 201);
        } catch (Exception $e) {
            // A failure is a 500, not a silent HTTP 200 — and the raw exception detail is logged,
            // not leaked to the caller.
            report($e);
            return response()->json([
                "success" => false,
                "msg" => "Could not create the user.",
                "data" => ["user" => null]
            ], 500);
        }
    }

    /** Assignable Spatie roles, for the admin create/edit form's role picker. */
    public function roles(Request $request)
    {
        // A non-super-admin (a plain `admin`) may not grant the two all-powerful
        // roles, so don't even offer them in that admin's picker.
        $roles = \Spatie\Permission\Models\Role::orderBy('name')->pluck('name');
        if (! $request->user()?->hasRole('super-admin')) {
            $roles = $roles->reject(fn ($r) => in_array($r, ['super-admin', 'admin'], true))->values();
        }

        return response()->json([
            "success" => true,
            "msg" => "OK",
            "data" => $roles,
        ]);
    }

    /** Admin-only: edit an existing account's name, email, status, role and (optionally) password. */
    public function update(Request $request, User $user)
    {
        try {
            $validatedData = Validator::make($request->all(), [
                'name' => 'required|regex:/^[a-zA-Z\s]+$/',
                'email' => 'required|email|unique:users,email,' . $user->id,
                // Password is optional on edit — only rotated when a new one is supplied.
                'password' => 'nullable|min:6',
                'status' => 'nullable|in:active,suspended',
                'role' => 'nullable|exists:roles,name',
            ], [
                'name.regex' => 'The name must not contain numbers or special characters.',
                'email.email' => 'The email must be a valid email address.',
            ]);

            if ($validatedData->fails()) {
                return response()->json([
                    "msg" => $validatedData->errors(),
                    "success" => false,
                    'data' => []
                ], 422);
            }

            // Same role ceiling as signup: only a super-admin may grant (or, here, keep
            // touching) the all-powerful roles. Block both the target's current role and
            // the requested role so a plain admin can neither elevate an account to, nor
            // re-save, a super-admin/admin — preventing vertical escalation on edit.
            $requestedRole = $request->role;
            $isSuperAdmin = $request->user()?->hasRole('super-admin');
            $privileged = ['super-admin', 'admin'];
            if (! $isSuperAdmin
                && (in_array($requestedRole, $privileged, true) || $user->hasAnyRole($privileged))) {
                return response()->json([
                    "success" => false,
                    "msg" => "You are not allowed to modify an admin-level account.",
                    "data" => []
                ], 403);
            }

            $user->name = $request->name;
            $user->email = $request->email;
            $user->status = $request->status ?? $user->status;
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
                // Audit: stamp the password rotation for the security surface.
                $user->last_password_change_at = now();
            }
            $user->save();

            // A single role per account (this app assigns one). syncRoles replaces
            // whatever was there. Only touch roles when the caller sent one.
            if ($requestedRole !== null) {
                $roleChanged = ! $user->hasRole($requestedRole) || $user->getRoleNames()->count() !== 1;
                $user->syncRoles([$requestedRole]);
                // Audit: record genuine role changes (not idempotent re-saves).
                if ($roleChanged) {
                    $user->forceFill(['last_role_change_at' => now()])->saveQuietly();
                }
            }

            return response()->json([
                "success" => true,
                "msg" => "User updated successfully",
                "data" => ["user" => UserResource::make($user->fresh('roles'))]
            ]);
        } catch (Exception $e) {
            report($e);
            return response()->json([
                "success" => false,
                "msg" => "Could not update the user.",
                "data" => ["user" => null]
            ], 500);
        }
    }

    /** Admin-only: permanently delete an account. */
    public function destroy(Request $request, User $user)
    {
        // Never let an admin delete their own account out from under themselves.
        if ($request->user()?->id === $user->id) {
            return response()->json([
                "success" => false,
                "msg" => "You cannot delete your own account.",
                "data" => []
            ], 422);
        }

        // Deleting an admin-level account requires super-admin, mirroring the role ceiling.
        if (! $request->user()?->hasRole('super-admin') && $user->hasAnyRole(['super-admin', 'admin'])) {
            return response()->json([
                "success" => false,
                "msg" => "You are not allowed to delete an admin-level account.",
                "data" => []
            ], 403);
        }

        try {
            $user->delete();

            return response()->json([
                "success" => true,
                "msg" => "User deleted successfully",
                "data" => []
            ]);
        } catch (Exception $e) {
            report($e);
            return response()->json([
                "success" => false,
                "msg" => "Could not delete the user.",
                "data" => []
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validatedData = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:6',
        ]);

        if ($validatedData->fails()) {
            return response()->json([
                "msg" => $validatedData->errors(),
                "success" => false,
                'data' => []
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Security audit: track failed attempts against the account (when it exists).
            if ($user) {
                $this->activity->stampFailedLogin($user);
            }
            return response()->json([
                "success" => false,
                "msg" => 'Invalid credentials',
                "data" => []
            ], 400);
        }

        // A suspended account must not be able to authenticate. (Existing tokens
        // for a since-suspended user are also rejected by EnsureUserActive.)
        if ($user->status === 'suspended') {
            return response()->json([
                "success" => false,
                "msg" => 'This account has been suspended. Contact an administrator.',
                "data" => []
            ], 403);
        }

        $token = $user->createToken("login")->plainTextToken;

        // Workforce activity: stamp the login + reset the failed-attempt counter.
        $this->activity->stampLogin($user, $request);

        return response()->json([
            "success" => true,
            "msg" => 'Logged in successfully',
            "data" => ['user' => UserResource::make($user), "token" => $token]
        ]);
    }

    /**
     * The CURRENT account, re-read from the database — same shape as login's `user` payload.
     *
     * Why this exists: login snapshots roles + permissions into the browser's localStorage, and the
     * frontend gates whole surfaces on that snapshot (usePermissions → e.g. the Action Center's lanes).
     * A permission granted after a user last signed in therefore stayed invisible to them until they
     * logged out and back in. The app re-fetches this on boot so a role change lands on the next page
     * load instead of on the next sign-in.
     */
    public function me(Request $request)
    {
        return response()->json([
            "success" => true,
            "msg" => 'OK',
            "data" => ['user' => UserResource::make($request->user())],
        ]);
    }

    public function logout(Request $request)
    {
        // Workforce activity: stamp the logout before the token is revoked.
        $this->activity->stampLogout($request->user(), $request);

        // Null-safe: logging out with an already-missing/expired token is a no-op success,
        // not a 500. ($request->user() is null when no valid token is presented.)
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            "success" => true,
            "msg" => 'Logged out successfully',
        ]);
    }
}