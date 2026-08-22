# Legacy User-Permission System Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the dead legacy user-permission pivot system (`user_enterprises`/`user_applications` tables and the `User` relations backed by them), after first migrating the live call sites that still (silently, incorrectly) depend on those relations to the real hierarchical system (`user_enterprise_access`/`user_application_access`).

**Architecture:** SENTINEL 3.0 has two parallel user-permission systems. The hierarchical one (`UserEnterpriseAccess`/`UserApplicationAccess` models, tables `user_enterprise_access`/`user_application_access`) is the live system — written by `HierarchicalPermissionController` (the admin "Gestionar Permisos" modal) and read by `AuthController::getUserPermissions()` at login. The legacy one (`User::enterprises()`/`applications()`/`submodules()` relations, tables `user_enterprises`/`user_applications`/`user_submodule_permissions`) was superseded but never removed. Investigation found:
- `user_enterprises` and `user_applications` are empty (0 rows) and genuinely dead — nothing reachable from the frontend writes to them, but **5 backend call sites still read from them** (`routes/channels.php` ×5 closures, `SystemNotification::scopeForUser`, `NotificationController::isNotificationForUser`, `SfFaceTemplateController`), which means those features (private/presence broadcast channels, enterprise-audience notifications, face-template enterprise guard) currently **always deny/never-match for every user in production**, because the table they check is always empty. This is the same bug already diagnosed and fixed once in `SfFieldCheckController` (Aug 2026) — it just wasn't fixed everywhere.
- `user_submodule_permissions` is **not** dead — its schema was changed by migration `2025_12_05_000004` to `(user_id, submodule_id, permission_type_id, is_granted)` and it holds 10,098 live rows, read/written via the `UserSubmodulePermission` model by `HierarchicalPermissionController`, `PendingApprovalController`, and three Empaque controllers. Only the *model relations* pointing at it with the old schema (`User::submodules()`, `Submodule::users()`) are dead/broken (unknown-column errors if ever called) — the table itself must not be touched.

**Tech Stack:** Laravel 12 / PHP 8.2, MySQL (dev), PHPUnit (SQLite in-memory), React 19 frontend (Vite).

**Spec:** This plan's Goal/Architecture sections above are the spec — this cleanup was scoped through direct codebase investigation in this session, not a separate written spec doc.

## Global Constraints

- Never run `php artisan test` or `php artisan migrate` with `--env=` (real incident, see backend `CLAUDE.md`) — always plain `php artisan test`.
- After every code change: `php artisan route:clear && php artisan config:clear && php artisan view:clear`, then `php -l` on the changed file.
- Any migration must be checked with `php artisan migrate --pretend` before being treated as done.
- Frontend changes must pass `npm run build` (per `orkela-crops-front/CLAUDE.md`).
- `user_submodule_permissions` (table) and `UserSubmodulePermission` (model) are **out of scope for removal** — only the stale `User::submodules()` / `Submodule::users()` relations pointing at it are removed.
- Match the existing fix pattern already used in `SfFieldCheckController::authorizeEnterpriseAccess()` — a direct `UserEnterpriseAccess::where('user_id', ...)->where('enterprise_id', ...)->where('is_active', true)->exists()` query — rather than inventing a new abstraction, so the codebase stays consistent.

---

### Task 1: Migrate `routes/channels.php` broadcast authorization to the hierarchical tables

**Files:**
- Modify: `routes/channels.php:27-82`

**Interfaces:**
- Consumes: `App\Models\UserEnterpriseAccess` (`user_id`, `enterprise_id`, `is_active` columns), `App\Models\UserApplicationAccess` (`user_id`, `application_id`, `is_active` columns) — both already exist, used by `AuthController::getUserPermissions()`.

- [ ] **Step 1: Replace the 5 legacy-relation calls**

In `routes/channels.php`, replace each of these:

```php
        // Canal privado por empresa
        Broadcast::channel('enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            return $user->activeEnterprises()
                ->where('enterprises.id', $enterpriseId)
                ->exists();
        });

        // Canal privado por aplicación
        Broadcast::channel('application.{applicationId}', function ($user, $applicationId) {
            return $user->activeApplications()
                ->where('applications.id', $applicationId)
                ->exists();
        });

        // Canal de presencia por empresa (ver quién está conectado)
        Broadcast::channel('presence.enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            if ($user->activeEnterprises()->where('enterprises.id', $enterpriseId)->exists()) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            }

            return false;
        });

        // Canal de presencia por aplicación
        Broadcast::channel('presence.application.{applicationId}', function ($user, $applicationId) {
            if ($user->activeApplications()->where('applications.id', $applicationId)->exists()) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }

            return false;
        });
```

with:

```php
        // Canal privado por empresa
        Broadcast::channel('enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            return \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->exists();
        });

        // Canal privado por aplicación
        Broadcast::channel('application.{applicationId}', function ($user, $applicationId) {
            return \App\Models\UserApplicationAccess::where('user_id', $user->id)
                ->where('application_id', $applicationId)
                ->where('is_active', true)
                ->exists();
        });

        // Canal de presencia por empresa (ver quién está conectado)
        Broadcast::channel('presence.enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            $hasAccess = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->exists();

            if ($hasAccess) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            }

            return false;
        });

        // Canal de presencia por aplicación
        Broadcast::channel('presence.application.{applicationId}', function ($user, $applicationId) {
            $hasAccess = \App\Models\UserApplicationAccess::where('user_id', $user->id)
                ->where('application_id', $applicationId)
                ->where('is_active', true)
                ->exists();

            if ($hasAccess) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }

            return false;
        });
```

And the CRM channel:

```php
        // Canal CRM por empresa (eventos transversales: asignación de vendedor, etc.)
        Broadcast::channel('crm.{empresaId}', function ($user, $empresaId) {
            return $user->activeEnterprises()
                ->where('enterprises.id', $empresaId)
                ->exists();
        });
```

becomes:

```php
        // Canal CRM por empresa (eventos transversales: asignación de vendedor, etc.)
        Broadcast::channel('crm.{empresaId}', function ($user, $empresaId) {
            return \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('enterprise_id', $empresaId)
                ->where('is_active', true)
                ->exists();
        });
```

- [ ] **Step 2: Lint**

Run: `php -l routes/channels.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add routes/channels.php
git commit -m "fix: broadcast channels use hierarchical UserEnterpriseAccess/UserApplicationAccess instead of empty legacy pivot"
```

---

### Task 2: Migrate `SystemNotification::scopeForUser` enterprise-audience filter

**Files:**
- Modify: `app/Models/SystemNotification.php:116`

- [ ] **Step 1: Replace the legacy pluck**

```php
            // De sus empresas
            $enterpriseIds = $user->activeEnterprises()->pluck('enterprises.id');
```

becomes:

```php
            // De sus empresas
            $enterpriseIds = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('enterprise_id');
```

- [ ] **Step 2: Lint**

Run: `php -l app/Models/SystemNotification.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Models/SystemNotification.php
git commit -m "fix: SystemNotification::scopeForUser reads enterprise ids from UserEnterpriseAccess"
```

---

### Task 3: Migrate `NotificationController::isNotificationForUser` enterprise-audience check

**Files:**
- Modify: `app/Http/Controllers/Api/NotificationController.php:186-188`

- [ ] **Step 1: Replace the legacy exists() check**

```php
            case SystemNotification::AUDIENCE_ENTERPRISE:
                // Verificar si el usuario pertenece a la empresa
                return $user->enterprises()
                    ->where('enterprise_id', $notification->enterprise_id)
                    ->exists();
```

becomes:

```php
            case SystemNotification::AUDIENCE_ENTERPRISE:
                // Verificar si el usuario pertenece a la empresa
                return \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                    ->where('enterprise_id', $notification->enterprise_id)
                    ->where('is_active', true)
                    ->exists();
```

- [ ] **Step 2: Lint**

Run: `php -l app/Http/Controllers/Api/NotificationController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/NotificationController.php
git commit -m "fix: NotificationController checks enterprise audience via UserEnterpriseAccess"
```

---

### Task 4: Migrate `SfFaceTemplateController` enterprise guard

**Files:**
- Modify: `app/Http/Controllers/Api/SplendidFarms/Administration/SfFaceTemplateController.php:172`

- [ ] **Step 1: Replace the legacy guard**

```php
            $request->user()->activeEnterprises()->where('enterprises.id', $sfEmployee->enterprise_id)->exists(),
```

becomes:

```php
            \App\Models\UserEnterpriseAccess::where('user_id', $request->user()->id)
                ->where('enterprise_id', $sfEmployee->enterprise_id)
                ->where('is_active', true)
                ->exists(),
```

Read the surrounding `abort_unless(...)` call first to confirm this is a drop-in boolean-argument replacement (same shape as the fix already applied in `SfFieldCheckController::authorizeEnterpriseAccess()`).

- [ ] **Step 2: Lint**

Run: `php -l app/Http/Controllers/Api/SplendidFarms/Administration/SfFaceTemplateController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/SplendidFarms/Administration/SfFaceTemplateController.php
git commit -m "fix: SfFaceTemplateController enterprise guard uses UserEnterpriseAccess (same bug as SfFieldCheckController, Aug 2026)"
```

---

### Task 5: Fix `CreatesSfPersonalFixtures` test fixture to populate the real table

**Files:**
- Modify: `tests/Concerns/CreatesSfPersonalFixtures.php:37-46`
- Consumed by (do not edit, just be aware): `tests/Feature/SplendidFarms/Administration/SfFaceTemplateControllerTest.php`, `SfFieldCheckControllerTest.php`, `SfEmployeeControllerTest.php`, `tests/Feature/PendingApprovalControllerTest.php`, `tests/Feature/Jobs/VerifyFieldCheckJobTest.php`, `tests/Feature/Console/PurgeBiometricDataCommandTest.php`, `tests/Feature/Console/RequeueStaleFieldChecksCommandTest.php`

- [ ] **Step 1: Add the import**

At the top of `tests/Concerns/CreatesSfPersonalFixtures.php`, add:

```php
use App\Models\UserEnterpriseAccess;
```

- [ ] **Step 2: Replace the fixture's legacy attach() with a UserEnterpriseAccess row**

```php
        // Sin esta fila en el pivot user_enterprises, User::hasEnterpriseAccess()/
        // activeEnterprises() (usado por el guard de autorización de los
        // endpoints de Plan 2) nunca vería a este usuario como miembro de la
        // empresa que él mismo acaba de crear, y todos los tests "felices"
        // recibirían 403 en vez del código de estado que en realidad prueban.
        $user->enterprises()->attach($enterprise->id, [
            'role' => 'admin',
            'is_active' => true,
            'granted_at' => now(),
        ]);
```

becomes:

```php
        // Sin esta fila en user_enterprise_access, el guard real de los
        // endpoints de Plan 2 (UserEnterpriseAccess, ver
        // SfFieldCheckController::authorizeEnterpriseAccess()) nunca vería a
        // este usuario como miembro de la empresa que él mismo acaba de
        // crear, y todos los tests "felices" recibirían 403 en vez del
        // código de estado que en realidad prueban.
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
```

- [ ] **Step 3: Run the tests that consume this fixture**

Run: `php artisan test --filter=SfFaceTemplateControllerTest`
Run: `php artisan test --filter=SfFieldCheckControllerTest`
Run: `php artisan test --filter=SfEmployeeControllerTest`
Run: `php artisan test --filter=PendingApprovalControllerTest`
Expected: all PASS (same pass/fail results as before this change — the fixture change must not alter test outcomes now that Tasks 1-4 route production code through the same table).

- [ ] **Step 4: Lint**

Run: `php -l tests/Concerns/CreatesSfPersonalFixtures.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add tests/Concerns/CreatesSfPersonalFixtures.php
git commit -m "fix: test fixture grants enterprise access via UserEnterpriseAccess, matching production guard"
```

---

### Task 6: Fix `UserController::show()` to use the hierarchical tables

**Files:**
- Modify: `app/Http/Controllers/Api/UserController.php:99-107`

This mirrors the fix already applied to `UserController::index()` (see the docblock above it, lines 16-26) — `show()` was missed.

- [ ] **Step 1: Replace the eager-loaded legacy relations**

```php
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with(['enterprises', 'applications'])->findOrFail($id);

        return response()->json($user);
    }
```

becomes:

```php
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        $enterpriseIds = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('enterprise_id');

        $applicationsByEnterprise = UserApplicationAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('application:id,enterprise_id')
            ->get()
            ->filter(fn ($access) => $access->application)
            ->groupBy(fn ($access) => $access->application->enterprise_id)
            ->map(fn ($group) => $group->pluck('application_id')->toArray());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'role' => $user->role ?? 'user',
            'created_at' => $user->created_at,
            'permissions' => [
                'enterprises' => $enterpriseIds,
                'applications' => $applicationsByEnterprise,
            ],
        ]);
    }
```

(`UserEnterpriseAccess` and `UserApplicationAccess` are already imported at the top of this file — used by `index()`.)

- [ ] **Step 2: Lint**

Run: `php -l app/Http/Controllers/Api/UserController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/UserController.php
git commit -m "fix: UserController::show() reads permissions from hierarchical tables (same bug already fixed in index())"
```

---

### Task 7: Remove `assignEnterprises`/`assignApplications` (dead endpoints) and their routes

Confirmed unreachable: no frontend component destructures `assignEnterprises`/`assignApplications` from `useUsers()` (only `Users.jsx`, `Permissions.jsx`, `PermissionsNew.jsx`, `Dashboard.jsx` call `useUsers()`, and none of them pull those two functions out).

**Files:**
- Modify: `app/Http/Controllers/Api/UserController.php:149-186` (remove both methods — read the file first to get the exact current end-line of `assignApplications()`)
- Modify: `routes/api.php:78-79`
- Modify: `orkela-crops-front/src/services/api.js:647-662`
- Modify: `orkela-crops-front/src/hooks/admin/useUsers.js:110-151` and the `assignEnterprises, assignApplications,` entries in its returned object (~line 174-175)

- [ ] **Step 1: Remove the two routes**

In `routes/api.php`, delete:

```php
    Route::post('users/{user}/enterprises', [App\Http\Controllers\Api\UserController::class, 'assignEnterprises']);
    Route::post('users/{user}/enterprises/{enterprise}/applications', [App\Http\Controllers\Api\UserController::class, 'assignApplications']);
```

- [ ] **Step 2: Remove the two controller methods**

Delete `assignEnterprises()` and `assignApplications()` from `app/Http/Controllers/Api/UserController.php` in full (the `/** Assign enterprises to user */` and `/** Assign applications to user for specific enterprise */` blocks).

- [ ] **Step 3: Remove the two frontend api.js functions**

Delete from `orkela-crops-front/src/services/api.js`:

```javascript
  assignEnterprises: async (userId, enterpriseIds) => {
    return fetchAPI(`/users/${userId}/enterprises`, {
      method: "POST",
      body: JSON.stringify({ enterpriseIds }),
    });
  },

  assignApplications: async (userId, enterpriseId, applicationIds) => {
    return fetchAPI(
      `/users/${userId}/enterprises/${enterpriseId}/applications`,
      {
        method: "POST",
        body: JSON.stringify({ applicationIds }),
      },
    );
  },

```

- [ ] **Step 4: Remove the two frontend hook functions**

Delete from `orkela-crops-front/src/hooks/admin/useUsers.js`:

```javascript
  const assignEnterprises = async (userId, enterpriseIds) => {
    try {
      setLoading(true);
      setError(null);
      const updatedUser = await usersAPI.assignEnterprises(
        userId,
        enterpriseIds,
      );
      setUsers((prev) =>
        prev.map((user) => (user.id === userId ? updatedUser : user)),
      );
      return updatedUser;
    } catch (err) {
      setError(err.message);
      console.error("Error assigning enterprises:", err);
      throw err;
    } finally {
      setLoading(false);
    }
  };

  const assignApplications = async (userId, enterpriseId, applicationIds) => {
    try {
      setLoading(true);
      setError(null);
      const updatedUser = await usersAPI.assignApplications(
        userId,
        enterpriseId,
        applicationIds,
      );
      setUsers((prev) =>
        prev.map((user) => (user.id === userId ? updatedUser : user)),
      );
      return updatedUser;
    } catch (err) {
      setError(err.message);
      console.error("Error assigning applications:", err);
      throw err;
    } finally {
      setLoading(false);
    }
  };

```

And remove the `assignEnterprises,` / `assignApplications,` lines from the hook's returned object.

- [ ] **Step 5: Lint backend + frontend**

Run: `php -l app/Http/Controllers/Api/UserController.php`
Expected: `No syntax errors detected`

Run (in `orkela-crops-front/`): `npm run build`
Expected: builds with no errors, no reference errors for the removed functions.

- [ ] **Step 6: Commit (two commits, one per repo)**

```bash
git add app/Http/Controllers/Api/UserController.php routes/api.php
git commit -m "chore: remove unreachable assignEnterprises/assignApplications endpoints (legacy pivot, no frontend caller)"
```

```bash
cd ../orkela-crops-front
git add src/services/api.js src/hooks/admin/useUsers.js
git commit -m "chore: remove unreachable assignEnterprises/assignApplications (no component ever called them)"
```

---

### Task 8: Remove the dead/stale relations from `User` and `Submodule` models

**Files:**
- Modify: `app/Models/User.php:53-121` (after Task 7, nothing calls `enterprises()`, `applications()`, `activeEnterprises()`, `activeApplications()`, `hasEnterpriseAccess()`, `hasApplicationAccess()`, or `submodules()` — Tasks 1-6 moved every live caller to direct `UserEnterpriseAccess`/`UserApplicationAccess` queries)
- Modify: `app/Models/Submodule.php:38-46` (`users()` relation — confirmed unused anywhere)

- [ ] **Step 1: Confirm no remaining callers (safety check before deleting)**

Run:
```bash
grep -rn "\->enterprises(\|\->applications(\|\->submodules(\|hasEnterpriseAccess\|hasApplicationAccess\|activeEnterprises\|activeApplications" app routes database/seeders tests --include=*.php
```
Expected: no results outside of `app/Models/User.php` itself, `app/Models/Submodule.php` (`users()` definition), and `database/seeders/SentinelSeeder.php` (handled in Task 10 — run this task *after* Task 10, or if Task 10 hasn't landed yet, seeing `SentinelSeeder.php` hits here is expected and fine, not a blocker).

- [ ] **Step 2: Delete the relations from `User.php`**

Remove these methods entirely (lines 53-121, i.e. everything between the `password` cast closing and `employee()`):
`enterprises()`, `applications()`, `activeEnterprises()`, `activeApplications()`, `hasEnterpriseAccess()`, `hasApplicationAccess()`, `submodules()`.

Also remove the now-unused import:
```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
```
(`employee()` is the only remaining relation and it returns `HasOne`, not `BelongsToMany` — confirm this before deleting the import.)

- [ ] **Step 3: Delete `Submodule::users()`**

Remove from `app/Models/Submodule.php`:
```php
    /**
     * Usuarios que tienen permisos en este submódulo
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_submodule_permissions')
            ->withPivot(['can_view', 'can_create', 'can_edit', 'can_delete', 'is_active', 'granted_at', 'expires_at'])
            ->withTimestamps();
    }
```
Check whether `BelongsToMany` is still used elsewhere in `Submodule.php` before removing its import — leave the import if another relation in the same file still returns `BelongsToMany`.

- [ ] **Step 4: Lint**

Run: `php -l app/Models/User.php`
Run: `php -l app/Models/Submodule.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php app/Models/Submodule.php
git commit -m "chore: remove dead legacy relations (User::enterprises/applications/submodules, Submodule::users) — superseded by hierarchical tables, confirmed no callers"
```

---

### Task 9: Update the now-inaccurate comment in `SfFieldCheckController`

**Files:**
- Modify: `app/Http/Controllers/Api/SplendidFarms/Administration/SfFieldCheckController.php:295-312`

- [ ] **Step 1: Update the comment** (it currently says `User::hasEnterpriseAccess() existe pero...` — after Task 8 that method no longer exists)

```php
    /**
     * Verifica que el usuario autenticado pertenece a la empresa solicitada.
     *
     * User::hasEnterpriseAccess() existe pero recibe un slug (string), no un id
     * — este controller trabaja con enterprise_id (numérico, ya validado con
     * exists:enterprises,id) en las 3 rutas que expone.
     *
     * OJO: se consulta UserEnterpriseAccess (tabla user_enterprise_access), NO
     * User::activeEnterprises()/hasEnterpriseAccess() (pivot legacy
     * user_enterprises) — esas dos tablas son sistemas distintos. El modal de
     * permisos actual (HierarchicalPermissionController) y el login
     * (AuthController::getUserPermissions(), fuente real de qué empresas ve un
     * usuario) escriben/leen UserEnterpriseAccess; user_enterprises no lo llena
     * ninguna pantalla vigente del admin. Usar el pivot legacy aquí causaba 403
     * ("No tienes acceso a esta empresa") para cualquier usuario al que se le
     * hubiera dado acceso por el camino correcto (el modal de permisos) —
     * confirmado en campo agosto 2026 con un usuario recién dado de alta.
     */
```

becomes:

```php
    /**
     * Verifica que el usuario autenticado pertenece a la empresa solicitada.
     *
     * Se consulta UserEnterpriseAccess (tabla user_enterprise_access) — el
     * modal de permisos actual (HierarchicalPermissionController) y el login
     * (AuthController::getUserPermissions(), fuente real de qué empresas ve un
     * usuario) escriben/leen esta tabla. El pivot legacy `user_enterprises`
     * que causaba 403 incorrectos aquí (confirmado en campo agosto 2026) fue
     * eliminado del todo (ver docs/superpowers/plans/2026-08-21-legacy-user-permissions-cleanup.md) —
     * ese mismo bug existía también en routes/channels.php, SystemNotification,
     * NotificationController y SfFaceTemplateController, y ya está corregido
     * en los cinco lugares.
     */
```

- [ ] **Step 2: Lint**

Run: `php -l app/Http/Controllers/Api/SplendidFarms/Administration/SfFieldCheckController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/SplendidFarms/Administration/SfFieldCheckController.php
git commit -m "docs: update comment now that the legacy pivot bug is fixed everywhere, not just here"
```

---

### Task 10: Clean up `SentinelSeeder.php`'s legacy-table writes

`SentinelSeeder` is not called from `DatabaseSeeder::run()` or anywhere else (confirmed via repo-wide search) — it's an orphaned seeder. It currently writes to *both* the hierarchical tables and the legacy ones (the legacy block is explicitly labeled "SISTEMA DE PERMISOS ANTIGUO (mantener compatibilidad)"). After Task 8 removes `User::enterprises()`/`applications()`, the legacy block becomes a fatal error if this seeder is ever run manually.

**Files:**
- Modify: `database/seeders/SentinelSeeder.php:142-169`

- [ ] **Step 1: Delete the legacy block**

Remove:
```php
        // ===== SISTEMA DE PERMISOS ANTIGUO (mantener compatibilidad) =====

        // Asignar permisos al usuario demo en el sistema antiguo
        if (! $user->enterprises()->where('enterprises.id', $splendidfarms->id)->exists()) {
            $user->enterprises()->attach($splendidfarms->id, [
                'role' => 'admin',
                'is_active' => true,
                'granted_at' => now(),
            ]);
        }

        if (! $user->enterprises()->where('enterprises.id', $splendidbyporvenir->id)->exists()) {
            $user->enterprises()->attach($splendidbyporvenir->id, [
                'role' => 'admin',
                'is_active' => true,
                'granted_at' => now(),
            ]);
        }

        foreach ($applications as $application) {
            if (! $user->applications()->where('applications.id', $application->id)->exists()) {
                $user->applications()->attach($application->id, [
                    'permissions' => json_encode(['read', 'write', 'delete']),
                    'is_active' => true,
                    'granted_at' => now(),
                ]);
            }
        }

```
Leave the hierarchical block above it (the `$userEnterpriseAccess::updateOrCreate(...)` / `$userApplicationAccess::updateOrCreate(...)` calls) untouched.

- [ ] **Step 2: Lint**

Run: `php -l database/seeders/SentinelSeeder.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add database/seeders/SentinelSeeder.php
git commit -m "chore: remove SentinelSeeder's legacy-pivot writes (table is being dropped, seeder is orphaned but keep it runnable)"
```

---

### Task 11: Migration to drop `user_enterprises` and `user_applications`

Safe now: both tables are empty (0 rows), and after Tasks 1-10 nothing in the codebase references them (routes, controllers, models, seeders, or frontend).

**Files:**
- Create: `database/migrations/2026_08_21_130000_drop_legacy_user_enterprise_application_tables.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Elimina las tablas del sistema de permisos legado (User::enterprises()/
     * applications(), ya removidas de app/Models/User.php). Confirmado que
     * ambas tablas tienen 0 filas en desarrollo y que nada las referencia ya
     * en el código — ver docs/superpowers/plans/2026-08-21-legacy-user-permissions-cleanup.md.
     * El sistema real es user_enterprise_access/user_application_access.
     */
    public function up(): void
    {
        Schema::dropIfExists('user_enterprises');
        Schema::dropIfExists('user_applications');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('user_enterprises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('user');
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'enterprise_id']);
        });

        Schema::create('user_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->text('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'application_id']);
        });
    }
};
```

(Check the original `create_user_applications_table` migration for the exact original column set before finalizing `down()`, so a rollback recreates the same shape.)

- [ ] **Step 2: Verify with --pretend**

Run: `php artisan migrate --pretend`
Expected: shows `drop table \`user_enterprises\`` and `drop table \`user_applications\`` and nothing else unexpected.

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: migration runs successfully.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_21_130000_drop_legacy_user_enterprise_application_tables.php
git commit -m "chore: drop empty legacy tables user_enterprises/user_applications"
```

---

### Task 12: Full verification pass

**Files:** none (verification only)

- [ ] **Step 1: Clear caches**

Run: `php artisan route:clear && php artisan config:clear && php artisan view:clear`
Expected: all three succeed.

- [ ] **Step 2: Run the full backend test suite**

Run: `php artisan test`
(Plain — no `--env=`.)
Expected: all tests pass, in particular everything under `tests/Feature/SplendidFarms/Administration/` (Sf* controllers) and `tests/Feature/PendingApprovalControllerTest.php`.

- [ ] **Step 3: Frontend build + lint**

Run (in `orkela-crops-front/`):
```bash
npm run build
npm run lint
```
Expected: build succeeds; lint shows no *new* errors (the project has known false positives from missing `eslint-plugin-react`, per its `CLAUDE.md` — don't chase those).

- [ ] **Step 4: Manual smoke check of the fixed live bug (optional but recommended)**

If a dev server + Reverb are running, confirm a private `enterprise.{id}` channel subscription now succeeds for a user with a real `UserEnterpriseAccess` row (it would have silently failed before Task 1).

---

## Self-Review Notes

- Spec coverage: every finding from the investigation (empty tables, live-but-broken call sites, the `user_submodule_permissions` correction, frontend dead code, the orphaned seeder) has a task.
- `user_submodule_permissions`, `UserSubmodulePermission` model, and all its live consumers are untouched anywhere in this plan — confirmed by re-reading Task 8/10, neither touches that table.
- Task ordering matters: Tasks 1-6 (migrate live call sites) must land before Task 8 (delete the relations) and Task 11 (drop the tables) — otherwise deleting the relations turns silent-403s into hard 500s. Task 9 (comment update) and Task 10 (seeder cleanup) are order-independent but placed after Task 8 since they reference "already removed."
