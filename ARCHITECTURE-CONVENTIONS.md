# How We Build the Backend — Architecture Conventions

Follow this architecture **exactly** for every new feature/endpoint. It is a Laravel API
with a strict layered flow. Never put business logic in the controller. Never return a raw
model or a raw `response()->json()`. Every layer has one job.

## The layers and the flow

```
Route (api.php)
  → FormRequest      (validates input + authorization)
    → Controller     (thin orchestrator, try/catch only)
      → Service      (ALL business logic + DB work)
      → Resource     (shapes the model into the JSON output)
    → ResponseHelper (wraps everything in the standard envelope)
```

> **Target: a Laravel 11+ project.** Do the one-time setup in section 0 first (the shared
> plumbing every feature depends on), then follow sections 1–6 for each resource.

For any new resource `Foo`, you create these files:

- `app/Http/Requests/StoreFooRequest.php` and `UpdateFooRequest.php`
- `app/Http/Resources/FooResource.php`
- `app/Services/FooService.php`
- `app/Http/Controllers/FooController.php`
- a route group in `routes/api.php`
- (`app/Helpers/ResponseHelper.php` already exists — reuse it, never re-create it)

---

## 0. One-time setup (do this before any feature)

These are shared across every endpoint. Create them once.

### a. Spatie permissions + middleware aliases

Install and wire Spatie so routes can use `permission:...`:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

In `bootstrap/app.php`, register the middleware aliases and any global API middleware:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})
```

### b. Base Controller

The base controller is intentionally empty — controllers stay thin (no shared traits needed):

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
```

### c. `ResponseHelper` (create the file — see section 5 for the full body)

Drop `app/Helpers/ResponseHelper.php` in with the exact contents from section 5. It is the
single response envelope + exception mapper. Every controller depends on it.

### d. Global exception net in `bootstrap/app.php`

So that anything thrown *before* a controller catch (e.g. FormRequest validation) still comes
out in the standard shape, add the render hook. This is the safety net that pairs with the
per-method try/catch:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
        // Let Laravel's native {message, errors} renderer handle validation (forms read that shape).
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return null;
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return \App\Helpers\ResponseHelper::fromException($e);
        }
        return null; // non-API requests keep Laravel's default rendering
    });
})
```

### e. Auth

Reads/writes are behind `auth:sanctum` at the route group. Install Sanctum if the target
project doesn't have it (`php artisan install:api`).

---

## 1. Controller — thin, no business logic

The controller only: injects the service, calls it, wraps the result in a Resource, and
returns via `ResponseHelper`. **Every method is wrapped in try/catch and errors go through
`ResponseHelper::fromException($e)`.** Constructor injection for the service. Route–model
binding for single records (`Foo $foo`).

```php
<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Foo;
use App\Http\Requests\StoreFooRequest;
use App\Http\Requests\UpdateFooRequest;
use App\Http\Resources\FooResource;
use App\Services\FooService;

class FooController extends Controller
{
    private $fooService;

    public function __construct(FooService $fooService)
    {
        $this->fooService = $fooService;
    }

    public function index()
    {
        try {
            $foo = $this->fooService->index();
            $result = FooResource::collection($foo);
            return ResponseHelper::SuccessResponse($result, "Foo retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreFooRequest $request)
    {
        try {
            $foo = $this->fooService->store($request->validated());
            $result = FooResource::make($foo);
            return ResponseHelper::SuccessResponse($result, "Foo created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(Foo $foo)
    {
        try {
            $result = FooResource::make($foo->load('relation'));
            return ResponseHelper::SuccessResponse($result, "Foo retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateFooRequest $request, Foo $foo)
    {
        try {
            $foo = $this->fooService->update($request->validated(), $foo);
            $result = FooResource::make($foo);
            return ResponseHelper::SuccessResponse($result, "Foo updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(Foo $foo)
    {
        try {
            $this->fooService->destroy($foo);
            return ResponseHelper::SuccessResponse(null, "Foo deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
```

Rules:
- Pass **only** `$request->validated()` into the service — never `$request->all()`.
- Controller never touches the database directly. No `Foo::create(...)` in the controller.
- Always return through `ResponseHelper`, always inside try/catch, always
  `ResponseHelper::fromException($e)` in the catch.

---

## 2. Service — all business logic + persistence

The service is the only place that talks to models/DB and holds business logic. Methods take
plain arrays (already validated) and/or the bound model, and **return the model or data**, not
an HTTP response. Eager-load relations here.

```php
<?php

namespace App\Services;

use App\Models\Foo;

class FooService
{
    public function index()
    {
        return Foo::with('relation')->get();
    }

    public function store(array $data)
    {
        return Foo::create($data);
    }

    public function update(array $data, Foo $foo)
    {
        $foo->update($data);
        return $foo->refresh();
    }

    public function destroy(Foo $foo)
    {
        $foo->delete();
    }
}
```

Rules:
- Services return models/collections/arrays — **never** `response()->json()` and never a Resource.
- Any real logic (calculations, multi-step writes, transactions, external calls) lives here.
- Keep controllers dumb; if you're tempted to add an `if` in the controller, it belongs in the service.

---

## 3. FormRequest — validation + authorization

One per write action (`Store...`, `Update...`). Holds `rules()` and `authorize()`. This is the
**only** place validation rules live.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFooRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission is enforced at the route via middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'   => 'required|string|max:255',
            'status' => 'nullable|in:active,suspended',
            // 'user_id' => 'nullable|exists:users,id',
        ];
    }
}
```

Rules:
- `Update...` requests usually mirror `Store...` but relax `required` where partial updates apply.
- Authorization by permission is done at the route (see below), so `authorize()` typically returns `true`.

---

## 4. Resource — shapes the output JSON

Explicitly list every field. Never `return $this->resource->toArray()`. Nested relations use
another Resource with `whenLoaded`.

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FooResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"       => $this->id,
            "name"     => $this->name,
            "status"   => $this->status,
            "relation" => RelationResource::make($this->whenLoaded('relation')),
        ];
    }
}
```

Rules:
- Explicit field list — this is the API contract. No leaking of raw columns.
- Nested objects always go through their own Resource + `whenLoaded` (no N+1, no over-fetching).

---

## 5. ResponseHelper — the single response envelope

**This file already exists — reuse it, do not rewrite it.** Every response has the shape:

```json
{ "data": ..., "success": true|false, "message": "..." }
```

- `ResponseHelper::SuccessResponse($data, $message, $code)` for the happy path.
- `ResponseHelper::fromException($e)` in every catch — it maps exceptions to **meaningful HTTP
  status codes** and never leaks internals:
  - `ValidationException` → 422 (with field errors in `data`)
  - `AuthenticationException` → 401
  - `AuthorizationException` → 403
  - `ModelNotFoundException` → 404
  - any framework `HttpException` (`abort(409)` etc.) → its own status
  - anything else → 500, **logged** with full context/trace; raw message shown only when
    `app.debug` is on (safe generic message in production).

Never write `catch (\Throwable $e) { return response()->json(['error' => $e->getMessage()], 400); }`.
That old pattern logs nothing, returns 400 for everything, and leaks SQL/paths. Always
`ResponseHelper::fromException($e)`.

**Full file to paste** into `app/Helpers/ResponseHelper.php`:

```php
<?php

namespace App\Helpers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ResponseHelper
{
    public static function SuccessResponse($data, $message = 'Success', $code = 200)
    {
        return response()->json([
            'data'    => $data,
            'success' => true,
            'message' => $message,
        ], $code);
    }

    public static function FailureResponse($data = null, $message = 'Failed', $code = 400)
    {
        return response()->json([
            'data'    => $data,
            'success' => false,
            'message' => $message,
        ], $code);
    }

    /**
     * Turn ANY exception into the standard failure envelope with a meaningful HTTP status code.
     * 4xx (expected client errors) are NOT logged; only genuine 5xx server faults are logged.
     */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::FailureResponse(
                $e->errors(),
                $e->validator->errors()->first() ?: 'The given data was invalid.',
                422
            );
        }

        if ($e instanceof AuthenticationException) {
            return self::FailureResponse(null, 'Unauthenticated.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::FailureResponse(null, $e->getMessage() ?: 'This action is unauthorized.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::FailureResponse(null, 'The requested resource was not found.', 404);
        }

        // abort(404/403/409/…) and any framework HttpException keep their own status code.
        if ($e instanceof HttpExceptionInterface) {
            return self::FailureResponse(null, $e->getMessage() ?: 'Request failed.', $e->getStatusCode());
        }

        // Genuine server fault → log with everything needed to debug, then return a safe 500.
        Log::error('Unhandled API exception: ' . $e->getMessage(), [
            'exception' => $e::class,
            'where'     => $e->getFile() . ':' . $e->getLine(),
            'url'       => request()?->fullUrl(),
            'method'    => request()?->method(),
            'user_id'   => optional(request()?->user())->id,
            'trace'     => $e->getTraceAsString(),
        ]);

        return self::FailureResponse(
            null,
            config('app.debug') ? $e->getMessage() : 'Something went wrong on our end. Please try again.',
            500
        );
    }
}
```

> **Domain exceptions (optional).** In the source project `fromException` also maps a custom
> `WorkflowTransitionException` → 422. That is specific to that app's maintenance state machine.
> If the target project has its own domain exceptions that should map to a specific status,
> add a matching `if ($e instanceof YourException)` branch **before** the generic `HttpException`
> branch. Otherwise leave the mapper as-is above.

---

## 6. Routes — grouped, controller-bound, permission-gated

Group by prefix, bind the controller once, gate each route with a permission middleware.

```php
Route::middleware('auth:sanctum')->prefix('Foo')->controller(FooController::class)->group(function () {
    Route::get('/',        'index')  ->middleware('permission:foos.view');
    Route::get('/{foo}',   'show')   ->middleware('permission:foos.view');
    Route::post('/',       'store')  ->middleware('permission:foos.manage');
    Route::post('/{foo}',  'update') ->middleware('permission:foos.manage'); // note: POST for update
    Route::delete('/{foo}','destroy')->middleware('permission:foos.manage');
});
```

Rules:
- `.view` permission for reads, `.manage` for writes (Spatie permissions).
- Update is a `POST /{foo}` in this codebase (not PUT/PATCH) — keep it consistent.
- Route-model binding (`{foo}` → `Foo $foo`) so the controller receives the model directly.

---

## Checklist for every new endpoint

1. `StoreFooRequest` / `UpdateFooRequest` — validation rules.
2. `FooResource` — explicit output shape.
3. `FooService` — all logic + DB.
4. `FooController` — thin, try/catch, Resource + `ResponseHelper`.
5. Route group in `api.php` — prefixed, permission-gated.
6. Business logic **only** in the service. Response shaping **only** in Resource/ResponseHelper.
7. Never return a raw model. Never skip try/catch. Never use `$request->all()`.
